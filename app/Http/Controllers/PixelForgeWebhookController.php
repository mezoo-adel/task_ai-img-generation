<?php

namespace App\Http\Controllers;

use App\Services\PixelForge\ApplyGenerationSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Receives signed PixelForge events outside the browser session. Verifies
 * the HMAC signature and freshness on the untouched body before trusting
 * any JSON, then commits dedup, update, and charge in one transaction
 * before replying.
 */
class PixelForgeWebhookController extends Controller
{
    private const int SIGNATURE_TOLERANCE_SECONDS = 300;

    private const array GENERATION_EVENTS = [
        'generation.processing',
        'generation.succeeded',
        'generation.failed',
    ];

    public function __construct(private readonly ApplyGenerationSnapshot $updater) {}

    public function __invoke(Request $request): Response
    {
        $secret = config('services.pixelforge.webhook_secret');
        if (! is_string($secret) || trim($secret) === '') {
            return response()->noContent(503);
        }

        $raw = $request->getContent();
        $header = $request->header('Pixelforge-Signature');

        if (! is_string($header)
            || preg_match('/^t=(\d{1,11}),v1=([0-9a-f]{64})$/', $header, $matches) !== 1
            || abs(now()->getTimestamp() - (int) $matches[1]) > self::SIGNATURE_TOLERANCE_SECONDS
            || ! hash_equals(
                hash_hmac('sha256', $matches[1].'.'.$raw, $secret),
                $matches[2]
            )) {
            return response()->noContent(400);
        }

        $event = json_decode($raw, true);
        $eventId = is_array($event) ? ($event['id'] ?? null) : null;
        $type = is_array($event) ? ($event['type'] ?? null) : null;

        if (! is_string($eventId)
            || preg_match('/^evt_[a-z0-9_-]{1,100}$/i', $eventId) !== 1
            || ! is_string($type)) {
            return response()->noContent(422);
        }

        if ($type === 'ping') {
            return $this->recordEvent($eventId);
        }

        if (! in_array($type, self::GENERATION_EVENTS, true)) {
            return response()->noContent(422);
        }

        $object = $event['data']['object'] ?? null;
        $localId = is_array($object) ? data_get($object, 'metadata.local_generation_id') : null;

        // The event type must agree with the object it carries.
        if (! is_array($object)
            || ! is_string($localId)
            || ! Str::isUuid($localId)
            || Str::after($type, 'generation.') !== ($object['status'] ?? null)) {
            return response()->noContent(422);
        }

        try {
            $applied = $this->updater->apply($localId, $object, $eventId);
        } catch (Throwable $exception) {
            Log::error('pixelforge.webhook_commit_failed', [
                'event_id' => $eventId,
                'exception' => $exception::class,
            ]);

            return response()->noContent(500);
        }

        if (! $applied) {
            return response()->noContent(422);
        }

        return response()->noContent();
    }

    /**
     * Record a signed ping event without a generation lookup.
     */
    private function recordEvent(string $eventId): Response
    {
        try {
            DB::transaction(function () use ($eventId): void {
                DB::table('pixelforge_webhook_events')->insertOrIgnore([
                    'event_id' => $eventId,
                    'accepted_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            Log::error('pixelforge.webhook_commit_failed', [
                'event_id' => $eventId,
                'exception' => $exception::class,
            ]);

            return response()->noContent(500);
        }

        return response()->noContent();
    }
}

<?php

namespace App\Services\PixelForge;

use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Applies one PixelForge generation snapshot, from a create response, a
 * signed webhook, or a status poll, through a single database transaction.
 * Binds or verifies the provider ID, moves the local status only forward,
 * and writes the charge together with a final success. A signed event ID
 * is claimed in the same transaction before any state effect, so a
 * committed event always means the snapshot was applied and a replay is a
 * no-op.
 */
final class ApplyGenerationSnapshot
{
    /**
     * Bounded machine identifiers issued by the provider.
     */
    private const string PROVIDER_ID_PATTERN = '/^gen_[a-z0-9_-]{1,100}$/i';

    private const string EVENT_ID_PATTERN = '/^evt_[a-z0-9_-]{1,100}$/i';

    public function __construct(private readonly PixelForgePricing $pricing) {}

    /**
     * @param  array<string, mixed>  $snapshot  The provider generation object.
     * @param  string|null  $eventId  Provider event ID when from a webhook.
     * @return bool True when the event is safe to acknowledge (applied,
     *              duplicate, stale, or an unknown local generation), false
     *              when the snapshot structure or binding is invalid.
     */
    public function apply(string $generationId, array $snapshot, ?string $eventId = null): bool
    {
        if (! $this->validShape($generationId, $snapshot, $eventId)) {
            return false;
        }

        $providerId = (string) $snapshot['id'];

        /** @var bool */
        return DB::transaction(function () use ($generationId, $snapshot, $eventId, $providerId): bool {
            $generation = Generation::query()
                ->whereKey($generationId)
                ->lockForUpdate()
                ->first();

            // An unknown local ID is acknowledged without a write; the caller
            // can still rely on the provider retrying nothing.
            if ($generation === null) {
                return true;
            }

            // A row already bound to a different provider ID can never take
            // this snapshot, even once it has reached a final state.
            if ($generation->provider_id !== null && $generation->provider_id !== $providerId) {
                return false;
            }

            // Claim the event before any state effect. A replay of a
            // committed event is a no-op whatever else the body says.
            if ($eventId !== null
                && DB::table('pixelforge_webhook_events')->insertOrIgnore([
                    'event_id' => $eventId,
                    'accepted_at' => now(),
                ]) === 0) {
                return true;
            }

            return $this->applyTo($generation, $snapshot, $eventId !== null);
        });
    }

    /**
     * Structure checks that never require the database.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function validShape(string $generationId, array $snapshot, ?string $eventId): bool
    {
        $providerId = $snapshot['id'] ?? null;
        $status = $snapshot['status'] ?? null;
        $localId = data_get($snapshot, 'metadata.local_generation_id');

        return is_string($providerId)
            && preg_match(self::PROVIDER_ID_PATTERN, $providerId) === 1
            && is_string($status)
            && GenerationStatus::tryFrom($status) !== null
            && $localId === $generationId
            && ($eventId === null || preg_match(self::EVENT_ID_PATTERN, $eventId) === 1);
    }

    /**
     * Apply a validated snapshot to the locked local row. Only attributes
     * whose values actually change are written, so a stale or no-op
     * snapshot cannot trip a zero-rows update on MySQL.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function applyTo(Generation $generation, array $snapshot, bool $signed): bool
    {
        $was = $generation->status;
        $providerId = (string) $snapshot['id'];
        $target = GenerationStatus::from((string) $snapshot['status']);

        if ($was->isFinal()) {
            return true;
        }

        $charge = null;

        $generation->provider_id = $providerId;
        $generation->acceptance_uncertain = false;

        if ($generation->getOriginal('provider_id') === null) {
            // First binding: stamp acceptance, restart the poll schedule,
            // and clear any prior backoff or park.
            $acceptedAt = $this->parseTime($snapshot['created_at'] ?? null) ?? Carbon::now();
            $generation->accepted_at = $acceptedAt;
            $generation->recovery_step = 0;
            $generation->attention_reason = null;
            $generation->next_check_at = $acceptedAt->copy()->addMinute();
        } elseif ($signed && $generation->next_check_at === null && ! $target->isFinal()) {
            // A parked row is resumed by a valid signed non-final snapshot.
            $generation->attention_reason = null;
            $generation->recovery_step = 0;
            $generation->next_check_at = Carbon::now()->addMinute();
        }
        // Already-bound snapshots keep their recovery_step so a slow poll
        // cadence is not reset on every response.

        if ($target === GenerationStatus::Processing) {
            $generation->status = GenerationStatus::Processing;
        } elseif ($target === GenerationStatus::Succeeded) {
            $settlement = $this->settlement($snapshot);
            if ($settlement === null) {
                // A success that cannot yet be priced stays unfinished and
                // eligible for recovery; the event is still acknowledged.
                Log::warning('pixelforge.success_unpriced', [
                    'generation_id' => $generation->id,
                    'provider_id' => $providerId,
                ]);
                if ($signed
                    && ($generation->next_check_at === null || $generation->next_check_at->isFuture())) {
                    $generation->next_check_at = Carbon::now();
                    $generation->attention_reason = null;
                }
            } else {
                foreach ($settlement['attributes'] as $key => $value) {
                    $generation->{$key} = $value;
                }
                $charge = $settlement['charge'];
            }
        } elseif ($target === GenerationStatus::Failed) {
            $generation->status = GenerationStatus::Failed;
            $generation->failure_code = $this->errorCode($snapshot);
            $generation->completed_at = $this->parseTime($snapshot['completed_at'] ?? null) ?? Carbon::now();
            $generation->next_check_at = null;
            $generation->attention_reason = null;
        }
        // A queued snapshot binds or verifies the provider ID only; a stale
        // queued/processing snapshot on a processing row changes nothing.

        $dirty = $generation->getDirty();

        if ($dirty !== []
            && Generation::query()
                ->whereKey($generation->id)
                ->where('status', $was->value)
                ->update($dirty) === 0) {
            throw new RuntimeException('Generation status write was lost to a concurrent snapshot.');
        }

        if ($charge !== null) {
            CreditTransaction::create([
                'user_id' => $generation->user_id,
                'generation_id' => $generation->id,
                'kind' => 'charge',
                'amount_picos' => $charge,
            ]);
        }

        return true;
    }

    /**
     * The complete success record, or null when usage, rates, or the UTC
     * completion time are missing or cannot be priced exactly.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{attributes: array<string, mixed>, charge: int}|null
     */
    private function settlement(array $snapshot): ?array
    {
        $usage = $snapshot['usage'] ?? null;
        $rates = $snapshot['rates'] ?? null;
        if (! is_array($usage) || ! is_array($rates)) {
            return null;
        }

        $completedAt = $this->parseTime($snapshot['completed_at'] ?? null);
        if ($completedAt === null) {
            return null;
        }

        try {
            $settled = $this->pricing->settle($usage, $rates);
        } catch (InvalidArgumentException) {
            return null;
        }

        $expiresAt = $this->parseTime($snapshot['image_url_expires_at'] ?? null)
            ?? $completedAt->copy()->addHours(24);
        $imageUrl = $this->imageUrl($snapshot);

        // A success that still claims a live image must deliver a usable
        // URL; leave it eligible for recovery to finish. Once the window
        // has passed, the record settles without an image.
        if ($imageUrl === null && $expiresAt->isFuture()) {
            return null;
        }

        return [
            'attributes' => [
                'status' => GenerationStatus::Succeeded,
                'usage' => $usage,
                'rates' => $rates,
                'provider_cost_picos' => $settled['cost'],
                'completed_at' => $completedAt,
                'image_url' => $imageUrl,
                'image_expires_at' => $expiresAt,
                'next_check_at' => null,
                'attention_reason' => null,
            ],
            'charge' => $settled['charge'],
        ];
    }

    /**
     * An HTTP(S) image URL or null; anything else is dropped.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function imageUrl(array $snapshot): ?string
    {
        $url = $snapshot['image_url'] ?? null;
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return $scheme === 'https' || $scheme === 'http' ? $url : null;
    }

    /**
     * A safe machine-readable provider error code or null.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function errorCode(array $snapshot): ?string
    {
        $code = data_get($snapshot, 'error.code');

        return is_string($code) && preg_match('/^[a-z0-9_]{1,64}$/i', $code) === 1
            ? $code
            : null;
    }

    /**
     * A UTC timestamp from a strict ISO 8601 value with an explicit Z or
     * numeric offset; date-only, relative, and invalid calendar values are
     * rejected rather than normalized.
     */
    private function parseTime(mixed $value): ?Carbon
    {
        if (! is_string($value)
            || preg_match(
                '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/',
                $value,
                $matches
            ) !== 1
            || ! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
            || (int) $matches[4] > 23
            || (int) $matches[5] > 59
            || (int) $matches[6] > 59) {
            return null;
        }

        return (new Carbon($value))->setTimezone('UTC');
    }
}

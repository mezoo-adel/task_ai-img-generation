<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\PixelForge\ApplyGenerationSnapshot;
use App\Services\PixelForge\PixelForgeClient;
use App\Services\PixelForge\PixelForgeConnectionException;
use App\Services\PixelForge\PixelForgeCooldown;
use App\Services\PixelForge\PixelForgePausedException;
use App\Services\PixelForge\SubmitGenerationAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Periodic recovery sweep: takes up to five unfinished generations whose
 * check deadline has passed, replays the submission for rows without a
 * provider ID through the shared attempt, and polls GET /v1/generations
 * for rows with one.
 */
class PollOverdueGenerations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 45;

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('pixelforge-sweep'))->releaseAfter(30)->expireAfter(60),
        ];
    }

    public function handle(
        PixelForgeClient $client,
        SubmitGenerationAttempt $submitter,
        ApplyGenerationSnapshot $updater,
        PixelForgeCooldown $cooldown
    ): void {
        if ($cooldown->authPaused() || $cooldown->inCooldown()) {
            return;
        }

        $due = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued->value, GenerationStatus::Processing->value])
            ->whereNotNull('next_check_at')
            ->where('next_check_at', '<=', now())
            ->orderBy('next_check_at')
            ->orderBy('id')
            ->limit(5)
            ->get();

        foreach ($due as $generation) {
            if ($generation->provider_id === null) {
                if (! $submitter->run($generation->id)) {
                    return;
                }

                continue;
            }

            if (! $this->poll($generation->id, $client, $updater, $cooldown)) {
                return;
            }
        }
    }

    /**
     * Status check for one row with a provider ID. Returns false when the
     * batch should stop on a shared pause or cooldown.
     */
    private function poll(
        string $generationId,
        PixelForgeClient $client,
        ApplyGenerationSnapshot $updater,
        PixelForgeCooldown $cooldown
    ): bool {
        $lock = Cache::lock('pixelforge:generation:'.$generationId, 60);
        if (! $lock->get()) {
            return true;
        }

        try {
            $generation = Generation::find($generationId);

            if ($generation === null
                || $generation->status->isFinal()
                || $generation->provider_id === null
                || $generation->next_check_at === null
                || $generation->next_check_at->isFuture()) {
                return true;
            }

            if ($generation->key_label !== (string) config('services.pixelforge.key_label')) {
                $this->park($generation);

                return true;
            }

            try {
                $response = $client->getGeneration($generation->provider_id);
            } catch (PixelForgePausedException) {
                return false;
            } catch (PixelForgeConnectionException) {
                // No response; the row stays due for the next sweep.
                return true;
            }

            if ($response->successful()) {
                $object = $response->json();
                if (! is_array($object) || ! $updater->apply($generation->id, $object)) {
                    Log::warning('pixelforge.poll_snapshot_rejected', [
                        'generation_id' => $generation->id,
                        'provider_id' => $generation->provider_id,
                    ]);
                }

                $this->advance($generation);

                return true;
            }

            $code = $client->errorCode($response);

            if ($client->isError($response, 401, 'invalid_api_key')) {
                $cooldown->pauseAuth();

                return false;
            }

            if ($client->isError($response, 429, 'rate_limit_exceeded')
                || $client->isError($response, 429, 'concurrency_limit_exceeded')
                || $client->isError($response, 503, 'overloaded')) {
                $deadline = $cooldown->applySharedCooldown(
                    (string) $code,
                    $client->retryAfterSeconds($response)
                );

                // The row waits out at least the shared cooldown before its
                // own next poll, then the whole batch stops.
                $this->advance($generation, $deadline);

                return false;
            }

            Log::warning('pixelforge.poll_error', [
                'generation_id' => $generation->id,
                'provider_id' => $generation->provider_id,
                'status' => $response->status(),
                'code' => $code,
            ]);

            $this->advance($generation);

            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Next status check on the capped backoff ladder, only while the row is
     * still unfinished, bound to the same provider ID, and scheduled. When
     * a shared cooldown applies the row waits at least until that deadline.
     */
    private function advance(Generation $generation, ?int $waitUntil = null): void
    {
        $step = min($generation->recovery_step + 1, count(SubmitGenerationAttempt::RECOVERY_BACKOFF));
        $next = now()->addSeconds(SubmitGenerationAttempt::RECOVERY_BACKOFF[$step - 1]);

        if ($waitUntil !== null) {
            $until = Carbon::createFromTimestamp($waitUntil, 'UTC');
            if ($until->gt($next)) {
                $next = $until;
            }
        }

        Generation::query()
            ->whereKey($generation->id)
            ->where('provider_id', $generation->provider_id)
            ->whereIn('status', [GenerationStatus::Queued->value, GenerationStatus::Processing->value])
            ->whereNotNull('next_check_at')
            ->update([
                'recovery_step' => $step,
                'next_check_at' => $next,
            ]);
    }

    private function park(Generation $generation): void
    {
        Generation::query()
            ->whereKey($generation->id)
            ->whereIn('status', [GenerationStatus::Queued->value, GenerationStatus::Processing->value])
            ->update([
                'next_check_at' => null,
                'attention_reason' => 'key_label_mismatch',
            ]);
    }
}

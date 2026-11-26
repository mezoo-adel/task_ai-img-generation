<?php

namespace App\Services\PixelForge;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs one provider submission for a queued generation that has no provider
 * ID yet, using the original UUID as both metadata.local_generation_id and
 * Idempotency-Key with an unchanged payload. Shared by the initial job and
 * the recovery sweep so retry decisions cannot diverge.
 */
final class SubmitGenerationAttempt
{
    /**
     * Delays in seconds before counted retries (attempts 1-3). At the fourth
     * counted attempt the cap is reached.
     */
    private const array COUNTED_DELAYS = [5, 15, 30];

    private const int MAX_COUNTED_ATTEMPTS = 4;

    /**
     * Backoff ladder in seconds for retries whose acceptance is uncertain
     * and for overdue checks on known provider IDs. Capped at 5 minutes.
     */
    public const array RECOVERY_BACKOFF = [60, 120, 240, 300];

    public function __construct(
        private readonly PixelForgeClient $client,
        private readonly PixelForgeCooldown $cooldown,
        private readonly ApplyGenerationSnapshot $updater,
    ) {}

    /**
     * Run the attempt if the row is still due and unbound.
     *
     * @return bool True lets the sweep continue; false means shared traffic
     *              is paused or cooling down and the batch should stop.
     */
    public function run(string $generationId): bool
    {
        if ($this->cooldown->authPaused() || $this->cooldown->inCooldown()) {
            return false;
        }

        $lock = Cache::lock('pixelforge:generation:'.$generationId, 60);
        if (! $lock->get()) {
            return true;
        }

        try {
            return $this->attempt($generationId);
        } finally {
            $lock->release();
        }
    }

    private function attempt(string $generationId): bool
    {
        $generation = Generation::find($generationId);

        if ($generation === null
            || $generation->status->isFinal()
            || $generation->provider_id !== null
            || $generation->next_check_at === null
            || $generation->next_check_at->isFuture()) {
            return true;
        }

        if ($generation->key_label !== (string) config('services.pixelforge.key_label')) {
            $this->park($generation, 'key_label_mismatch');

            return true;
        }

        $previousAttempts = $generation->submit_attempts;
        $previousUncertainty = $generation->acceptance_uncertain;
        $previousDeadline = $generation->next_check_at;
        $attempts = min(self::MAX_COUNTED_ATTEMPTS, $previousAttempts + 1);

        // Record the attempt before sending so a crash cannot lose it; only
        // an unfinished, still-unbound, still-due row may proceed.
        $recorded = $this->guard($generation)
            ->where('next_check_at', '<=', now())
            ->update([
                'submit_attempts' => $attempts,
                'acceptance_uncertain' => true,
                'next_check_at' => now()->addMinute(),
            ]);

        if ($recorded === 0) {
            return true;
        }

        try {
            $response = $this->client->createGeneration($generation->id, [
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'reference_images' => $generation->reference_urls,
                'metadata' => ['local_generation_id' => $generation->id],
            ]);
        } catch (PixelForgePausedException) {
            // Another worker paused traffic after the preclaim; nothing was
            // sent, so restore the prior attempt state.
            $this->guard($generation)->update([
                'submit_attempts' => $previousAttempts,
                'acceptance_uncertain' => $previousUncertainty,
                'next_check_at' => $previousDeadline,
            ]);

            return false;
        } catch (PixelForgeConnectionException) {
            // The request may have been accepted; replay the same key later.
            $this->scheduleUncertainRetry($generation, $attempts);

            return true;
        }

        if ($response->successful()) {
            $object = $response->json();
            if (is_array($object) && $this->updater->apply($generation->id, $object)) {
                return true;
            }

            Log::warning('pixelforge.create_snapshot_rejected', ['generation_id' => $generation->id]);
            $this->scheduleUncertainRetry($generation, $attempts);

            return true;
        }

        $code = $this->client->errorCode($response);
        $retryAfter = $this->client->retryAfterSeconds($response);

        return match (true) {
            $this->client->isError($response, 429, 'rate_limit_exceeded'),
            $this->client->isError($response, 429, 'concurrency_limit_exceeded') => $this->limited(
                $generation, (string) $code, $retryAfter, $previousAttempts, $previousUncertainty
            ),
            $this->client->isError($response, 503, 'overloaded') => $this->overloaded(
                $generation, $retryAfter, $previousUncertainty, $attempts
            ),
            $this->client->isError($response, 400, 'content_policy_violation'),
            $this->client->isError($response, 422, 'validation_failed'),
            $this->client->isError($response, 429, 'daily_limit_exceeded') => $this->failNow(
                $generation, (string) $code
            ),
            $this->client->isError($response, 401, 'invalid_api_key') => $this->authFailed(
                $generation, $previousAttempts, $previousUncertainty
            ),
            $this->client->isError($response, 409, 'idempotency_key_reused') => $this->conflicted($generation),
            default => $this->unexpected($generation, $attempts, $response->status(), $code),
        };
    }

    /**
     * Nothing was created by a documented 429: undo the attempt count and
     * the uncertainty flag, then wait out the shared cooldown.
     */
    private function limited(
        Generation $generation,
        string $code,
        ?int $retryAfter,
        int $previousAttempts,
        bool $previousUncertainty
    ): bool {
        $deadline = $this->cooldown->applySharedCooldown($code, $retryAfter);

        $this->guard($generation)->update([
            'submit_attempts' => $previousAttempts,
            'acceptance_uncertain' => $previousUncertainty,
            'next_check_at' => Carbon::createFromTimestamp($deadline, 'UTC'),
        ]);

        return false;
    }

    /**
     * An overload counts toward the attempt cap. Before the cap it retries
     * on the documented delays; at the cap it fails only when no earlier
     * attempt could have been accepted.
     */
    private function overloaded(
        Generation $generation,
        ?int $retryAfter,
        bool $previousUncertainty,
        int $attempts
    ): bool {
        $deadline = $this->cooldown->applySharedCooldown('overloaded', $retryAfter);
        $attributes = ['acceptance_uncertain' => $previousUncertainty];

        if ($attempts >= self::MAX_COUNTED_ATTEMPTS) {
            if ($previousUncertainty) {
                $this->scheduleUncertainRetry($generation, $attempts, $retryAfter);
            } else {
                $this->guard($generation)->update(array_merge($attributes, [
                    'status' => GenerationStatus::Failed->value,
                    'failure_code' => 'overloaded',
                    'completed_at' => now(),
                    'next_check_at' => null,
                    'attention_reason' => null,
                ]));
            }
        } else {
            $wait = self::COUNTED_DELAYS[$attempts - 1];
            if ($retryAfter !== null) {
                $wait = max($wait, $retryAfter);
            }
            if ($deadline > 0) {
                $wait = max($wait, $deadline - now()->getTimestamp());
            }
            $this->guard($generation)->update(array_merge($attributes, [
                'next_check_at' => now()->addSeconds($wait),
            ]));
        }

        return false;
    }

    /**
     * A same-key rejection proves the earlier attempt was never accepted.
     */
    private function failNow(Generation $generation, string $code): bool
    {
        $this->guard($generation)->update([
            'status' => GenerationStatus::Failed->value,
            'failure_code' => $code,
            'acceptance_uncertain' => false,
            'completed_at' => now(),
            'next_check_at' => null,
            'attention_reason' => null,
        ]);

        return true;
    }

    /**
     * A provider auth failure pauses all traffic until configuration is
     * restored; the attempt does not count and the row stays unfinished.
     */
    private function authFailed(Generation $generation, int $previousAttempts, bool $previousUncertainty): bool
    {
        $this->cooldown->pauseAuth();

        $this->guard($generation)->update([
            'submit_attempts' => $previousAttempts,
            'acceptance_uncertain' => $previousUncertainty,
            'next_check_at' => now()->addMinute(),
        ]);

        return false;
    }

    /**
     * A key conflict means a different body may exist under this key; park
     * the row for operator review instead of issuing a new key.
     */
    private function conflicted(Generation $generation): bool
    {
        $this->park($generation, 'idempotency_key_reused');

        return true;
    }

    /**
     * Any other response leaves acceptance unknown; replay the same key.
     */
    private function unexpected(Generation $generation, int $attempts, int $status, ?string $code): bool
    {
        Log::warning('pixelforge.create_unexpected', [
            'generation_id' => $generation->id,
            'status' => $status,
            'code' => $code,
        ]);

        $this->scheduleUncertainRetry($generation, $attempts);

        return true;
    }

    /**
     * Next deadline for an uncertain attempt: the documented counted delays
     * before the cap, the capped backoff ladder after it, and a park only
     * when a capped row has stayed uncertain for 24 hours.
     */
    private function scheduleUncertainRetry(Generation $generation, int $attempts, ?int $retryAfter = null): void
    {
        if ($attempts < self::MAX_COUNTED_ATTEMPTS) {
            $this->guard($generation)->update([
                'next_check_at' => now()->addSeconds(self::COUNTED_DELAYS[$attempts - 1]),
            ]);

            return;
        }

        if ($this->parkedAfter24Hours($generation)) {
            return;
        }

        $step = min($generation->recovery_step + 1, count(self::RECOVERY_BACKOFF));
        $delay = self::RECOVERY_BACKOFF[$step - 1];
        if ($retryAfter !== null) {
            $delay = max($delay, $retryAfter);
        }

        $this->guard($generation)->update([
            'recovery_step' => $step,
            'next_check_at' => now()->addSeconds($delay),
        ]);
    }

    private function parkedAfter24Hours(Generation $generation): bool
    {
        if ($generation->created_at === null || $generation->created_at->gt(now()->subDay())) {
            return false;
        }

        $this->park($generation, 'unresolved_24h');

        return true;
    }

    /**
     * Stop automatic recovery while leaving the row non-final so late
     * signed callbacks can still resolve it.
     */
    private function park(Generation $generation, string $reason): void
    {
        $this->guard($generation)->update([
            'next_check_at' => null,
            'attention_reason' => $reason,
        ]);
    }

    /**
     * Base conditional write: only an unfinished, still-unbound row may be
     * touched, so a webhook that already bound or finalized wins.
     *
     * @return Builder<Generation>
     */
    private function guard(Generation $generation): Builder
    {
        return Generation::query()
            ->whereKey($generation->id)
            ->whereNull('provider_id')
            ->whereIn('status', [GenerationStatus::Queued->value, GenerationStatus::Processing->value]);
    }
}

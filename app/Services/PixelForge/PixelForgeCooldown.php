<?php

namespace App\Services\PixelForge;

use Illuminate\Support\Facades\Cache;

/**
 * Shared PixelForge traffic gates: a persistent auth pause flag and a
 * timestamped cooldown that rate-limit, concurrency-limit, and overload
 * responses extend. Both provider jobs consult these before every
 * authenticated call.
 */
final class PixelForgeCooldown
{
    private const string AUTH_PAUSED_KEY = 'pixelforge:auth-paused';

    private const string COOLDOWN_UNTIL_KEY = 'pixelforge:cooldown-until';

    private const string COOLDOWN_LOCK_KEY = 'pixelforge:cooldown-lock';

    /**
     * Shared cooldown fallbacks in seconds when the provider sends no
     * Retry-After, keyed by documented error code.
     *
     * @var array<string, int>
     */
    private const array FALLBACK_SECONDS = [
        'rate_limit_exceeded' => 60,
        'concurrency_limit_exceeded' => 5,
        'overloaded' => 2,
    ];

    /**
     * Authenticated traffic is paused while the flag is set or while no API
     * key is configured at all.
     */
    public function authPaused(): bool
    {
        if (blank(config('services.pixelforge.api_key'))) {
            return true;
        }

        return (bool) Cache::get(self::AUTH_PAUSED_KEY, false);
    }

    public function pauseAuth(): void
    {
        Cache::forever(self::AUTH_PAUSED_KEY, true);
    }

    /**
     * Unix timestamp until which authenticated calls should wait, or null.
     */
    public function cooldownUntil(): ?int
    {
        $until = Cache::get(self::COOLDOWN_UNTIL_KEY);

        return is_int($until) && $until > now()->timestamp ? $until : null;
    }

    public function inCooldown(): bool
    {
        return $this->cooldownUntil() !== null;
    }

    /**
     * Extend the shared cooldown for a documented limit response. The
     * provider's Retry-After wins; the documented fallback applies when it
     * is missing. Unknown codes are ignored.
     *
     * @return int The cooldown deadline applied, or zero when not applicable.
     */
    public function applySharedCooldown(string $code, ?int $retryAfterSeconds): int
    {
        if (! array_key_exists($code, self::FALLBACK_SECONDS)) {
            return 0;
        }

        return $this->extend(now()->getTimestamp() + ($retryAfterSeconds ?? self::FALLBACK_SECONDS[$code]));
    }

    /**
     * Move the shared cooldown deadline forward to at least the given
     * timestamp, using a short lock so the read-modify-write is atomic. The
     * lock is never held across an HTTP call.
     */
    public function extend(int $until): int
    {
        $lock = Cache::lock(self::COOLDOWN_LOCK_KEY, 5);

        return (int) $lock->block(3, function () use ($until): int {
            $current = Cache::get(self::COOLDOWN_UNTIL_KEY);
            $deadline = is_int($current) ? max($current, $until) : $until;

            Cache::forever(self::COOLDOWN_UNTIL_KEY, $deadline);

            return $deadline;
        });
    }
}

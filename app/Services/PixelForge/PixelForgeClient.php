<?php

namespace App\Services\PixelForge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Thin PixelForge HTTP client. Every request uses a 2-second connect
 * timeout and a 5-second total timeout, follows no redirects so the bearer
 * key can never leak to another host, and never retries internally.
 * Authenticated calls honour the shared auth-pause and cooldown gates;
 * thrown exceptions never carry request URLs or response bodies.
 */
final class PixelForgeClient
{
    private const string PRICING_CACHE_KEY = 'pixelforge:pricing';

    private const int PRICING_CACHE_TTL = 60;

    private const int IMAGE_MAX_BYTES = 12 * 1024 * 1024;

    private const string PROVIDER_ID_PATTERN = '/^gen_[a-z0-9_-]{1,100}$/i';

    private const string PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    public function __construct(private readonly PixelForgeCooldown $cooldown) {}

    /**
     * The public price list, shared through a 60-second cache. No API key
     * or pause check applies to this call.
     *
     * @return array<string, mixed>
     */
    public function pricing(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = Cache::remember(self::PRICING_CACHE_KEY, self::PRICING_CACHE_TTL, function (): array {
            try {
                $response = $this->request()->get('pricing');
            } catch (ConnectionException) {
                throw new PixelForgeConnectionException('PixelForge pricing is unreachable.');
            }

            if (! $response->successful()) {
                throw new PixelForgeConnectionException('PixelForge pricing is unavailable.');
            }

            $decoded = $response->json();

            if (! is_array($decoded)) {
                throw new PixelForgeConnectionException('PixelForge pricing response was not usable.');
            }

            return $decoded;
        });

        return $decoded;
    }

    /**
     * POST /v1/generations with a stable idempotency key. Callers inspect
     * the response for the documented status and error codes.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createGeneration(string $idempotencyKey, array $payload): Response
    {
        $this->ensureCallable();

        try {
            return $this->authenticated()
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post('generations', $payload);
        } catch (ConnectionException) {
            throw new PixelForgeConnectionException('PixelForge did not respond to the create request.');
        }
    }

    /**
     * GET /v1/generations/{id} for a known provider ID.
     */
    public function getGeneration(string $providerId): Response
    {
        $this->ensureCallable();

        try {
            return $this->authenticated()->get('generations/'.rawurlencode($providerId));
        } catch (ConnectionException) {
            throw new PixelForgeConnectionException('PixelForge did not respond to the status request.');
        }
    }

    /**
     * GET a stored image asset. The signed URL is validated against the
     * pinned provider host and expected path before any request, then read
     * from a bounded stream. Returns PNG bytes, or null on any failure —
     * provider URLs are never included in thrown or logged context.
     */
    public function downloadImage(string $url, string $providerId): ?string
    {
        if (! $this->isTrustedImageUrl($url, $providerId)) {
            return null;
        }

        try {
            $response = $this->request()
                ->withOptions(['stream' => true, 'read_timeout' => 5])
                ->get($url);
        } catch (ConnectionException) {
            return null;
        }

        $body = $response->toPsrResponse()->getBody();
        $contentType = strtolower(
            trim(explode(';', (string) $response->header('Content-Type'))[0])
        );

        if (! $response->successful() || $contentType !== 'image/png') {
            $body->close();

            return null;
        }

        try {
            $contents = '';
            $deadline = hrtime(true) + 5_000_000_000;

            while (! $body->eof()) {
                if (hrtime(true) >= $deadline) {
                    return null;
                }

                $chunk = $body->read(8192);

                if ($chunk === '') {
                    break;
                }

                $contents .= $chunk;

                if (strlen($contents) > self::IMAGE_MAX_BYTES) {
                    return null;
                }
            }
        } catch (Throwable $exception) {
            Log::warning('pixelforge.image_download_read_failed', [
                'provider_id' => $providerId,
                'exception' => $exception::class,
            ]);

            return null;
        } finally {
            $body->close();
        }

        if (! str_starts_with($contents, self::PNG_SIGNATURE)) {
            return null;
        }

        return $contents;
    }

    /**
     * Whether the stored URL is an HTTPS asset on the configured provider
     * host with the exact expected path and a signed query. Anything else
     * is refused without a request.
     */
    private function isTrustedImageUrl(string $url, string $providerId): bool
    {
        if (preg_match(self::PROVIDER_ID_PATTERN, $providerId) !== 1) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['fragment'])
            && ($parts['port'] ?? 443) === 443
            && strtolower($parts['host'] ?? '') === $this->trustedImageHost()
            && ($parts['path'] ?? '') === '/images/'.$providerId.'.png'
            && ($parts['query'] ?? '') !== '';
    }

    private function trustedImageHost(): string
    {
        $host = parse_url($this->baseUrl(), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new InvalidArgumentException('PixelForge base URL is not configured.');
        }

        return strtolower($host);
    }

    /**
     * The documented provider error code from a response, or null when it
     * is missing or is not a safe machine code.
     */
    public function errorCode(Response $response): ?string
    {
        $code = $response->json('error.code');

        return is_string($code) && preg_match('/^[a-z0-9_]{1,64}$/', $code) === 1
            ? $code
            : null;
    }

    /**
     * Whether the response is an exact documented HTTP status + error code
     * pair. Anything else is an unknown response, never a documented one.
     */
    public function isError(Response $response, int $status, string $code): bool
    {
        return $response->status() === $status && $this->errorCode($response) === $code;
    }

    /**
     * The Retry-After header as whole seconds from now, whether it was sent
     * as an integer delay or an HTTP date. Absurd values count as missing.
     */
    public function retryAfterSeconds(Response $response): ?int
    {
        $header = trim($response->header('Retry-After'));
        if ($header === '') {
            return null;
        }
        if (ctype_digit($header)) {
            $seconds = (int) $header;

            return $seconds <= 86400 ? $seconds : null;
        }

        $timestamp = strtotime($header);

        if ($timestamp === false) {
            return null;
        }

        $seconds = $timestamp - now()->getTimestamp();

        return $seconds >= 0 && $seconds <= 86400 ? $seconds : null;
    }

    private function ensureCallable(): void
    {
        if ($this->cooldown->authPaused() || $this->cooldown->inCooldown()) {
            throw new PixelForgePausedException('PixelForge traffic is paused or cooling down.');
        }
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->connectTimeout(2)
            ->timeout(5)
            ->withoutRedirecting();
    }

    private function authenticated(): PendingRequest
    {
        $key = config('services.pixelforge.api_key');
        if (! is_string($key) || $key === '') {
            throw new PixelForgePausedException('PixelForge API key is not configured.');
        }

        return $this->request()->withToken($key);
    }

    private function baseUrl(): string
    {
        $baseUrl = config('services.pixelforge.base_url');

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new InvalidArgumentException('PixelForge base URL is not configured.');
        }

        return $baseUrl;
    }
}

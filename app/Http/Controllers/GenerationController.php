<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Http\Requests\GenerationInputRequest;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\PixelForge\PixelForgeClient;
use App\Services\PixelForge\PixelForgeConnectionException;
use App\Services\PixelForge\PixelForgePricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class GenerationController extends Controller
{
    /**
     * Plain-language failure text keyed by stored provider code. Provider
     * messages are never shown.
     *
     * @var array<string, string>
     */
    private const array FAILURE_MESSAGES = [
        'content_policy_violation' => 'The prompt was rejected by the content policy.',
        'validation_failed' => 'The request could not be processed by the provider.',
        'daily_limit_exceeded' => 'The daily generation limit was reached. No charge was made.',
        'overloaded' => 'The provider was overloaded. No charge was made.',
        'idempotency_key_reused' => 'The request could not be confirmed with the provider.',
        'default' => 'The generation failed. No charge was made.',
    ];

    public function __construct(
        private readonly PixelForgeClient $client,
        private readonly PixelForgePricing $pricing,
    ) {}

    /**
     * The signed-in user's dashboard with their three most recent
     * generations.
     */
    public function dashboard(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $recentGenerations = Generation::query()
            ->where('user_id', $user->id)
            ->select(['id', 'prompt', 'size', 'quality', 'status', 'next_check_at'])
            ->latest('created_at')
            ->latest('id')
            ->limit(3)
            ->get()
            ->map(fn (Generation $generation): array => [
                'id' => $generation->id,
                'prompt' => Str::limit((string) $generation->prompt, 120),
                'size' => $generation->size,
                'quality' => $generation->quality,
                'status' => $generation->status->value,
                'needs_attention' => ! $generation->status->isFinal()
                    && $generation->next_check_at === null,
            ]);

        return Inertia::render('Dashboard', [
            'recentGenerations' => $recentGenerations,
        ]);
    }

    /**
     * Show the generation form.
     */
    public function create(): Response
    {
        return Inertia::render('generations/Create');
    }

    /**
     * Estimated charge range for the given input, without creating a
     * generation.
     */
    public function estimate(GenerationInputRequest $request): JsonResponse
    {
        $bounds = $this->bounds($request);

        if ($bounds === null) {
            return response()->json([
                'message' => __('The price estimate is temporarily unavailable. Please try again.'),
            ], 503);
        }

        return response()->json([
            'estimated_min_usd' => $this->pricing->formatUsd($bounds['min']),
            'estimated_max_usd' => $this->pricing->formatUsd($bounds['max']),
        ]);
    }

    /**
     * Save a queued generation with its freshly computed estimate bounds,
     * then dispatch the submission job after the row commits.
     */
    public function store(GenerationInputRequest $request): RedirectResponse
    {
        $bounds = $this->bounds($request);

        if ($bounds === null) {
            throw ValidationException::withMessages([
                'pricing' => __('The price estimate is temporarily unavailable. Please try again.'),
            ]);
        }

        $validated = $request->validated();
        /** @var User $user */
        $user = $request->user();
        $references = is_array($validated['reference_images'] ?? null)
            ? array_values($validated['reference_images'])
            : [];

        $generation = DB::transaction(function () use ($user, $validated, $references, $bounds): Generation {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            return Generation::create([
                'user_id' => $user->id,
                'key_label' => (string) config('services.pixelforge.key_label'),
                'prompt' => (string) $validated['prompt'],
                'size' => (string) $validated['size'],
                'quality' => (string) $validated['quality'],
                'reference_urls' => $references,
                'status' => GenerationStatus::Queued,
                'estimated_min_picos' => $bounds['min'],
                'estimated_max_picos' => $bounds['max'],
                'next_check_at' => now(),
            ]);
        });

        SubmitGeneration::dispatch($generation->id)->afterCommit();

        return to_route('generations.index');
    }

    /**
     * The signed-in user's history, newest first.
     */
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $generations = Generation::query()
            ->where('user_id', $user->id)
            ->with('charge')
            ->latest('created_at')
            ->latest('id')
            ->simplePaginate(20)
            ->through(fn (Generation $generation): array => $this->row($generation));

        return Inertia::render('generations/Index', [
            'generations' => $generations,
        ]);
    }

    /**
     * Download a finished image through the app. The stored signed URL is
     * validated against the pinned provider host before a bounded read, so
     * this never proxies arbitrary URLs.
     */
    public function download(Request $request, string $generation): HttpResponse
    {
        /** @var User $user */
        $user = $request->user();

        $row = Generation::query()
            ->where('user_id', $user->id)
            ->where('id', $generation)
            ->select(['id', 'provider_id', 'status', 'image_url', 'image_expires_at'])
            ->first();

        abort_if(
            $row === null
                || $row->status !== GenerationStatus::Succeeded
                || $row->image_url === null
                || $row->provider_id === null,
            404
        );

        abort_unless(
            $row->image_expires_at !== null && $row->image_expires_at->isFuture(),
            410
        );

        $bytes = $this->client->downloadImage($row->image_url, $row->provider_id);

        abort_if($bytes === null, 502);

        return response($bytes, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="pixelforge-'.$row->id.'.png"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Estimate bounds from the cached price list, or null when pricing is
     * unavailable or cannot be priced exactly.
     *
     * @return array{min: int, max: int}|null
     */
    private function bounds(GenerationInputRequest $request): ?array
    {
        try {
            $priceList = $this->client->pricing();
        } catch (PixelForgeConnectionException) {
            return null;
        }

        $validated = $request->validated();
        $references = is_array($validated['reference_images'] ?? null)
            ? array_values($validated['reference_images'])
            : [];

        try {
            return $this->pricing->estimate(
                (string) $validated['prompt'],
                (string) $validated['size'],
                (string) $validated['quality'],
                count($references),
                $priceList
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Generation $generation): array
    {
        $status = $generation->status;
        $imageValid = $status === GenerationStatus::Succeeded
            && $generation->image_url !== null
            && $generation->image_expires_at !== null
            && $generation->image_expires_at->isFuture();

        return [
            'id' => $generation->id,
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_count' => count($generation->reference_urls),
            'status' => $status->value,
            'estimated_min_usd' => $this->pricing->formatUsd($generation->estimated_min_picos),
            'estimated_max_usd' => $this->pricing->formatUsd($generation->estimated_max_picos),
            'charge_usd' => $this->chargeUsd($generation),
            'failure_message' => $status === GenerationStatus::Failed
                ? (self::FAILURE_MESSAGES[$generation->failure_code ?? ''] ?? self::FAILURE_MESSAGES['default'])
                : null,
            'image_url' => $imageValid ? $generation->image_url : null,
            'image_expired' => $status === GenerationStatus::Succeeded && ! $imageValid,
            'image_expires_at' => $generation->image_expires_at?->toIso8601String(),
            'is_delayed' => ! $status->isFinal()
                && $generation->created_at !== null
                && $generation->created_at->lt(now()->subMinutes(2)),
            'needs_attention' => ! $status->isFinal() && $generation->next_check_at === null,
            'created_at' => $generation->created_at?->toIso8601String(),
        ];
    }

    private function chargeUsd(Generation $generation): ?string
    {
        if ($generation->status === GenerationStatus::Succeeded && $generation->charge !== null) {
            return $this->pricing->formatUsd($generation->charge->amount_picos);
        }

        if ($generation->status === GenerationStatus::Failed) {
            return '0.00';
        }

        return null;
    }
}

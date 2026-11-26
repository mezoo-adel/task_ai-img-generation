<?php

use App\Enums\GenerationStatus;
use App\Jobs\PollOverdueGenerations;
use App\Models\Generation;
use App\Services\PixelForge\PixelForgeCooldown;
use App\Services\PixelForge\SubmitGenerationAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.pixelforge.api_key' => 'pk_test',
        'services.pixelforge.key_label' => 'local',
    ]);

    Http::preventStrayRequests();
});

test('a timed-out attempt is replayed by the sweep with an identical keyed payload', function () {
    $generation = Generation::factory()->create();

    $calls = 0;
    $bodies = [];
    $idempotencyKeys = [];

    Http::fake(function ($request) use (&$calls, &$bodies, &$idempotencyKeys, $generation) {
        $calls++;
        $bodies[] = $request->body();
        $idempotencyKeys[] = $request->header('Idempotency-Key')[0] ?? null;

        if ($calls === 1) {
            throw new ConnectionException('Connection timed out.');
        }

        return Http::response(pixelforgeGenerationObject('gen_replay', 'processing', $generation->id), 202);
    });

    $attempt = app(SubmitGenerationAttempt::class);

    // The lost response leaves acceptance uncertain; the row stays due.
    $attempt->run($generation->id);

    $generation->refresh();
    expect($generation->acceptance_uncertain)->toBeTrue()
        ->and($generation->submit_attempts)->toBe(1)
        ->and($generation->provider_id)->toBeNull();

    // Let the recovery deadline pass, then let the sweep replay it.
    $this->travel(10)->seconds();
    app()->call([app(PollOverdueGenerations::class), 'handle']);

    // Requests that throw are not recorded as sent pairs; count the fake calls.
    expect($calls)->toBe(2);
    expect($bodies[0])->toBe($bodies[1])
        ->and($idempotencyKeys[0])->toBe($generation->id)
        ->and($idempotencyKeys[1])->toBe($generation->id);

    $generation->refresh();
    expect($generation->provider_id)->toBe('gen_replay')
        ->and($generation->acceptance_uncertain)->toBeFalse()
        ->and($generation->status)->toBe(GenerationStatus::Processing);
});

test('a documented 429 spends no attempt budget and pauses the batch for others', function () {
    $generation = Generation::factory()->create();
    $other = Generation::factory()->create();

    Http::fake(fn () => Http::response(
        ['error' => ['type' => 'rate_limit', 'code' => 'concurrency_limit_exceeded', 'message' => 'Try again.']],
        429,
        ['Retry-After' => '5']
    ));

    $attempt = app(SubmitGenerationAttempt::class);
    $cooldown = app(PixelForgeCooldown::class);

    // The 429 itself stops the batch.
    expect($attempt->run($generation->id))->toBeFalse();

    $generation->refresh();
    expect($generation->submit_attempts)->toBe(0)
        ->and($generation->acceptance_uncertain)->toBeFalse()
        ->and($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->next_check_at?->isFuture())->toBeTrue();

    expect($cooldown->inCooldown())->toBeTrue();

    // Another generation cannot spend budget while the cooldown stands.
    expect($attempt->run($other->id))->toBeFalse();

    Http::assertSentCount(1);
});

test('a same-key rejection after an uncertain attempt fails the row with no charge', function (int $status, string $code) {
    $generation = Generation::factory()->create([
        'submit_attempts' => 1,
        'acceptance_uncertain' => true,
    ]);

    Http::fake(fn () => Http::response(
        ['error' => ['type' => 'rejected', 'code' => $code, 'message' => 'Rejected.']],
        $status
    ));

    $result = app(SubmitGenerationAttempt::class)->run($generation->id);

    expect($result)->toBeTrue();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->failure_code)->toBe($code)
        ->and($generation->acceptance_uncertain)->toBeFalse()
        ->and($generation->completed_at)->not->toBeNull()
        ->and($generation->next_check_at)->toBeNull();

    $this->assertDatabaseMissing('credit_transactions', ['generation_id' => $generation->id]);
})->with([
    'content policy violation' => [400, 'content_policy_violation'],
    'validation failure' => [422, 'validation_failed'],
    'daily limit' => [429, 'daily_limit_exceeded'],
]);

test('a provider auth failure pauses shared traffic without failing the row', function () {
    $generation = Generation::factory()->create();
    $other = Generation::factory()->create();

    Http::fake(fn () => Http::response(
        ['error' => ['type' => 'auth', 'code' => 'invalid_api_key', 'message' => 'Bad key.']],
        401
    ));

    $attempt = app(SubmitGenerationAttempt::class);

    expect($attempt->run($generation->id))->toBeFalse();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->submit_attempts)->toBe(0)
        ->and($generation->acceptance_uncertain)->toBeFalse();

    expect(app(PixelForgeCooldown::class)->authPaused())->toBeTrue();

    // Nothing else may call the provider while the pause stands.
    expect($attempt->run($other->id))->toBeFalse();

    Http::assertSentCount(1);
});

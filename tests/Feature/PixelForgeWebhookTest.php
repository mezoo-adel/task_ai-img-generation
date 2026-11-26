<?php

use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Services\PixelForge\ApplyGenerationSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const PIXELFORGE_SECRET = 'whsec_test_secret';

beforeEach(function () {
    config([
        'services.pixelforge.webhook_secret' => PIXELFORGE_SECRET,
        'services.pixelforge.api_key' => 'pk_test',
        'services.pixelforge.key_label' => 'local',
    ]);
});

/**
 * Sign a raw body exactly like the provider does.
 */
function pixelforgeSignature(string $body, ?int $timestamp = null, string $secret = PIXELFORGE_SECRET): string
{
    $timestamp ??= time();

    return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
}

/**
 * The documented succeeded generation at the locked rates.
 *
 * @return array<string, mixed>
 */
function pixelforgeSucceededObject(string $id, string $localId): array
{
    return pixelforgeGenerationObject($id, 'succeeded', $localId, [
        'usage' => [
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'total_tokens' => 10411,
        ],
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'completed_at' => '2026-01-01T00:01:00Z',
        'image_url' => 'https://images.example/gen.png',
        'image_url_expires_at' => '2026-01-02T00:01:00Z',
    ]);
}

/**
 * POST a body to the webhook endpoint. Pass true to auto-sign, a string for a
 * verbatim signature header, or null to send no signature header.
 */
function postPixelforgeWebhook(object $test, string $body, string|bool|null $signature = true)
{
    $server = ['CONTENT_TYPE' => 'application/json'];

    if ($signature === true) {
        $server['HTTP_PIXELFORGE_SIGNATURE'] = pixelforgeSignature($body);
    } elseif (is_string($signature)) {
        $server['HTTP_PIXELFORGE_SIGNATURE'] = $signature;
    }

    return $test->call(
        'POST',
        '/webhooks/pixelforge',
        server: $server,
        content: $body,
    );
}

test('the endpoint refuses requests before reading the body when the secret is unset', function () {
    config(['services.pixelforge.webhook_secret' => null]);

    postPixelforgeWebhook($this, '{}')->assertStatus(503);
});

test('invalid or stale signatures are rejected without writes', function (?string $signature) {
    $generation = Generation::factory()->create();
    $body = json_encode([
        'id' => 'evt_bad',
        'type' => 'generation.succeeded',
        'data' => ['object' => pixelforgeSucceededObject('gen_bad', $generation->id)],
    ]);

    postPixelforgeWebhook($this, $body, $signature)->assertStatus(400);

    $this->assertDatabaseCount('pixelforge_webhook_events', 0);
    $this->assertDatabaseMissing('credit_transactions', ['generation_id' => $generation->id]);
    expect($generation->fresh()->status)->toBe(GenerationStatus::Queued);
})->with([
    'missing signature' => [null],
    'malformed signature' => ['v1=abc'],
    'wrong signature' => fn () => 't='.time().',v1='.str_repeat('0', 64),
    'stale timestamp' => fn () => pixelforgeSignature('ignored', time() - 301),
]);

test('a signed success commits once, stays idempotent, and ignores late regressions', function () {
    $generation = Generation::factory()->create();
    $object = pixelforgeSucceededObject('gen_early', $generation->id);

    $event = fn (string $id): string => json_encode([
        'id' => $id,
        'type' => 'generation.succeeded',
        'data' => ['object' => $object],
    ]);

    // The webhook arrives before the create response snapshot.
    postPixelforgeWebhook($this, $event('evt_first'))->assertNoContent();
    postPixelforgeWebhook($this, $event('evt_first'))->assertNoContent();
    postPixelforgeWebhook($this, $event('evt_second'))->assertNoContent();

    // A replayed event ID carrying a different, still-valid object is a
    // no-op: the claimed event can never touch another generation.
    $other = Generation::factory()->create();
    postPixelforgeWebhook($this, json_encode([
        'id' => 'evt_first',
        'type' => 'generation.succeeded',
        'data' => ['object' => pixelforgeSucceededObject('gen_other', $other->id)],
    ]))->assertNoContent();

    expect($other->fresh())
        ->status->toBe(GenerationStatus::Queued)
        ->provider_id->toBeNull();
    $this->assertDatabaseMissing('credit_transactions', ['generation_id' => $other->id]);

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_id)->toBe('gen_early')
        ->and($generation->provider_cost_picos)->toBe(305510000000);

    expect(CreditTransaction::query()->where('generation_id', $generation->id)->sole())
        ->amount_picos->toBe(381887500000);

    expect(DB::table('pixelforge_webhook_events')->pluck('event_id')->sort()->values()->all())
        ->toBe(['evt_first', 'evt_second']);

    // A late processing event must not move the final status backward.
    postPixelforgeWebhook($this, json_encode([
        'id' => 'evt_late_processing',
        'type' => 'generation.processing',
        'data' => ['object' => pixelforgeGenerationObject('gen_early', 'processing', $generation->id)],
    ]))->assertNoContent();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Succeeded);

    // The delayed create-response snapshot through the same updater is stale.
    $applied = app(ApplyGenerationSnapshot::class)->apply(
        $generation->id,
        pixelforgeGenerationObject('gen_early', 'queued', $generation->id)
    );

    expect($applied)->toBeTrue()
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Succeeded);
});

test('a failed local commit is retryable and the replay commits charge and event', function () {
    $generation = Generation::factory()->create();
    $seeded = CreditTransaction::create([
        'user_id' => $generation->user_id,
        'generation_id' => $generation->id,
        'kind' => 'charge',
        'amount_picos' => 1,
    ]);

    $body = json_encode([
        'id' => 'evt_retry',
        'type' => 'generation.succeeded',
        'data' => ['object' => pixelforgeSucceededObject('gen_retry', $generation->id)],
    ]);

    postPixelforgeWebhook($this, $body)->assertStatus(500);

    // The event was not recorded, so the provider can retry.
    $this->assertDatabaseMissing('pixelforge_webhook_events', ['event_id' => 'evt_retry']);
    expect($generation->fresh()->status)->toBe(GenerationStatus::Queued);

    $seeded->delete();

    postPixelforgeWebhook($this, $body)->assertNoContent();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded);

    $charge = CreditTransaction::query()->where('generation_id', $generation->id)->sole();
    expect($charge->amount_picos)->toBe(381887500000);

    $this->assertDatabaseHas('pixelforge_webhook_events', ['event_id' => 'evt_retry']);
});

test('a signed event for an unknown local generation is acknowledged without a write', function () {
    $localId = (string) str()->uuid();
    $body = json_encode([
        'id' => 'evt_unknown',
        'type' => 'generation.processing',
        'data' => ['object' => pixelforgeGenerationObject('gen_other', 'processing', $localId)],
    ]);

    postPixelforgeWebhook($this, $body)->assertNoContent();

    $this->assertDatabaseMissing('pixelforge_webhook_events', ['event_id' => 'evt_unknown']);
});

test('a signed ping records the event without a generation lookup', function () {
    $body = json_encode(['id' => 'evt_ping', 'type' => 'ping', 'data' => ['object' => new stdClass]]);

    postPixelforgeWebhook($this, $body)->assertNoContent();

    $this->assertDatabaseHas('pixelforge_webhook_events', ['event_id' => 'evt_ping']);
});

test('signed events with a malformed object or binding conflict are rejected', function (Generation $generation, array $object) {
    $body = json_encode([
        'id' => 'evt_malformed',
        'type' => 'generation.succeeded',
        'data' => ['object' => $object],
    ]);

    postPixelforgeWebhook($this, $body)->assertStatus(422);

    $this->assertDatabaseMissing('pixelforge_webhook_events', ['event_id' => 'evt_malformed']);
    expect($generation->fresh()->status)->not->toBe(GenerationStatus::Succeeded);
})->with([
    'non-UUID local id' => fn () => [
        Generation::factory()->create(),
        pixelforgeSucceededObject('gen_x', 'not-a-uuid'),
    ],
    'unknown status' => fn () => [
        $g = Generation::factory()->create(),
        pixelforgeGenerationObject('gen_x', 'exploded', $g->id),
    ],
    'mismatched provider binding' => function () {
        $generation = Generation::factory()->create(['provider_id' => 'gen_bound']);

        return [
            $generation,
            pixelforgeSucceededObject('gen_other', $generation->id),
        ];
    },
    'missing local metadata' => fn () => [
        $g = Generation::factory()->create(),
        ['id' => 'gen_x', 'status' => 'succeeded', 'metadata' => []],
    ],
]);

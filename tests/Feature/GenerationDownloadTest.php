<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.pixelforge.api_key' => 'pk_test',
        'services.pixelforge.key_label' => 'local',
    ]);

    Http::preventStrayRequests();
});

function finishedGeneration(User $user, array $attributes = []): Generation
{
    return Generation::factory()->for($user)->create(array_merge([
        'provider_id' => 'gen_test01',
        'status' => GenerationStatus::Succeeded,
        'image_url' => 'https://pixelforge.promptedit.dev/images/gen_test01.png?X-Signature=abc',
        'image_expires_at' => now()->addHour(),
    ], $attributes));
}

test('the owner can download a succeeded image as a png attachment', function () {
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAATSURBVBhXY/iPBf8ZIAwjMFQGAB8rAgEp+b13AAAAAElFTkSuQmCC'
    );
    $generation = finishedGeneration($user = User::factory()->create());

    Http::fake(fn () => Http::response($png, 200, ['Content-Type' => 'image/png']));

    $response = $this->actingAs($user)->get(route('generations.download', $generation->id));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/png');
    $response->assertHeader('Content-Disposition', 'attachment; filename="pixelforge-'.$generation->id.'.png"');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->getContent())->toBe($png);

    Http::assertSent(
        fn ($request) => $request->url() === $generation->image_url
            && ! $request->hasHeader('Authorization')
    );
});

test('another user’s generation cannot be downloaded', function () {
    $generation = finishedGeneration(User::factory()->create());
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->get(route('generations.download', $generation->id))
        ->assertNotFound();

    Http::assertNothingSent();
});

test('an expired image is gone without contacting the provider', function () {
    $generation = finishedGeneration($user = User::factory()->create(), [
        'image_expires_at' => now()->subMinute(),
    ]);
    Http::fake();

    $this->actingAs($user)
        ->get(route('generations.download', $generation->id))
        ->assertGone();

    Http::assertNothingSent();
});

test('a url on an untrusted host is refused without a request', function () {
    $generation = finishedGeneration($user = User::factory()->create(), [
        'image_url' => 'https://evil.example.com/images/gen_test01.png?X-Signature=abc',
    ]);
    Http::fake();

    $this->actingAs($user)
        ->get(route('generations.download', $generation->id))
        ->assertStatus(502);

    Http::assertNothingSent();
});

test('a url with an unexpected path is refused without a request', function () {
    $generation = finishedGeneration($user = User::factory()->create(), [
        'image_url' => 'https://pixelforge.promptedit.dev/v1/generations/gen_test01',
    ]);
    Http::fake();

    $this->actingAs($user)
        ->get(route('generations.download', $generation->id))
        ->assertStatus(502);

    Http::assertNothingSent();
});

test('a redirect response is not followed', function () {
    $generation = finishedGeneration($user = User::factory()->create());

    Http::fake(fn () => Http::response('', 302, [
        'Location' => 'https://evil.example.com/images/gen_test01.png',
    ]));

    $this->actingAs($user)
        ->get(route('generations.download', $generation->id))
        ->assertStatus(502);

    Http::assertSentCount(1);
});

test('a non-png provider payload is rejected', function () {
    $generation = finishedGeneration($user = User::factory()->create());

    Http::fake(fn () => Http::response('<html>login</html>', 200, ['Content-Type' => 'text/html']));

    $this->actingAs($user)
        ->get(route('generations.download', $generation->id))
        ->assertStatus(502);
});

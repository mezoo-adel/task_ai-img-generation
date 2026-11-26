<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('unverified users can visit the dashboard', function () {
    $response = $this->actingAs(User::factory()->unverified()->create())
        ->get(route('dashboard'));

    $response->assertOk();
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard shows only the signed-in user\'s three most recent generations', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $rows = collect(range(1, 4))->map(
        fn (int $i) => Generation::factory()->for($user)->create([
            'prompt' => "user prompt {$i}",
            'created_at' => now()->subMinutes(10 - $i),
        ]),
    );

    // A newer generation owned by someone else must not appear.
    Generation::factory()->for($other)->create([
        'prompt' => 'other user prompt',
        'created_at' => now(),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('recentGenerations', 3)
        ->where('recentGenerations.0.id', $rows[3]->id)
        ->where('recentGenerations.1.id', $rows[2]->id)
        ->where('recentGenerations.2.id', $rows[1]->id)
        ->missing('recentGenerations.0.image_url')
        ->missing('recentGenerations.0.charge_usd')
        ->etc(),
    );
});

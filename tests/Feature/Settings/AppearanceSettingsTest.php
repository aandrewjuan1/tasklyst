<?php

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutMiddleware;

test('appearance page route is removed', function () {
    withoutMiddleware();
    /** @var User $user */
    $user = User::factory()->create();
    actingAs($user);

    $response = get('/settings/appearance');

    $response->assertNotFound();
});

test('settings navigation does not show appearance section', function () {
    withoutMiddleware();
    /** @var User $user */
    $user = User::factory()->create();
    actingAs($user);

    $response = get('/settings/profile');

    $response->assertOk();
    $response->assertDontSee('Appearance');
});

test('app layout does not force dark class', function () {
    withoutMiddleware();
    /** @var User $user */
    $user = User::factory()->create();
    actingAs($user);

    $response = get('/dashboard');

    $response->assertOk();
    $response->assertDontSee('class="dark', false);
    $response->assertDontSee('@fluxAppearance', false);
});

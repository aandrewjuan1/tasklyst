<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Route::get('/__error-test/{status}', function (int $status) {
        abort($status);
    })->whereNumber('status');
});

it('renders selected custom error pages for guests', function (int $status): void {
    $response = $this->get("/__error-test/{$status}");

    $response->assertStatus($status);
    $response->assertSee("Error {$status}", false);
    $response->assertSee('Go to Login', false);
    $response->assertSee(route('login'), false);
})->with([403, 404, 419, 429, 500, 503]);

test('authenticated users see dashboard primary action on error pages', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/__error-test/404');

    $response->assertNotFound();
    $response->assertSee('Go to Dashboard', false);
    $response->assertSee(route('dashboard'), false);
});

test('guest access to protected routes still redirects to login', function (): void {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

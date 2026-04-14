<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guest can view the login page', function (): void {
    $response = $this->get(route('login'));

    $response->assertSuccessful();
    $response->assertSee('Welcome to Tasklyst', false);
});

test('login blade contains google cta and tasklyst content', function (): void {
    $response = $this->view('auth.login');

    $response->assertSee('Welcome to Tasklyst', false);
    $response->assertSee('Continue with Google', false);
    $response->assertSee(route('login', ['redirect' => 1]), false);
});

test('authenticated user is redirected away from login page', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('login'))
        ->assertRedirect();
});

test('authenticated user is redirected away from login redirect mode', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('login', ['redirect' => 1]))
        ->assertRedirect();
});

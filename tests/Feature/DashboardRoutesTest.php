<?php

use App\Models\User;

test('guest is redirected from dashboard routes', function (): void {
    $this->get('/')->assertRedirect();
    $this->get(route('dashboard'))->assertRedirect();
});

test('authenticated user can view dashboard from root and dashboard paths', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')->assertOk();
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

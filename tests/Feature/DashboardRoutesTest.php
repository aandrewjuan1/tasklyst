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

test('authenticated user can view dashboard with trend presets in query', function (): void {
    $user = User::factory()->create();

    collect(['daily', 'weekly', 'monthly', '7d', '30d', '90d', 'this_month', 'invalid'])
        ->each(function (string $preset) use ($user): void {
            $this->actingAs($user)
                ->get(route('dashboard', ['preset' => $preset]))
                ->assertOk()
                ->assertSeeText('Trend')
                ->assertSeeText('Daily')
                ->assertSeeText('Weekly')
                ->assertSeeText('Monthly');
        });
});

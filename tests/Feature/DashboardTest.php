<?php

use App\Models\Task;
use App\Models\User;

test('guest is redirected from dashboard', function (): void {
    $this->get(route('dashboard'))->assertRedirect();
});

test('authenticated user can view dashboard with analytics-backed chart payload', function (): void {
    $user = User::factory()->create();

    Task::factory()->for($user)->create([
        'completed_at' => now()->subDay(),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()
        ->assertSee(__('Tasks completed (analytics)'), false)
        ->assertSee('__dashboardAnalyticsChart', false)
        ->assertSee('"labels":', false)
        ->assertSee('"values":', false);
});

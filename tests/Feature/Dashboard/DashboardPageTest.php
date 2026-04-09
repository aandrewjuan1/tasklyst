<?php

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;

test('dashboard loads for authenticated user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertStatus(200);
});

test('dashboard hero greets user by first name', function () {
    $user = User::factory()->create(['name' => 'Jordan Smith']);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertSuccessful();
    $response->assertSee('Dashboard — Hello, Jordan!', false);
});

test('dashboard summary shows total incomplete tasks count', function () {
    $user = User::factory()->create();

    foreach (range(1, 3) as $_) {
        Task::factory()->for($user)->create(['completed_at' => null]);
    }
    Task::factory()->for($user)->create(['completed_at' => now()]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertSuccessful();
    $response->assertSee(__('Total tasks'), false);

    expect(preg_match('/data-testid="dashboard-summary-total-tasks-value"[^>]*>\s*(\d+)\s*</', $response->getContent(), $matches))->toBe(1);
    expect($matches[1])->toBe('3');
});

test('dashboard summary shows to-do tasks count', function () {
    $user = User::factory()->create();

    Task::factory()->for($user)->count(2)->create([
        'status' => TaskStatus::ToDo,
        'completed_at' => null,
    ]);
    Task::factory()->for($user)->create([
        'status' => TaskStatus::Doing,
        'completed_at' => null,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertSuccessful();
    $response->assertSee(__('To-Do Tasks'), false);

    expect(preg_match('/data-testid="dashboard-summary-todo-tasks-value"[^>]*>\s*(\d+)\s*</', $response->getContent(), $matches))->toBe(1);
    expect($matches[1])->toBe('2');
});

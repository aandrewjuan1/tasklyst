<?php

use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertSoftDeleted;

it('creates a task in a transaction', function (): void {
    $user = User::factory()->create();

    $task = app(TaskService::class)->createTask($user, [
        'title' => 'My Task',
    ]);

    expect($task)->toBeInstanceOf(Task::class);
    expect($task->user_id)->toBe($user->id);

    assertDatabaseHas('tasks', [
        'id' => $task->id,
        'user_id' => $user->id,
        'title' => 'My Task',
    ]);
});

it('forces the provided user_id over attributes', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $task = app(TaskService::class)->createTask($user, [
        'title' => 'Task',
        'user_id' => $otherUser->id,
    ]);

    expect($task->user_id)->toBe($user->id);
    assertDatabaseHas('tasks', [
        'id' => $task->id,
        'user_id' => $user->id,
    ]);
});

it('updates and deletes a task', function (): void {
    $user = User::factory()->create();

    $task = app(TaskService::class)->createTask($user, [
        'title' => 'Before',
    ]);

    $updated = app(TaskService::class)->updateTask($task, [
        'title' => 'After',
        'user_id' => User::factory()->create()->id,
    ]);

    expect($updated->title)->toBe('After');
    expect($updated->user_id)->toBe($user->id);

    assertDatabaseHas('tasks', [
        'id' => $task->id,
        'title' => 'After',
    ]);

    $deleted = app(TaskService::class)->deleteTask($task);
    expect($deleted)->toBeTrue();

    assertSoftDeleted('tasks', [
        'id' => $task->id,
    ]);
});

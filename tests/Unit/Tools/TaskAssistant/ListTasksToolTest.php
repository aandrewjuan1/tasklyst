<?php

use App\Models\Task;
use App\Models\User;
use App\Tools\LLM\TaskAssistant\ListTasksTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns tasks for the current user on happy path', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create([
        'user_id' => $user->id,
        'title' => 'My task',
        'status' => \App\Enums\TaskStatus::ToDo,
        'completed_at' => null,
        'end_datetime' => now()->addDay(),
        'duration' => 45,
    ]);
    $tool = new ListTasksTool($user);

    $result = $tool->__invoke([]);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeTrue();
    expect($decoded['tasks'])->toHaveCount(1);
    expect($decoded['tasks'][0]['id'])->toBe($task->id);
    expect($decoded['tasks'][0]['title'])->toBe('My task');
    expect($decoded['tasks'][0]['duration_minutes'])->toBe(45);
    expect($decoded['tasks'][0]['end_datetime'])->not->toBeNull();
});

it('does not return another user tasks', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    Task::factory()->create(['user_id' => $userA->id, 'title' => 'A task']);
    $tool = new ListTasksTool($userB);

    $result = $tool->__invoke([]);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeTrue();
    expect($decoded['tasks'])->toHaveCount(0);
});

it('does not return completed tasks', function (): void {
    $user = User::factory()->create();

    Task::factory()->for($user)->create([
        'title' => 'Active task',
        'status' => \App\Enums\TaskStatus::ToDo,
        'completed_at' => null,
        'end_datetime' => now()->addDay(),
        'duration' => 30,
    ]);

    Task::factory()->for($user)->create([
        'title' => 'Done task',
        'status' => \App\Enums\TaskStatus::Done,
        'completed_at' => now()->subDay(),
        'end_datetime' => now()->subDay(),
        'duration' => 30,
    ]);

    $tool = new ListTasksTool($user);
    $result = $tool->__invoke([]);

    $decoded = json_decode($result, true);
    $titles = array_map(fn (array $t): string => (string) ($t['title'] ?? ''), $decoded['tasks'] ?? []);

    expect($decoded['tasks'])->toHaveCount(1);
    expect($titles)->toContain('Active task');
    expect($titles)->not->toContain('Done task');
});

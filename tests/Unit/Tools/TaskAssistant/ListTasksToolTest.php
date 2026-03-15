<?php

use App\Models\Task;
use App\Models\User;
use App\Tools\TaskAssistant\ListTasksTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns tasks for the current user on happy path', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['user_id' => $user->id, 'title' => 'My task']);
    $tool = new ListTasksTool($user);

    $result = $tool->__invoke([]);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeTrue();
    expect($decoded['tasks'])->toHaveCount(1);
    expect($decoded['tasks'][0]['id'])->toBe($task->id);
    expect($decoded['tasks'][0]['title'])->toBe('My task');
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

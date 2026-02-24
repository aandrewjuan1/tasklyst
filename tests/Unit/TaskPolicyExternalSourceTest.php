<?php

use App\Enums\TaskSourceType;
use App\Models\Task;
use App\Models\User;
use App\Policies\TaskPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows updating and deleting external-source tasks for owner', function () {
    $user = User::factory()->create();

    /** @var Task $task */
    $task = Task::query()->create([
        'user_id' => $user->id,
        'title' => 'Synced task',
        'description' => null,
        'status' => \App\Enums\TaskStatus::ToDo,
        'priority' => \App\Enums\TaskPriority::Medium,
        'complexity' => \App\Enums\TaskComplexity::Moderate,
        'duration' => null,
        'start_datetime' => null,
        'end_datetime' => null,
        'project_id' => null,
        'event_id' => null,
        'source_type' => TaskSourceType::Brightspace,
        'source_id' => 'uid-123',
    ]);

    $policy = new TaskPolicy;

    expect($policy->update($user, $task))->toBeTrue();
    expect($policy->delete($user, $task))->toBeTrue();
});

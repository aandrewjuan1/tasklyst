<?php

use App\Actions\Task\UpdateTaskPropertyAction;
use App\Enums\FocusModeType;
use App\Enums\FocusSessionType;
use App\Enums\TaskStatus;
use App\Models\FocusSession;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\User;

test('status transition done to non-done resets persisted task focus progress', function (): void {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create([
        'status' => TaskStatus::Done,
        'duration' => 60,
    ]);

    FocusSession::factory()->create([
        'user_id' => $user->id,
        'focusable_type' => $task->getMorphClass(),
        'focusable_id' => $task->id,
        'type' => FocusSessionType::Work,
        'focus_mode_type' => FocusModeType::Sprint,
        'started_at' => now()->subMinutes(20),
        'ended_at' => now()->subMinutes(5),
        'completed' => true,
        'paused_seconds' => 0,
    ]);

    $result = app(UpdateTaskPropertyAction::class)->execute(
        $task,
        'status',
        TaskStatus::Doing->value,
        null,
        $user
    );

    expect($result->success)->toBeTrue()
        ->and($result->clearedFocusProgress)->toBeTrue()
        ->and($task->fresh()->status)->toBe(TaskStatus::Doing)
        ->and($task->focusSessions()->count())->toBe(0);
});

test('status transitions that do not leave done keep persisted focus progress', function (): void {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create([
        'status' => TaskStatus::Doing,
        'duration' => 60,
    ]);

    FocusSession::factory()->create([
        'user_id' => $user->id,
        'focusable_type' => $task->getMorphClass(),
        'focusable_id' => $task->id,
        'type' => FocusSessionType::Work,
        'focus_mode_type' => FocusModeType::Pomodoro,
        'started_at' => now()->subMinutes(12),
        'ended_at' => now()->subMinutes(2),
        'completed' => true,
        'paused_seconds' => 0,
    ]);

    $result = app(UpdateTaskPropertyAction::class)->execute(
        $task,
        'status',
        TaskStatus::Done->value,
        null,
        $user
    );

    expect($result->success)->toBeTrue()
        ->and($result->clearedFocusProgress)->toBeFalse()
        ->and($task->fresh()->status)->toBe(TaskStatus::Done)
        ->and($task->focusSessions()->count())->toBe(1);
});

test('recurring task occurrence leaving done clears focus progress', function (): void {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create(['status' => TaskStatus::ToDo]);
    $recurring = RecurringTask::factory()->create(['task_id' => $task->id]);
    TaskInstance::factory()->create([
        'recurring_task_id' => $recurring->id,
        'task_id' => $task->id,
        'instance_date' => '2026-06-01',
        'status' => TaskStatus::Done,
        'completed_at' => now(),
    ]);

    FocusSession::factory()->create([
        'user_id' => $user->id,
        'focusable_type' => $task->getMorphClass(),
        'focusable_id' => $task->id,
        'type' => FocusSessionType::Work,
        'focus_mode_type' => FocusModeType::Sprint,
        'started_at' => now()->subMinutes(20),
        'ended_at' => now()->subMinutes(5),
        'completed' => true,
        'paused_seconds' => 0,
    ]);

    $result = app(UpdateTaskPropertyAction::class)->execute(
        $task->fresh(),
        'status',
        TaskStatus::Doing->value,
        '2026-06-01',
        $user
    );

    expect($result->success)->toBeTrue()
        ->and($result->clearedFocusProgress)->toBeTrue()
        ->and($task->fresh()->focusSessions()->count())->toBe(0);
});

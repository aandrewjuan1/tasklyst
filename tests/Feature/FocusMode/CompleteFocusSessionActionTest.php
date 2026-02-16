<?php

use App\Actions\FocusSession\CompleteFocusSessionAction;
use App\Enums\ActivityLogAction as ActivityLogActionEnum;
use App\Enums\FocusSessionType;
use App\Enums\TaskStatus;
use App\Models\ActivityLog;
use App\Models\FocusSession;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\User;

beforeEach(function (): void {
    $this->action = app(CompleteFocusSessionAction::class);
});

test('updates session with ended_at completed and paused_seconds', function (): void {
    $user = User::factory()->create();
    $session = FocusSession::factory()->for($user)->inProgress()->create();

    $result = $this->action->execute($session, now(), true, 120);

    expect($result->ended_at)->not->toBeNull()
        ->and($result->completed)->toBeTrue()
        ->and($result->paused_seconds)->toBe(120);
});

test('records activity log when completing work session with task', function (): void {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create();
    $session = FocusSession::factory()->for($user)->inProgress()->create([
        'focusable_type' => $task->getMorphClass(),
        'focusable_id' => $task->id,
        'type' => FocusSessionType::Work,
    ]);

    $this->action->execute($session, now(), true, 0);

    $log = ActivityLog::query()
        ->where('loggable_type', $task->getMorphClass())
        ->where('loggable_id', $task->id)
        ->where('action', ActivityLogActionEnum::FocusSessionCompleted)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->payload['focus_session_id'])->toBe($session->id);
});

test('does not record activity log when completed is false', function (): void {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create();
    $session = FocusSession::factory()->for($user)->inProgress()->create([
        'focusable_type' => $task->getMorphClass(),
        'focusable_id' => $task->id,
        'type' => FocusSessionType::Work,
    ]);

    $this->action->execute($session, now(), false, 0);

    $count = ActivityLog::query()
        ->where('action', ActivityLogActionEnum::FocusSessionCompleted)
        ->count();

    expect($count)->toBe(0);
});

test('updates task status to done when completing work session with mark_task_status', function (): void {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create(['status' => TaskStatus::Doing]);
    $session = FocusSession::factory()->for($user)->inProgress()->create([
        'focusable_type' => $task->getMorphClass(),
        'focusable_id' => $task->id,
        'type' => FocusSessionType::Work,
    ]);

    $this->action->execute($session, now(), true, 0, 'done');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Done);
});

test('does not update task status when mark_task_status is null', function (): void {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create(['status' => TaskStatus::ToDo]);
    $session = FocusSession::factory()->for($user)->inProgress()->create([
        'focusable_type' => $task->getMorphClass(),
        'focusable_id' => $task->id,
        'type' => FocusSessionType::Work,
    ]);

    $this->action->execute($session, now(), true, 0, null);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::ToDo);
});

test('updates recurring task instance when session payload has occurrence_date', function (): void {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create(['status' => TaskStatus::ToDo]);
    $recurring = RecurringTask::factory()->create(['task_id' => $task->id]);
    $occurrenceDate = '2025-02-15';
    TaskInstance::factory()->create([
        'recurring_task_id' => $recurring->id,
        'task_id' => $task->id,
        'instance_date' => $occurrenceDate,
        'status' => TaskStatus::Doing,
    ]);
    $session = FocusSession::factory()->for($user)->inProgress()->create([
        'focusable_type' => $task->getMorphClass(),
        'focusable_id' => $task->id,
        'type' => FocusSessionType::Work,
        'payload' => ['occurrence_date' => $occurrenceDate],
    ]);

    $this->action->execute($session, now(), true, 0, 'done');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::ToDo);

    $instance = TaskInstance::query()
        ->where('recurring_task_id', $recurring->id)
        ->whereDate('instance_date', $occurrenceDate)
        ->first();
    expect($instance)->not->toBeNull()
        ->and($instance->status)->toBe(TaskStatus::Done);
});

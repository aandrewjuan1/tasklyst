<?php

use App\Models\Event;
use App\Models\EventException;
use App\Models\RecurringEvent;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\TaskException;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('skip recurring task occurrence creates exception and returns id', function (): void {
    $this->actingAs($this->user);
    $task = Task::factory()->for($this->user)->create();
    $recurring = RecurringTask::factory()->create([
        'task_id' => $task->id,
        'recurrence_type' => \App\Enums\TaskRecurrenceType::Daily,
        'start_datetime' => Carbon::parse('2025-02-01'),
        'end_datetime' => Carbon::parse('2025-02-28'),
    ]);
    $payload = [
        'recurringTaskId' => $recurring->id,
        'exceptionDate' => '2025-02-10',
        'isDeleted' => true,
    ];

    $component = Livewire::test('pages::workspace.index')
        ->call('skipRecurringTaskOccurrence', $payload);

    $exception = TaskException::query()
        ->where('recurring_task_id', $recurring->id)
        ->whereDate('exception_date', '2025-02-10')
        ->first();
    expect($exception)->not->toBeNull()
        ->and($exception->is_deleted)->toBeTrue();

    $component->assertDispatched('recurring-task-occurrence-skipped');
});

test('skip recurring task occurrence returns null when validation fails', function (): void {
    $this->actingAs($this->user);
    $payload = [
        'recurringTaskId' => 99999,
        'exceptionDate' => 'invalid-date',
    ];

    Livewire::test('pages::workspace.index')
        ->call('skipRecurringTaskOccurrence', $payload);

    expect(TaskException::count())->toBe(0);
});

test('restore recurring task occurrence deletes exception', function (): void {
    $this->actingAs($this->user);
    $task = Task::factory()->for($this->user)->create();
    $recurring = RecurringTask::factory()->create(['task_id' => $task->id]);
    $exception = TaskException::factory()->create([
        'recurring_task_id' => $recurring->id,
        'exception_date' => Carbon::parse('2025-02-10'),
    ]);
    $exceptionId = $exception->id;

    Livewire::test('pages::workspace.index')
        ->call('restoreRecurringTaskOccurrence', $exceptionId)
        ->assertDispatched('recurring-task-occurrence-restored');

    expect(TaskException::find($exceptionId))->toBeNull();
});

test('restore recurring task occurrence returns false when exception not found', function (): void {
    $this->actingAs($this->user);

    $result = Livewire::test('pages::workspace.index')
        ->call('restoreRecurringTaskOccurrence', 99999);

    $result->assertDispatched('toast');
});

test('skip recurring event occurrence creates exception and returns id', function (): void {
    $this->actingAs($this->user);
    $event = Event::factory()->for($this->user)->create();
    $recurring = RecurringEvent::factory()->create([
        'event_id' => $event->id,
        'recurrence_type' => \App\Enums\EventRecurrenceType::Daily,
        'start_datetime' => Carbon::parse('2025-02-01'),
        'end_datetime' => Carbon::parse('2025-02-28'),
    ]);
    $payload = [
        'recurringEventId' => $recurring->id,
        'exceptionDate' => '2025-02-10',
        'isDeleted' => true,
    ];

    Livewire::test('pages::workspace.index')
        ->call('skipRecurringEventOccurrence', $payload);

    $exception = EventException::query()
        ->where('recurring_event_id', $recurring->id)
        ->whereDate('exception_date', '2025-02-10')
        ->first();
    expect($exception)->not->toBeNull()
        ->and($exception->is_deleted)->toBeTrue();
});

test('restore recurring event occurrence deletes exception', function (): void {
    $this->actingAs($this->user);
    $event = Event::factory()->for($this->user)->create();
    $recurring = RecurringEvent::factory()->create(['event_id' => $event->id]);
    $exception = EventException::factory()->create([
        'recurring_event_id' => $recurring->id,
        'exception_date' => Carbon::parse('2025-02-10'),
    ]);
    $exceptionId = $exception->id;

    Livewire::test('pages::workspace.index')
        ->call('restoreRecurringEventOccurrence', $exceptionId)
        ->assertDispatched('recurring-event-occurrence-restored');

    expect(EventException::find($exceptionId))->toBeNull();
});

test('skip recurring task occurrence forbids when user cannot update task', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $task = Task::factory()->for($owner)->create();
    $recurring = RecurringTask::factory()->create(['task_id' => $task->id]);
    $payload = [
        'recurringTaskId' => $recurring->id,
        'exceptionDate' => '2025-02-10',
        'isDeleted' => true,
    ];

    $this->actingAs($otherUser);

    Livewire::test('pages::workspace.index')
        ->call('skipRecurringTaskOccurrence', $payload);

    expect(TaskException::where('recurring_task_id', $recurring->id)->count())->toBe(0);
});

test('restore recurring task occurrence forbids when user cannot delete exception', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $task = Task::factory()->for($owner)->create();
    $recurring = RecurringTask::factory()->create(['task_id' => $task->id]);
    $exception = TaskException::factory()->create(['recurring_task_id' => $recurring->id]);
    $exceptionId = $exception->id;

    $this->actingAs($otherUser);

    Livewire::test('pages::workspace.index')
        ->call('restoreRecurringTaskOccurrence', $exceptionId);

    expect(TaskException::find($exceptionId))->not->toBeNull();
});

test('get event exceptions returns skipped dates for recurring event', function (): void {
    $this->actingAs($this->user);
    $event = Event::factory()->for($this->user)->create();
    $recurring = RecurringEvent::factory()->create(['event_id' => $event->id]);
    EventException::factory()->create([
        'recurring_event_id' => $recurring->id,
        'exception_date' => Carbon::parse('2025-02-10'),
        'is_deleted' => true,
    ]);
    EventException::factory()->create([
        'recurring_event_id' => $recurring->id,
        'exception_date' => Carbon::parse('2025-02-15'),
        'is_deleted' => true,
    ]);

    Livewire::test('pages::workspace.index')
        ->call('getEventExceptions', $recurring->id)
        ->assertOk();
});

test('get task exceptions returns skipped dates for recurring task', function (): void {
    $this->actingAs($this->user);
    $task = Task::factory()->for($this->user)->create();
    $recurring = RecurringTask::factory()->create(['task_id' => $task->id]);
    TaskException::factory()->create([
        'recurring_task_id' => $recurring->id,
        'exception_date' => Carbon::parse('2025-02-10'),
        'is_deleted' => true,
    ]);

    Livewire::test('pages::workspace.index')
        ->call('getTaskExceptions', $recurring->id)
        ->assertOk();
});

test('get event exceptions returns empty when user cannot update event', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $event = Event::factory()->for($owner)->create();
    $recurring = RecurringEvent::factory()->create(['event_id' => $event->id]);

    $this->actingAs($otherUser);

    Livewire::test('pages::workspace.index')
        ->call('getEventExceptions', $recurring->id)
        ->assertOk();
});

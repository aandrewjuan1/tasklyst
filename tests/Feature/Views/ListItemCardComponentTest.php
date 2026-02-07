<?php

use App\Enums\EventRecurrenceType;
use App\Enums\EventStatus;
use App\Enums\TaskRecurrenceType;
use App\Enums\TaskStatus;
use App\Models\Event;
use App\Models\RecurringEvent;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Services\EventService;
use App\Services\TaskService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Blade;

it('renders recurring pill for recurring tasks', function (): void {
    $task = Task::factory()->create();

    RecurringTask::query()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => now(),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    $task->load('recurringTask');

    $html = Blade::render('<x-workspace.list-item-card kind="task" :item="$item" />', [
        'item' => $task,
    ]);

    expect($html)->toContain('Daily');
});

it('renders recurring selection for non-recurring tasks', function (): void {
    $task = Task::factory()->create();

    $html = Blade::render('<x-workspace.list-item-card kind="task" :item="$item" />', [
        'item' => $task,
    ]);

    expect($html)->toContain('aria-label="Repeat this task"');
});

it('renders recurring pill for recurring events', function (): void {
    $event = Event::factory()->create();

    RecurringEvent::query()->create([
        'event_id' => $event->id,
        'recurrence_type' => EventRecurrenceType::Weekly,
        'interval' => 1,
        'days_of_week' => null,
        'start_datetime' => now(),
        'end_datetime' => null,
    ]);

    $event->load('recurringEvent');

    $html = Blade::render('<x-workspace.list-item-card kind="event" :item="$item" />', [
        'item' => $event,
    ]);

    expect($html)->toContain('Weekly');
});

it('renders recurring selection for non-recurring events', function (): void {
    $event = Event::factory()->create();

    $html = Blade::render('<x-workspace.list-item-card kind="event" :item="$item" />', [
        'item' => $event,
    ]);

    expect($html)->toContain('aria-label="Repeat this event"');
});

it('uses base task status for recurring task', function (): void {
    Carbon::setTestNow('2026-02-06 10:00:00');

    $task = Task::factory()->create([
        'status' => TaskStatus::Doing,
    ]);

    RecurringTask::query()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => Carbon::parse('2026-02-01 00:00:00'),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    $task->load('recurringTask');

    $effectiveStatus = app(TaskService::class)->getEffectiveStatusForDate(
        $task,
        Carbon::parse('2026-02-06')
    );

    $task->effectiveStatusForDate = $effectiveStatus;

    $html = Blade::render('<x-workspace.list-item-card kind="task" :item="$item" />', [
        'item' => $task,
    ]);

    expect($html)->toContain('Doing');
});

it('uses base event status for recurring event', function (): void {
    Carbon::setTestNow('2026-02-06 10:00:00');

    $event = Event::factory()->create([
        'status' => EventStatus::Tentative,
    ]);

    RecurringEvent::query()->create([
        'event_id' => $event->id,
        'recurrence_type' => EventRecurrenceType::Weekly,
        'interval' => 1,
        'days_of_week' => null,
        'start_datetime' => Carbon::parse('2026-02-01 00:00:00'),
        'end_datetime' => null,
    ]);

    $event->load('recurringEvent');

    $effectiveStatus = app(EventService::class)->getEffectiveStatusForDate(
        $event,
        Carbon::parse('2026-02-06')
    );

    $event->effectiveStatusForDate = $effectiveStatus;

    $html = Blade::render('<x-workspace.list-item-card kind="event" :item="$item" />', [
        'item' => $event,
    ]);

    expect($html)->toContain('Tentative');
});

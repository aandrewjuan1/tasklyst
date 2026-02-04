<?php

use App\Enums\EventRecurrenceType;
use App\Enums\TaskRecurrenceType;
use App\Models\Event;
use App\Models\RecurringEvent;
use App\Models\RecurringTask;
use App\Models\Task;
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

    expect($html)->toContain('Recurring:');
    expect($html)->toContain('Daily');
});

it('renders recurring selection for non-recurring tasks', function (): void {
    $task = Task::factory()->create();

    $html = Blade::render('<x-workspace.list-item-card kind="task" :item="$item" />', [
        'item' => $task,
    ]);

    expect($html)->toContain('Recurring:');
    expect($html)->toContain('Not set');
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
        'timezone' => null,
    ]);

    $event->load('recurringEvent');

    $html = Blade::render('<x-workspace.list-item-card kind="event" :item="$item" />', [
        'item' => $event,
    ]);

    expect($html)->toContain('Recurring:');
    expect($html)->toContain('Weekly');
});

it('does not render recurring pill for non-recurring events', function (): void {
    $event = Event::factory()->create();

    $html = Blade::render('<x-workspace.list-item-card kind="event" :item="$item" />', [
        'item' => $event,
    ]);

    expect($html)->not->toContain('Recurring:');
});

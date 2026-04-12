<?php

use App\Enums\TaskRecurrenceType;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('recurring task with past parent end_datetime still appears on today workspace list', function (): void {
    $task = Task::factory()->for($this->user)->create([
        'title' => 'RecurringPastEndWorkspace',
        'start_datetime' => now()->subWeek()->startOfDay(),
        'end_datetime' => now()->subWeek()->endOfDay(),
    ]);

    RecurringTask::factory()->create([
        'task_id' => $task->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => now()->subDays(30)->startOfDay(),
        'end_datetime' => null,
        'days_of_week' => null,
    ]);

    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', now()->toDateString());

    $titles = $component->instance()->getAllListEntries()
        ->pluck('item.title')
        ->all();

    expect($titles)->toContain('RecurringPastEndWorkspace');
});

test('workspace list dedupes overdue task that also matches selected date', function (): void {
    $yesterday = now()->subDay()->startOfDay();
    $task = Task::factory()->for($this->user)->create([
        'title' => 'OverlapDup',
        'start_datetime' => $yesterday->copy()->subDay(),
        'end_datetime' => $yesterday->copy()->addHours(12),
    ]);

    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', $yesterday->toDateString());

    $entries = $component->instance()->getAllListEntries();
    $taskRows = $entries->filter(fn (array $e): bool => $e['kind'] === 'task' && (int) $e['item']->id === $task->id);

    expect($taskRows)->toHaveCount(1);
});

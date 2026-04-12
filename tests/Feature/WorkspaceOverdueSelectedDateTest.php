<?php

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('task due on a future day is not shown in overdue when browsing an earlier calendar date', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-12 12:00:00'));

    $user = User::factory()->create();

    Task::factory()->for($user)->create([
        'title' => 'Impossible 5h study block before quiz',
        'status' => TaskStatus::ToDo,
        'start_datetime' => Carbon::parse('2026-04-11 07:00:00'),
        'end_datetime' => Carbon::parse('2026-04-11 09:00:00'),
        'completed_at' => null,
    ]);

    $this->actingAs($user);

    $component = Livewire::test('pages::workspace.index')
        ->set('searchScope', 'selected_date')
        ->set('selectedDate', '2026-04-04');

    $instance = $component->instance();
    expect($instance->overdue()->pluck('item.title')->all())->not->toContain('Impossible 5h study block before quiz');
    expect($instance->getAllListEntries()->pluck('item.title')->all())->not->toContain('Impossible 5h study block before quiz');
});

test('task due in the past appears in overdue when selected calendar day is today', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-12 12:00:00'));

    $user = User::factory()->create();

    Task::factory()->for($user)->create([
        'title' => 'DueYesterdayTask',
        'status' => TaskStatus::ToDo,
        'start_datetime' => Carbon::parse('2026-04-11 07:00:00'),
        'end_datetime' => Carbon::parse('2026-04-11 09:00:00'),
        'completed_at' => null,
    ]);

    $this->actingAs($user);

    $titles = Livewire::test('pages::workspace.index')
        ->set('searchScope', 'selected_date')
        ->set('selectedDate', '2026-04-12')
        ->instance()
        ->overdue()
        ->pluck('item.title')
        ->all();

    expect($titles)->toContain('DueYesterdayTask');
});

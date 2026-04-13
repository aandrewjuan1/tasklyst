<?php

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('task not yet due is never in overdue regardless of selected calendar date', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-12 12:00:00'));

    $user = User::factory()->create();

    Task::factory()->for($user)->create([
        'title' => 'DueNextWeekTask',
        'status' => TaskStatus::ToDo,
        'start_datetime' => Carbon::parse('2026-04-19 09:00:00'),
        'end_datetime' => Carbon::parse('2026-04-20 18:00:00'),
        'completed_at' => null,
    ]);

    $this->actingAs($user);

    foreach (['2026-04-04', '2026-04-12', '2026-04-25'] as $selectedDate) {
        $titles = Livewire::test('pages::workspace.index')
            ->set('searchScope', 'selected_date')
            ->set('selectedDate', $selectedDate)
            ->instance()
            ->overdue()
            ->pluck('item.title')
            ->all();

        expect($titles)->not->toContain('DueNextWeekTask');
    }
});

test('actually overdue task appears in overdue when browsing a past calendar day', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-12 12:00:00'));

    $user = User::factory()->create();

    Task::factory()->for($user)->create([
        'title' => 'BacklogFromLastWeek',
        'status' => TaskStatus::ToDo,
        'start_datetime' => Carbon::parse('2026-04-05 07:00:00'),
        'end_datetime' => Carbon::parse('2026-04-05 09:00:00'),
        'completed_at' => null,
    ]);

    $this->actingAs($user);

    $titles = Livewire::test('pages::workspace.index')
        ->set('searchScope', 'selected_date')
        ->set('selectedDate', '2026-04-04')
        ->instance()
        ->overdue()
        ->pluck('item.title')
        ->all();

    expect($titles)->toContain('BacklogFromLastWeek');
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

<?php

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('refreshWorkspaceCalendar recomputes calendarGridMetaForJs after tasks change', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-13 12:00:00'));

    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-04-15');

    $metaBefore = $component->get('calendarGridMetaForJs');
    expect($metaBefore['2026-04-15']['due_count'] ?? 0)->toBe(0);

    Task::factory()->create([
        'user_id' => $user->id,
        'status' => TaskStatus::ToDo,
        'end_datetime' => Carbon::parse('2026-04-15 14:00:00'),
        'start_datetime' => null,
    ]);

    $component->call('refreshWorkspaceCalendar');

    $metaAfter = $component->get('calendarGridMetaForJs');
    expect($metaAfter['2026-04-15']['due_count'] ?? 0)->toBeGreaterThan(0);
});

test('refreshWorkspaceCalendar recomputes selectedDayAgenda summary', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-15 12:00:00'));

    $user = User::factory()->create();
    $this->actingAs($user);

    Task::factory()->create([
        'user_id' => $user->id,
        'status' => TaskStatus::ToDo,
        'end_datetime' => Carbon::parse('2026-04-15 15:00:00'),
        'start_datetime' => null,
    ]);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-04-15')
        ->call('refreshWorkspaceCalendar');

    expect($component->get('selectedDayAgenda.summary.tasks'))->toBeGreaterThan(0);
});

test('changing selected date keeps calendarGridMetaForJs populated for dots', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-13 12:00:00'));

    $user = User::factory()->create();
    $this->actingAs($user);

    Task::factory()->create([
        'user_id' => $user->id,
        'status' => TaskStatus::ToDo,
        'end_datetime' => Carbon::parse('2026-04-15 14:00:00'),
        'start_datetime' => null,
    ]);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-04-14')
        ->call('refreshWorkspaceCalendar');

    expect($component->get('calendarGridMetaForJs')['2026-04-15']['due_count'] ?? 0)->toBeGreaterThan(0);

    $component->set('selectedDate', '2026-04-15');

    expect($component->get('calendarGridMetaForJs')['2026-04-15']['due_count'] ?? 0)->toBeGreaterThan(0);
});

test('dashboard index can refresh workspace calendar', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-13 12:00:00'));

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::dashboard.index')
        ->set('selectedDate', '2026-04-13')
        ->call('refreshWorkspaceCalendar')
        ->assertOk();
});

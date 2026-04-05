<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('upcoming sidebar still lists events when item type filter is tasks only', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-10 12:00:00'));

    $user = User::factory()->create();

    Event::factory()->for($user)->create([
        'title' => 'UpcomingSidebarEventIndependent99',
        'description' => null,
        'start_datetime' => Carbon::parse('2026-06-12 10:00:00'),
        'end_datetime' => Carbon::parse('2026-06-12 11:00:00'),
        'status' => EventStatus::Scheduled,
        'all_day' => false,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-06-10')
        ->set('filterItemType', 'tasks')
        ->assertSee('UpcomingSidebarEventIndependent99');
});

test('upcoming sidebar still lists tasks when search does not match them', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-10 12:00:00'));

    $user = User::factory()->create();

    Task::factory()->for($user)->create([
        'title' => 'UpcomingSidebarTaskIndependent88',
        'description' => null,
        'start_datetime' => null,
        'end_datetime' => Carbon::parse('2026-06-11 15:00:00'),
    ]);

    $this->actingAs($user);

    Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-06-10')
        ->set('searchQuery', 'totallyDifferentSearchZZZ')
        ->assertSee('UpcomingSidebarTaskIndependent88');
});

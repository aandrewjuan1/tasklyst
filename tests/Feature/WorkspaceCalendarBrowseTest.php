<?php

use App\Models\User;
use Livewire\Livewire;

test('browsing the calendar month does not change selected date', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-06-15')
        ->call('browseCalendarMonth', 1);

    expect($component->get('selectedDate'))->toBe('2026-06-15')
        ->and($component->get('calendarViewMonth'))->toBe(7)
        ->and($component->get('calendarViewYear'))->toBe(2026);
});

test('calendar view is cleared when selected date changes', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-06-15')
        ->call('browseCalendarMonth', 1);

    expect($component->get('calendarViewMonth'))->not->toBeNull();

    $component->set('selectedDate', '2026-06-20');

    expect($component->get('calendarViewMonth'))->toBeNull()
        ->and($component->get('calendarViewYear'))->toBeNull();
});

test('browsing across January moves to December of the previous year', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-01-10')
        ->call('browseCalendarMonth', -1);

    expect($component->get('selectedDate'))->toBe('2026-01-10')
        ->and($component->get('calendarViewMonth'))->toBe(12)
        ->and($component->get('calendarViewYear'))->toBe(2025);
});

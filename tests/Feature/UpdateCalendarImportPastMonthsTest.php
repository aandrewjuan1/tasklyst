<?php

use App\Models\User;
use Livewire\Livewire;

it('persists calendar import past months for the authenticated user', function (): void {
    $user = User::factory()->create([
        'calendar_import_past_months' => null,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::workspace.index')
        ->call('updateCalendarImportPastMonths', 6);

    expect($user->fresh()->calendar_import_past_months)->toBe(6);
});

it('does not persist calendar import past months when out of range', function (): void {
    $user = User::factory()->create([
        'calendar_import_past_months' => 3,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::workspace.index')
        ->call('updateCalendarImportPastMonths', 4);

    expect($user->fresh()->calendar_import_past_months)->toBe(3);
});

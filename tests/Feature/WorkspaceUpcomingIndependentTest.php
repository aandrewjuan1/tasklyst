<?php

use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('workspace index renders successfully', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-10 12:00:00'));

    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::workspace.index')
        ->set('selectedDate', '2026-06-10')
        ->assertSuccessful();
});

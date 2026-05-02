<?php

use App\Models\User;
use Livewire\Livewire;

test('profile page is displayed', function () {
    // Disable middleware so the profile page loads without WorkOS session validation in tests.
    $this->withoutMiddleware();

    $this->actingAs($user = User::factory()->create());

    $this->get('/settings/profile')->assertOk();
});

test('profile page includes the mobile header notification bell', function () {
    $this->withoutMiddleware();

    $user = User::factory()->create();

    $html = (string) $this->actingAs($user)->get('/settings/profile')->assertOk()->getContent();

    expect($html)->toContain('data-test="notifications-bell-button"');
});

test('preferences page is displayed', function () {
    $this->withoutMiddleware();

    $this->actingAs($user = User::factory()->create());

    $this->get('/settings/preference')->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toEqual('Test User');
});

test('scheduler preferences can be updated from preferences settings', function () {
    $user = User::factory()->create([
        'timezone' => 'Asia/Manila',
        'schedule_preferences' => [
            'schema_version' => 1,
            'energy_bias' => 'balanced',
            'day_bounds' => ['start' => '08:00', 'end' => '22:00'],
            'lunch_block' => ['enabled' => true, 'start' => '12:00', 'end' => '13:00'],
        ],
    ]);

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.preference')
        ->set('dayBoundsStart', '11:00')
        ->set('dayBoundsEnd', '20:00')
        ->set('lunchBlockEnabled', false)
        ->set('lunchBlockStart', '12:30')
        ->set('lunchBlockEnd', '13:30')
        ->set('energyBias', 'evening')
        ->call('updatePreferences');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->schedule_preferences)->toMatchArray([
        'energy_bias' => 'evening',
        'day_bounds' => ['start' => '11:00', 'end' => '20:00'],
        'lunch_block' => ['enabled' => false, 'start' => '12:30', 'end' => '13:30'],
    ]);
});

test('scheduler preferences can save afternoon energy bias', function () {
    $user = User::factory()->create([
        'timezone' => 'Asia/Manila',
        'schedule_preferences' => [
            'schema_version' => 1,
            'energy_bias' => 'balanced',
            'day_bounds' => ['start' => '08:00', 'end' => '22:00'],
            'lunch_block' => ['enabled' => true, 'start' => '12:00', 'end' => '13:00'],
        ],
    ]);

    $this->actingAs($user);

    Livewire::test('pages::settings.preference')
        ->set('energyBias', 'afternoon')
        ->call('updatePreferences')
        ->assertHasNoErrors();

    $user->refresh();

    expect((string) ($user->schedule_preferences['energy_bias'] ?? ''))->toBe('afternoon');
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-form')
        ->call('deleteUser');

    $response->assertRedirect('/');

    expect($user->fresh())->toBeNull();
});

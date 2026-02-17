<?php

use App\Models\User;
use Livewire\Livewire;

test('profile page is displayed', function () {
    // Disable middleware so the profile page loads without WorkOS session validation in tests.
    $this->withoutMiddleware();

    $this->actingAs($user = User::factory()->create());

    $this->get('/settings/profile')->assertOk();
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

test('user can delete their account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-form')
        ->call('deleteUser');

    $response->assertRedirect('/');

    expect($user->fresh())->toBeNull();
});

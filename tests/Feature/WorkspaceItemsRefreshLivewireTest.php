<?php

use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('workspace items fingerprint changes when refreshing workspace items', function (): void {
    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index');
    $before = $component->instance()->workspaceItemsFingerprint();

    $component->call('refreshWorkspaceItems');

    $after = $component->instance()->workspaceItemsFingerprint();

    expect($before)->not->toBe($after);
});

test('workspace page includes notification bell strip markup', function (): void {
    $this->actingAs($this->user);

    $html = (string) $this->get(route('workspace'))->assertSuccessful()->getContent();

    expect($html)->toContain('data-test="notifications-bell-button"');
    expect(substr_count($html, 'data-test="notifications-bell-button"'))->toBe(1);
});

test('workspace hero clips the gradient in an inner layer so notification popovers are not clipped by overflow', function (): void {
    $this->actingAs($this->user);

    $html = (string) $this->get(route('workspace'))->getContent();

    expect($html)->toContain('pointer-events-none absolute inset-0 overflow-hidden rounded-2xl');
});

test('collaboration invitation accepted event bumps workspace items version without resetting pagination', function (): void {
    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index');
    $component->set('itemsPage', 3);

    $beforeVersion = $component->get('workspaceItemsVersion');
    $beforePage = $component->get('itemsPage');

    $component->dispatch('collaboration-invitation-accepted');

    expect($component->get('workspaceItemsVersion'))->toBe($beforeVersion + 1)
        ->and($component->get('itemsPage'))->toBe($beforePage);
});

test('collaboration invitation declined event bumps workspace items version without resetting pagination', function (): void {
    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index');
    $component->set('itemsPage', 2);

    $beforeVersion = $component->get('workspaceItemsVersion');
    $beforePage = $component->get('itemsPage');

    $component->dispatch('collaboration-invitation-declined');

    expect($component->get('workspaceItemsVersion'))->toBe($beforeVersion + 1)
        ->and($component->get('itemsPage'))->toBe($beforePage);
});

test('workspace trash restored event bumps workspace items version without resetting pagination', function (): void {
    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index');
    $component->set('itemsPage', 3);

    $beforeVersion = $component->get('workspaceItemsVersion');
    $beforePage = $component->get('itemsPage');

    $component->dispatch('workspace-trash-restored');

    expect($component->get('workspaceItemsVersion'))->toBe($beforeVersion + 1)
        ->and($component->get('itemsPage'))->toBe($beforePage);
});

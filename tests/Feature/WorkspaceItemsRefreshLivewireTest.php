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

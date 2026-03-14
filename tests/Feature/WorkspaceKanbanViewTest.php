<?php

use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('workspace with view kanban shows Kanban board columns', function (): void {
    $this->actingAs($this->user);

    Livewire::withQueryParams(['view' => 'kanban'])
        ->test('pages::workspace.index')
        ->assertSet('viewMode', 'kanban')
        ->assertSee(__('To Do'))
        ->assertSee(__('Doing'))
        ->assertSee(__('Done'))
        ->assertSee(__('Kanban'));
});

test('workspace view mode can be set to list and kanban', function (): void {
    $this->actingAs($this->user);

    Livewire::test('pages::workspace.index')
        ->assertSet('viewMode', 'list')
        ->set('viewMode', 'kanban')
        ->assertSet('viewMode', 'kanban')
        ->set('viewMode', 'list')
        ->assertSet('viewMode', 'list');
});

test('invalid view mode is normalized to list on mount', function (): void {
    $this->actingAs($this->user);

    Livewire::withQueryParams(['view' => 'invalid'])
        ->test('pages::workspace.index')
        ->assertSet('viewMode', 'list');
});

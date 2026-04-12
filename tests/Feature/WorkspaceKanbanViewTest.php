<?php

use App\Models\Task;
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
        ->assertSee(__('Kanban'))
        ->assertSee(__('No tasks in this column'))
        ->assertDontSee(__('No tasks, projects, or events in this column'));
});

test('creating a task while in kanban view shows it on the board', function (): void {
    $this->actingAs($this->user);

    Livewire::withQueryParams(['view' => 'kanban'])
        ->test('pages::workspace.index')
        ->assertSet('viewMode', 'kanban')
        ->call('createTask', ['title' => 'Kanban created task'])
        ->assertSee('Kanban created task');
});

test('kanban task cards use neutral zinc surface without status left border classes', function (): void {
    $this->actingAs($this->user);

    $html = Livewire::withQueryParams(['view' => 'kanban'])
        ->test('pages::workspace.index')
        ->call('createTask', ['title' => 'Kanban surface task'])
        ->html();

    $taskId = Task::query()->where('title', 'Kanban surface task')->value('id');
    expect($taskId)->not->toBeNull();

    expect(preg_match(
        '/id="workspace-item-task-'.preg_quote((string) $taskId, '/').'"[^>]*class="([^"]*)"/',
        $html,
        $matches
    ))->toBe(1);

    expect($matches[1])->toContain('lic-surface-zinc')
        ->not->toContain('lic-surface-task-todo')
        ->not->toContain('lic-surface-task-doing')
        ->not->toContain('lic-surface-task-done');

    expect($html)->toContain('lic-item-type-pill--task');
});

test('kanban view add control is task-only (no event or project options)', function (): void {
    $this->actingAs($this->user);

    $this->get(route('workspace', ['view' => 'kanban']))
        ->assertSuccessful()
        ->assertDontSee('calendar-days');
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

test('workspace view mode tab buttons include selected classes on first paint', function (): void {
    $this->actingAs($this->user);

    $listHtml = $this->get(route('workspace', ['view' => 'list']))->assertSuccessful()->getContent();

    preg_match('/id="workspace-view-list"[\s\S]*?class="([^"]*)"/', $listHtml, $listTabClass);
    preg_match('/id="workspace-view-kanban"[\s\S]*?class="([^"]*)"/', $listHtml, $kanbanTabClass);

    expect($listTabClass[1] ?? '')->toContain('bg-brand-blue')
        ->not->toContain('text-muted-foreground');
    expect($kanbanTabClass[1] ?? '')->toContain('text-muted-foreground')
        ->not->toContain('bg-brand-blue');

    $kanbanHtml = $this->get(route('workspace', ['view' => 'kanban']))->assertSuccessful()->getContent();

    preg_match('/id="workspace-view-list"[\s\S]*?class="([^"]*)"/', $kanbanHtml, $listTabClassK);
    preg_match('/id="workspace-view-kanban"[\s\S]*?class="([^"]*)"/', $kanbanHtml, $kanbanTabClassK);

    expect($listTabClassK[1] ?? '')->toContain('text-muted-foreground')
        ->not->toContain('bg-brand-blue');
    expect($kanbanTabClassK[1] ?? '')->toContain('bg-brand-blue')
        ->not->toContain('text-muted-foreground');
});

test('workspace list view mounts only the nested list livewire component', function (): void {
    $this->actingAs($this->user);

    $this->get(route('workspace', ['view' => 'list']))
        ->assertSuccessful()
        ->assertSee('wire:key="workspace-list-', false)
        ->assertDontSee('wire:key="workspace-kanban-', false)
        ->assertDontSee('data-kanban-column', false)
        ->assertDontSee(__('Kanban board'), false);
});

test('workspace kanban view mounts only the nested kanban livewire component', function (): void {
    $this->actingAs($this->user);

    $this->get(route('workspace', ['view' => 'kanban']))
        ->assertSuccessful()
        ->assertSee('wire:key="workspace-kanban-', false)
        ->assertSee('data-kanban-column', false)
        ->assertSee(__('Kanban board'), false)
        ->assertDontSee('wire:key="workspace-list-', false);
});

test('kanban column shells allow overflow so anchored dropdowns are not clipped', function (): void {
    $this->actingAs($this->user);

    $html = $this->get(route('workspace', ['view' => 'kanban']))
        ->assertSuccessful()
        ->getContent();

    preg_match_all('/<div\b[^>]*\bdata-kanban-column\b[^>]*>/i', $html, $matches);
    expect($matches[0])->not->toBeEmpty();

    foreach ($matches[0] as $tag) {
        expect($tag)->toContain('overflow-visible')
            ->and($tag)->not->toContain('overflow-hidden');
    }
});

test('switching view mode from list to kanban renders kanban child', function (): void {
    $this->actingAs($this->user);

    Livewire::test('pages::workspace.index')
        ->assertSet('viewMode', 'list')
        ->set('viewMode', 'kanban')
        ->assertSee('wire:key="workspace-kanban-', false)
        ->assertSee('data-kanban-column', false)
        ->assertSee(__('Kanban board'), false)
        ->assertDontSee('wire:key="workspace-list-', false);
});

test('setFilter updates workspace state without requiring list remount counter', function (): void {
    $this->actingAs($this->user);

    Livewire::test('pages::workspace.index')
        ->call('setFilter', 'itemType', 'tasks')
        ->assertSet('filterItemType', 'tasks');
});

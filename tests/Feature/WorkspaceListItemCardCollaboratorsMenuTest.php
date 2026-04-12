<?php

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Livewire::flushState();
});

test('workspace list owner task card ellipsis dispatches workspace-open-collaborators for that item', function (): void {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create([
        'title' => 'Collaborators menu task',
        'status' => TaskStatus::ToDo,
        'end_datetime' => now()->addDay(),
        'completed_at' => null,
    ]);

    $html = Livewire::actingAs($user)
        ->withQueryParams([
            'date' => now()->toDateString(),
            'view' => 'list',
            'task' => (string) $task->id,
        ])
        ->test('pages::workspace.index')
        ->assertSet('filterItemType', 'tasks')
        ->html();

    expect($html)->toContain('workspace-open-collaborators')
        ->and($html)->toContain('$dispatch(\'workspace-open-collaborators\', { id: '.$task->id.', kind: \'task\' })');
});

test('workspace kanban owner task card ellipsis dispatches workspace-open-collaborators for that item', function (): void {
    $user = User::factory()->create();

    $html = Livewire::actingAs($user)
        ->withQueryParams(['view' => 'kanban'])
        ->test('pages::workspace.index')
        ->assertSet('viewMode', 'kanban')
        ->call('createTask', ['title' => 'Kanban collaborators menu task'])
        ->html();

    $taskId = Task::query()->where('title', 'Kanban collaborators menu task')->value('id');
    expect($taskId)->not->toBeNull();

    expect($html)->toContain('workspace-open-collaborators')
        ->and($html)->toContain('$dispatch(\'workspace-open-collaborators\', { id: '.$taskId.', kind: \'task\' })');
});

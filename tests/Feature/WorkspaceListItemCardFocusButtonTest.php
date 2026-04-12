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

test('workspace list renders a single focus trigger per task card in the header', function (): void {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create([
        'title' => 'Focus Button Task',
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

    expect(substr_count($html, 'id="workspace-item-task-'.$task->id.'"'))->toBe(1)
        ->and(substr_count($html, 'x-ref="focusTrigger"'))->toBe(1)
        ->and($html)->toContain('workspace-focus-trigger')
        ->and($html)->toContain('lic-item-type-pill--task');
});

<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
});

test('unauthenticated restoreTrashItems does not restore and does not toast success', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $task->delete();

    Livewire::test('pages::workspace.index')
        ->call('restoreTrashItems', [['kind' => 'task', 'id' => $task->id]]);

    expect($task->refresh()->trashed())->toBeTrue();
});

test('unauthenticated forceDeleteTrashItems does not delete', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $taskId = $task->id;
    $task->delete();

    Livewire::test('pages::workspace.index')
        ->call('forceDeleteTrashItems', [['kind' => 'task', 'id' => $taskId]]);

    expect(Task::withTrashed()->find($taskId))->not->toBeNull();
});

test('authenticated restoreTrashItems with empty array does not restore any item', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    $task->delete();

    Livewire::test('pages::workspace.index')
        ->call('restoreTrashItems', []);

    expect($task->refresh()->trashed())->toBeTrue();
});

test('authenticated restoreTrashItems restores one trashed task', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create(['title' => 'Trashed task']);
    $task->delete();
    expect($task->refresh()->trashed())->toBeTrue();

    Livewire::test('pages::workspace.index')
        ->call('restoreTrashItems', [['kind' => 'task', 'id' => $task->id]]);

    expect($task->refresh()->trashed())->toBeFalse();
});

test('authenticated forceDeleteTrashItems permanently deletes one trashed task', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create(['title' => 'To remove']);
    $taskId = $task->id;
    $task->delete();
    expect(Task::withTrashed()->find($taskId))->not->toBeNull();

    Livewire::test('pages::workspace.index')
        ->call('forceDeleteTrashItems', [['kind' => 'task', 'id' => $taskId]]);

    expect(Task::withTrashed()->find($taskId))->toBeNull();
});

test('restoreTrashItems restores multiple trashed tasks', function (): void {
    $this->actingAs($this->owner);
    $t1 = Task::factory()->for($this->owner)->create();
    $t2 = Task::factory()->for($this->owner)->create();
    $t1->delete();
    $t2->delete();

    Livewire::test('pages::workspace.index')
        ->call('restoreTrashItems', [
            ['kind' => 'task', 'id' => $t1->id],
            ['kind' => 'task', 'id' => $t2->id],
        ]);

    expect($t1->refresh()->trashed())->toBeFalse()
        ->and($t2->refresh()->trashed())->toBeFalse();
});

test('restoreTrashItems with mixed kinds restores task and project', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    $project = Project::factory()->for($this->owner)->create();
    $task->delete();
    $project->delete();

    Livewire::test('pages::workspace.index')
        ->call('restoreTrashItems', [
            ['kind' => 'task', 'id' => $task->id],
            ['kind' => 'project', 'id' => $project->id],
        ]);

    expect($task->refresh()->trashed())->toBeFalse()
        ->and($project->refresh()->trashed())->toBeFalse();
});

test('restoreTrashItems ignores invalid kind and deduplicates', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create();
    $task->delete();

    Livewire::test('pages::workspace.index')
        ->call('restoreTrashItems', [
            ['kind' => 'invalid', 'id' => 1],
            ['kind' => 'task', 'id' => $task->id],
            ['kind' => 'task', 'id' => $task->id],
        ]);

    expect($task->refresh()->trashed())->toBeFalse();
});

test('unauthenticated forceDeleteAllTrashItems does not delete any item', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $taskId = $task->id;
    $task->delete();

    Livewire::test('pages::workspace.index')
        ->call('forceDeleteAllTrashItems');

    expect(Task::withTrashed()->find($taskId))->not->toBeNull();
});

test('authenticated forceDeleteAllTrashItems permanently deletes all trashed items', function (): void {
    $this->actingAs($this->owner);
    $t1 = Task::factory()->for($this->owner)->create();
    $t2 = Task::factory()->for($this->owner)->create();
    $t1->delete();
    $t2->delete();

    Livewire::test('pages::workspace.index')
        ->call('forceDeleteAllTrashItems');

    expect(Task::withTrashed()->find($t1->id))->toBeNull()
        ->and(Task::withTrashed()->find($t2->id))->toBeNull();
});

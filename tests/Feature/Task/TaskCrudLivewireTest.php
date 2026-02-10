<?php

use App\Enums\CollaborationPermission;
use App\Enums\TaskStatus;
use App\Models\Collaboration;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Support\Validation\TaskPayloadValidation;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->collaboratorWithEdit = User::factory()->create();
    $this->collaboratorWithView = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('create task with valid payload creates task in database', function (): void {
    $this->actingAs($this->owner);

    Livewire::test('pages::workspace.index')
        ->call('createTask', [
            'title' => 'Livewire created task',
        ]);

    $task = Task::query()->where('user_id', $this->owner->id)->where('title', 'Livewire created task')->first();
    expect($task)->not->toBeNull()
        ->and($task->user_id)->toBe($this->owner->id);
});

test('create task with empty title does not create task', function (): void {
    $this->actingAs($this->owner);
    $payload = array_replace_recursive(TaskPayloadValidation::defaults(), ['title' => '']);

    Livewire::test('pages::workspace.index')
        ->call('createTask', $payload);

    $count = Task::query()->where('user_id', $this->owner->id)->count();
    expect($count)->toBe(0);
});

test('create task with project id creates task linked to user project', function (): void {
    $this->actingAs($this->owner);
    $project = Project::factory()->for($this->owner)->create();

    Livewire::test('pages::workspace.index')
        ->call('createTask', [
            'title' => 'Task with project',
            'projectId' => $project->id,
        ]);

    $task = Task::query()->where('user_id', $this->owner->id)->where('title', 'Task with project')->first();
    expect($task)->not->toBeNull()
        ->and($task->project_id)->toBe($project->id);
});

test('create task with tag ids attaches tags', function (): void {
    $this->actingAs($this->owner);
    $tag = Tag::factory()->for($this->owner)->create();

    Livewire::test('pages::workspace.index')
        ->call('createTask', [
            'title' => 'Task with tag',
            'tagIds' => [$tag->id],
        ]);

    $task = Task::query()->where('user_id', $this->owner->id)->where('title', 'Task with tag')->first();
    expect($task)->not->toBeNull();
    $task->load('tags');
    expect($task->tags->pluck('id')->toArray())->toContain($tag->id);
});

test('owner can delete task and task is soft deleted', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create(['title' => 'To delete']);

    Livewire::test('pages::workspace.index')
        ->call('deleteTask', $task->id);

    $task->refresh();
    expect($task->trashed())->toBeTrue();
});

test('delete task with non existent id does not delete any task', function (): void {
    $this->actingAs($this->owner);
    $countBefore = Task::query()->count();

    Livewire::test('pages::workspace.index')
        ->call('deleteTask', 99999);

    expect(Task::query()->count())->toBe($countBefore);
});

test('collaborator with edit permission cannot delete task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaboratorWithEdit->id,
        'permission' => CollaborationPermission::Edit,
    ]);
    $this->actingAs($this->collaboratorWithEdit);

    Livewire::test('pages::workspace.index')
        ->call('deleteTask', $task->id)
        ->assertForbidden();

    expect($task->fresh()->trashed())->toBeFalse();
});

test('other user cannot delete task not shared with them', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    $this->actingAs($this->otherUser);

    Livewire::test('pages::workspace.index')
        ->call('deleteTask', $task->id);

    expect($task->fresh()->trashed())->toBeFalse();
});

test('owner can update task property title', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create(['title' => 'Original title']);

    Livewire::test('pages::workspace.index')
        ->call('updateTaskProperty', $task->id, 'title', 'Updated title');

    expect($task->fresh()->title)->toBe('Updated title');
});

test('owner can update task property status', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create(['status' => TaskStatus::ToDo]);

    Livewire::test('pages::workspace.index')
        ->call('updateTaskProperty', $task->id, 'status', TaskStatus::Doing->value);

    expect($task->fresh()->status)->toBe(TaskStatus::Doing);
});

test('update task property with invalid property name does not update task', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create(['title' => 'Unchanged']);

    Livewire::test('pages::workspace.index')
        ->call('updateTaskProperty', $task->id, 'invalidProperty', 'value');

    expect($task->fresh()->title)->toBe('Unchanged');
});

test('update task property with empty title does not update task', function (): void {
    $this->actingAs($this->owner);
    $task = Task::factory()->for($this->owner)->create(['title' => 'Original']);

    Livewire::test('pages::workspace.index')
        ->call('updateTaskProperty', $task->id, 'title', '   ');

    expect($task->fresh()->title)->toBe('Original');
});

test('collaborator with edit permission can update task property', function (): void {
    $task = Task::factory()->for($this->owner)->create(['title' => 'Shared task']);
    Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaboratorWithEdit->id,
        'permission' => CollaborationPermission::Edit,
    ]);
    $this->actingAs($this->collaboratorWithEdit);

    Livewire::test('pages::workspace.index')
        ->call('updateTaskProperty', $task->id, 'title', 'Updated by collaborator');

    expect($task->fresh()->title)->toBe('Updated by collaborator');
});

test('collaborator with view only permission cannot update task property', function (): void {
    $task = Task::factory()->for($this->owner)->create(['title' => 'View only task']);
    Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaboratorWithView->id,
        'permission' => CollaborationPermission::View,
    ]);
    $this->actingAs($this->collaboratorWithView);

    Livewire::test('pages::workspace.index')
        ->call('updateTaskProperty', $task->id, 'title', 'Should not update')
        ->assertForbidden();

    expect($task->fresh()->title)->toBe('View only task');
});

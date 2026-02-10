<?php

use App\Enums\CollaborationPermission;
use App\Models\Collaboration;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->collaboratorWithEdit = User::factory()->create();
    $this->collaboratorWithView = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('view any and create allow any authenticated user', function (): void {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('viewAny', Task::class))->toBeTrue()
        ->and(Gate::forUser($user)->allows('create', Task::class))->toBeTrue();
});

test('owner can view task', function (): void {
    $task = Task::factory()->for($this->owner)->create();

    expect($this->owner->can('view', $task))->toBeTrue();
});

test('collaborator with edit permission can view task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaboratorWithEdit->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    expect($this->collaboratorWithEdit->can('view', $task))->toBeTrue();
});

test('collaborator with view permission can view task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaboratorWithView->id,
        'permission' => CollaborationPermission::View,
    ]);

    expect($this->collaboratorWithView->can('view', $task))->toBeTrue();
});

test('other user cannot view task', function (): void {
    $task = Task::factory()->for($this->owner)->create();

    expect($this->otherUser->can('view', $task))->toBeFalse();
});

test('owner can update task', function (): void {
    $task = Task::factory()->for($this->owner)->create();

    expect($this->owner->can('update', $task))->toBeTrue();
});

test('collaborator with edit permission can update task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaboratorWithEdit->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    expect($this->collaboratorWithEdit->can('update', $task))->toBeTrue();
});

test('collaborator with view permission cannot update task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaboratorWithView->id,
        'permission' => CollaborationPermission::View,
    ]);

    expect($this->collaboratorWithView->can('update', $task))->toBeFalse();
});

test('other user cannot update task', function (): void {
    $task = Task::factory()->for($this->owner)->create();

    expect($this->otherUser->can('update', $task))->toBeFalse();
});

test('owner can delete task', function (): void {
    $task = Task::factory()->for($this->owner)->create();

    expect($this->owner->can('delete', $task))->toBeTrue();
});

test('collaborator cannot delete task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaboratorWithEdit->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    expect($this->collaboratorWithEdit->can('delete', $task))->toBeFalse();
});

test('other user cannot delete task', function (): void {
    $task = Task::factory()->for($this->owner)->create();

    expect($this->otherUser->can('delete', $task))->toBeFalse();
});

test('owner can restore and force delete task', function (): void {
    $task = Task::factory()->for($this->owner)->create();

    expect($this->owner->can('restore', $task))->toBeTrue()
        ->and($this->owner->can('forceDelete', $task))->toBeTrue();
});

test('collaborator cannot restore or force delete task', function (): void {
    $task = Task::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => $task->id,
        'user_id' => $this->collaboratorWithEdit->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    expect($this->collaboratorWithEdit->can('restore', $task))->toBeFalse()
        ->and($this->collaboratorWithEdit->can('forceDelete', $task))->toBeFalse();
});

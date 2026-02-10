<?php

use App\Enums\CollaborationPermission;
use App\Models\Collaboration;
use App\Models\Project;
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

    expect(Gate::forUser($user)->allows('viewAny', Project::class))->toBeTrue()
        ->and(Gate::forUser($user)->allows('create', Project::class))->toBeTrue();
});

test('owner can view project', function (): void {
    $project = Project::factory()->for($this->owner)->create();

    expect($this->owner->can('view', $project))->toBeTrue();
});

test('collaborator with edit permission can view project', function (): void {
    $project = Project::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Project::class,
        'collaboratable_id' => $project->id,
        'user_id' => $this->collaboratorWithEdit->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    expect($this->collaboratorWithEdit->can('view', $project))->toBeTrue();
});

test('collaborator with view permission can view project', function (): void {
    $project = Project::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Project::class,
        'collaboratable_id' => $project->id,
        'user_id' => $this->collaboratorWithView->id,
        'permission' => CollaborationPermission::View,
    ]);

    expect($this->collaboratorWithView->can('view', $project))->toBeTrue();
});

test('other user cannot view project', function (): void {
    $project = Project::factory()->for($this->owner)->create();

    expect($this->otherUser->can('view', $project))->toBeFalse();
});

test('owner can update project', function (): void {
    $project = Project::factory()->for($this->owner)->create();

    expect($this->owner->can('update', $project))->toBeTrue();
});

test('collaborator with edit permission can update project', function (): void {
    $project = Project::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Project::class,
        'collaboratable_id' => $project->id,
        'user_id' => $this->collaboratorWithEdit->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    expect($this->collaboratorWithEdit->can('update', $project))->toBeTrue();
});

test('collaborator with view permission cannot update project', function (): void {
    $project = Project::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Project::class,
        'collaboratable_id' => $project->id,
        'user_id' => $this->collaboratorWithView->id,
        'permission' => CollaborationPermission::View,
    ]);

    expect($this->collaboratorWithView->can('update', $project))->toBeFalse();
});

test('other user cannot update project', function (): void {
    $project = Project::factory()->for($this->owner)->create();

    expect($this->otherUser->can('update', $project))->toBeFalse();
});

test('owner can delete project', function (): void {
    $project = Project::factory()->for($this->owner)->create();

    expect($this->owner->can('delete', $project))->toBeTrue();
});

test('collaborator cannot delete project', function (): void {
    $project = Project::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Project::class,
        'collaboratable_id' => $project->id,
        'user_id' => $this->collaboratorWithEdit->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    expect($this->collaboratorWithEdit->can('delete', $project))->toBeFalse();
});

test('other user cannot delete project', function (): void {
    $project = Project::factory()->for($this->owner)->create();

    expect($this->otherUser->can('delete', $project))->toBeFalse();
});

test('owner can restore and force delete project', function (): void {
    $project = Project::factory()->for($this->owner)->create();

    expect($this->owner->can('restore', $project))->toBeTrue()
        ->and($this->owner->can('forceDelete', $project))->toBeTrue();
});

test('collaborator cannot restore or force delete project', function (): void {
    $project = Project::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Project::class,
        'collaboratable_id' => $project->id,
        'user_id' => $this->collaboratorWithEdit->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    expect($this->collaboratorWithEdit->can('restore', $project))->toBeFalse()
        ->and($this->collaboratorWithEdit->can('forceDelete', $project))->toBeFalse();
});

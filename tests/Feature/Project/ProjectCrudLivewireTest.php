<?php

use App\Enums\CollaborationPermission;
use App\Models\Collaboration;
use App\Models\Project;
use App\Models\User;
use App\Support\Validation\ProjectPayloadValidation;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->collaboratorWithEdit = User::factory()->create();
    $this->collaboratorWithView = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('create project with valid payload creates project in database', function (): void {
    $this->actingAs($this->owner);

    Livewire::test('pages::workspace.index')
        ->call('createProject', [
            'name' => 'Livewire created project',
        ]);

    $project = Project::query()->where('user_id', $this->owner->id)->where('name', 'Livewire created project')->first();
    expect($project)->not->toBeNull()
        ->and($project->user_id)->toBe($this->owner->id);
});

test('create project with empty name does not create project', function (): void {
    $this->actingAs($this->owner);
    $payload = array_replace_recursive(ProjectPayloadValidation::defaults(), ['name' => '']);

    Livewire::test('pages::workspace.index')
        ->call('createProject', $payload);

    $count = Project::query()->where('user_id', $this->owner->id)->count();
    expect($count)->toBe(0);
});

test('owner can delete project and project is soft deleted', function (): void {
    $this->actingAs($this->owner);
    $project = Project::factory()->for($this->owner)->create(['name' => 'To delete']);

    Livewire::test('pages::workspace.index')
        ->call('deleteProject', $project->id);

    $project->refresh();
    expect($project->trashed())->toBeTrue();
});

test('delete project with non existent id does not delete any project', function (): void {
    $this->actingAs($this->owner);
    $countBefore = Project::query()->count();

    Livewire::test('pages::workspace.index')
        ->call('deleteProject', 99999);

    expect(Project::query()->count())->toBe($countBefore);
});

test('collaborator with edit permission cannot delete project', function (): void {
    $project = Project::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Project::class,
        'collaboratable_id' => $project->id,
        'user_id' => $this->collaboratorWithEdit->id,
        'permission' => CollaborationPermission::Edit,
    ]);
    $this->actingAs($this->collaboratorWithEdit);

    Livewire::test('pages::workspace.index')
        ->call('deleteProject', $project->id)
        ->assertDispatched('toast', type: 'error', message: __('Only the owner can delete this project.'));

    expect($project->fresh()->trashed())->toBeFalse();
});

test('other user cannot delete project not shared with them', function (): void {
    $project = Project::factory()->for($this->owner)->create();
    $this->actingAs($this->otherUser);

    Livewire::test('pages::workspace.index')
        ->call('deleteProject', $project->id);

    expect($project->fresh()->trashed())->toBeFalse();
});

test('owner can update project property name', function (): void {
    $this->actingAs($this->owner);
    $project = Project::factory()->for($this->owner)->create(['name' => 'Original name']);

    Livewire::test('pages::workspace.index')
        ->call('updateProjectProperty', $project->id, 'name', 'Updated name');

    expect($project->fresh()->name)->toBe('Updated name');
});

test('owner can update project property description', function (): void {
    $this->actingAs($this->owner);
    $project = Project::factory()->for($this->owner)->create(['description' => null]);

    Livewire::test('pages::workspace.index')
        ->call('updateProjectProperty', $project->id, 'description', 'New description');

    expect($project->fresh()->description)->toBe('New description');
});

test('update project property with invalid property name does not update project', function (): void {
    $this->actingAs($this->owner);
    $project = Project::factory()->for($this->owner)->create(['name' => 'Unchanged']);

    Livewire::test('pages::workspace.index')
        ->call('updateProjectProperty', $project->id, 'invalidProperty', 'value');

    expect($project->fresh()->name)->toBe('Unchanged');
});

test('update project property with empty name does not update project', function (): void {
    $this->actingAs($this->owner);
    $project = Project::factory()->for($this->owner)->create(['name' => 'Original']);

    Livewire::test('pages::workspace.index')
        ->call('updateProjectProperty', $project->id, 'name', '   ');

    expect($project->fresh()->name)->toBe('Original');
});

test('collaborator with edit permission can update project property', function (): void {
    $project = Project::factory()->for($this->owner)->create(['name' => 'Shared project']);
    Collaboration::create([
        'collaboratable_type' => Project::class,
        'collaboratable_id' => $project->id,
        'user_id' => $this->collaboratorWithEdit->id,
        'permission' => CollaborationPermission::Edit,
    ]);
    $this->actingAs($this->collaboratorWithEdit);

    Livewire::test('pages::workspace.index')
        ->call('updateProjectProperty', $project->id, 'name', 'Updated by collaborator');

    expect($project->fresh()->name)->toBe('Updated by collaborator');
});

test('collaborator with view only permission cannot update project property', function (): void {
    $project = Project::factory()->for($this->owner)->create(['name' => 'View only project']);
    Collaboration::create([
        'collaboratable_type' => Project::class,
        'collaboratable_id' => $project->id,
        'user_id' => $this->collaboratorWithView->id,
        'permission' => CollaborationPermission::View,
    ]);
    $this->actingAs($this->collaboratorWithView);

    Livewire::test('pages::workspace.index')
        ->call('updateProjectProperty', $project->id, 'name', 'Should not update')
        ->assertForbidden();

    expect($project->fresh()->name)->toBe('View only project');
});

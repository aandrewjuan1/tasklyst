<?php

use App\Enums\CollaborationPermission;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Carbon\Carbon;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->service = app(ProjectService::class);
});

test('create project sets user_id and minimal attributes', function (): void {
    $project = $this->service->createProject($this->user, [
        'name' => 'Minimal project',
    ]);

    expect($project)->toBeInstanceOf(Project::class)
        ->and($project->user_id)->toBe($this->user->id)
        ->and($project->name)->toBe('Minimal project')
        ->and($project->exists)->toBeTrue();
});

test('create project with description and dates sets attributes', function (): void {
    $start = Carbon::parse('2025-02-10 09:00');
    $end = Carbon::parse('2025-02-15 17:00');

    $project = $this->service->createProject($this->user, [
        'name' => 'Full project',
        'description' => 'A description',
        'start_datetime' => $start,
        'end_datetime' => $end,
    ]);

    expect($project->name)->toBe('Full project')
        ->and($project->description)->toBe('A description')
        ->and($project->start_datetime->format('Y-m-d H:i'))->toBe($start->format('Y-m-d H:i'))
        ->and($project->end_datetime->format('Y-m-d H:i'))->toBe($end->format('Y-m-d H:i'));
});

test('update project updates attributes', function (): void {
    $project = Project::factory()->for($this->user)->create(['name' => 'Original']);

    $updated = $this->service->updateProject($project, ['name' => 'Updated name']);

    expect($updated->name)->toBe('Updated name')
        ->and($project->fresh()->name)->toBe('Updated name');
});

test('update project does not allow changing user_id', function (): void {
    $project = Project::factory()->for($this->user)->create();
    $otherUser = User::factory()->create();

    $this->service->updateProject($project, ['user_id' => $otherUser->id]);

    expect($project->fresh()->user_id)->toBe($this->user->id);
});

test('delete project soft deletes and boot removes related records', function (): void {
    $project = Project::factory()->for($this->user)->create();
    $collab = $project->collaborations()->create([
        'user_id' => User::factory()->create()->id,
        'permission' => CollaborationPermission::Edit,
    ]);
    $invitation = $project->collaborationInvitations()->create([
        'inviter_id' => $this->user->id,
        'invitee_email' => 'a@b.com',
        'permission' => CollaborationPermission::View,
        'status' => 'pending',
        'token' => \Illuminate\Support\Str::random(32),
    ]);

    $result = $this->service->deleteProject($project);

    expect($result)->toBeTrue();
    expect(Project::withTrashed()->find($project->id))->not->toBeNull()
        ->and(Project::withTrashed()->find($project->id)->trashed())->toBeTrue();
    expect($collab->fresh())->toBeNull();
    expect($invitation->fresh())->toBeNull();
});

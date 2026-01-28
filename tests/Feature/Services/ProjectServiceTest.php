<?php

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertSoftDeleted;

it('creates updates and deletes a project', function (): void {
    $user = User::factory()->create();

    $project = app(ProjectService::class)->createProject($user, [
        'name' => 'Before',
    ]);

    expect($project)->toBeInstanceOf(Project::class);
    expect($project->user_id)->toBe($user->id);

    $updated = app(ProjectService::class)->updateProject($project, [
        'name' => 'After',
        'user_id' => User::factory()->create()->id,
    ]);

    expect($updated->name)->toBe('After');
    expect($updated->user_id)->toBe($user->id);

    assertDatabaseHas('projects', [
        'id' => $project->id,
        'user_id' => $user->id,
        'name' => 'After',
    ]);

    $deleted = app(ProjectService::class)->deleteProject($project);
    expect($deleted)->toBeTrue();

    assertSoftDeleted('projects', [
        'id' => $project->id,
    ]);
});

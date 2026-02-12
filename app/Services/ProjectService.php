<?php

namespace App\Services;

use App\Enums\ActivityLogAction;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProjectService
{
    public function __construct(
        private ActivityLogRecorder $activityLogRecorder
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createProject(User $user, array $attributes): Project
    {
        return DB::transaction(function () use ($user, $attributes): Project {
            $project = Project::query()->create([
                ...$attributes,
                'user_id' => $user->id,
            ]);

            $this->activityLogRecorder->record($project, $user, ActivityLogAction::ItemCreated, [
                'name' => $project->name,
            ]);

            return $project;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateProject(Project $project, array $attributes): Project
    {
        unset($attributes['user_id']);

        return DB::transaction(function () use ($project, $attributes): Project {
            $project->fill($attributes);
            $project->save();

            return $project;
        });
    }

    public function deleteProject(Project $project, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($project, $actor): bool {
            $this->activityLogRecorder->record($project, $actor, ActivityLogAction::ItemDeleted, [
                'name' => $project->name,
            ]);

            return (bool) $project->delete();
        });
    }
}

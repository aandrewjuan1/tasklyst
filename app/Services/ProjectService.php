<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProjectService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createProject(User $user, array $attributes): Project
    {
        return DB::transaction(function () use ($user, $attributes): Project {
            return Project::query()->create([
                ...$attributes,
                'user_id' => $user->id,
            ]);
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

    public function deleteProject(Project $project): bool
    {
        return DB::transaction(function () use ($project): bool {
            return (bool) $project->delete();
        });
    }
}

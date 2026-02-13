<?php

namespace App\Actions\Project;

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;

class RestoreProjectAction
{
    public function __construct(
        private ProjectService $projectService
    ) {}

    public function execute(Project $project, ?User $actor = null): bool
    {
        return $this->projectService->restoreProject($project, $actor);
    }
}

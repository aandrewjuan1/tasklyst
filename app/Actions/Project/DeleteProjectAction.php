<?php

namespace App\Actions\Project;

use App\Models\Project;
use App\Services\ProjectService;

class DeleteProjectAction
{
    public function __construct(
        private ProjectService $projectService
    ) {}

    public function execute(Project $project): bool
    {
        return $this->projectService->deleteProject($project);
    }
}

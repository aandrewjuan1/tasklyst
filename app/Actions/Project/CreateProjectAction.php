<?php

namespace App\Actions\Project;

use App\DataTransferObjects\Project\CreateProjectDto;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;

class CreateProjectAction
{
    public function __construct(
        private ProjectService $projectService
    ) {}

    public function execute(User $user, CreateProjectDto $dto): Project
    {
        return $this->projectService->createProject($user, $dto->toServiceAttributes());
    }
}

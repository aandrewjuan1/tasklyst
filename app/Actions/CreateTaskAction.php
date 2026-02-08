<?php

namespace App\Actions;

use App\DataTransferObjects\Task\CreateTaskDto;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;

class CreateTaskAction
{
    public function __construct(
        private TaskService $taskService
    ) {}

    public function execute(User $user, CreateTaskDto $dto): Task
    {
        return $this->taskService->createTask($user, $dto->toServiceAttributes());
    }
}

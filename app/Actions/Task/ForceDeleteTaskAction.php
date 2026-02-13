<?php

namespace App\Actions\Task;

use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;

class ForceDeleteTaskAction
{
    public function __construct(
        private TaskService $taskService
    ) {}

    public function execute(Task $task, ?User $actor = null): bool
    {
        return $this->taskService->forceDeleteTask($task, $actor);
    }
}

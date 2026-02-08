<?php

namespace App\Actions\Task;

use App\Models\Task;
use App\Services\TaskService;

class DeleteTaskAction
{
    public function __construct(
        private TaskService $taskService
    ) {}

    public function execute(Task $task): bool
    {
        return $this->taskService->deleteTask($task);
    }
}

<?php

namespace App\Actions\Task;

use App\Models\TaskException;
use App\Services\TaskService;

class DeleteTaskExceptionAction
{
    public function __construct(
        private TaskService $taskService
    ) {}

    public function execute(TaskException $exception): bool
    {
        return $this->taskService->deleteTaskException($exception);
    }
}

<?php

namespace App\Actions\Task;

use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;

class DeleteTaskAction
{
    public function __construct(
        private TaskService $taskService
    ) {}

    public function execute(Task $task, ?User $actor = null): bool
    {
        return $this->taskService->deleteTask($task, $actor)['success'];
    }

    /**
     * @return array{success: bool, abandoned_in_progress_focus_session: bool}
     */
    public function executeWithFocusMeta(Task $task, ?User $actor = null): array
    {
        return $this->taskService->deleteTask($task, $actor);
    }
}

<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\TaskException;
use App\Models\User;

class TaskExceptionPolicy
{
    /**
     * Determine whether the user can view any task exceptions.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the task exception.
     */
    public function view(User $user, TaskException $taskException): bool
    {
        $task = $this->resolveTask($taskException);

        return $task !== null && $user->can('update', $task);
    }

    /**
     * Determine whether the user can update the task exception.
     */
    public function update(User $user, TaskException $taskException): bool
    {
        $task = $this->resolveTask($taskException);

        return $task !== null && $user->can('update', $task);
    }

    /**
     * Determine whether the user can delete the task exception.
     */
    public function delete(User $user, TaskException $taskException): bool
    {
        $task = $this->resolveTask($taskException);

        return $task !== null && $user->can('update', $task);
    }

    protected function resolveTask(TaskException $taskException): ?Task
    {
        $taskException->loadMissing('recurringTask.task');

        return $taskException->recurringTask?->task;
    }
}

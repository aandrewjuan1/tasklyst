<?php

namespace App\Actions\Task;

use App\DataTransferObjects\Task\CreateTaskExceptionDto;
use App\Models\RecurringTask;
use App\Models\TaskException;
use App\Models\TaskInstance;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Support\Carbon;

class CreateTaskExceptionAction
{
    public function __construct(
        private TaskService $taskService
    ) {}

    public function execute(User $user, CreateTaskExceptionDto $dto): TaskException
    {
        $recurringTask = RecurringTask::query()->findOrFail($dto->recurringTaskId);
        $date = Carbon::parse($dto->exceptionDate);

        $replacement = null;
        if ($dto->replacementInstanceId !== null) {
            $replacement = TaskInstance::query()
                ->where('recurring_task_id', $recurringTask->id)
                ->findOrFail($dto->replacementInstanceId);
        }

        return $this->taskService->createTaskException(
            $recurringTask,
            $date,
            $dto->isDeleted,
            $replacement,
            $user,
            $dto->reason
        );
    }
}

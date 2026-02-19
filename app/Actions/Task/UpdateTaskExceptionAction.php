<?php

namespace App\Actions\Task;

use App\DataTransferObjects\Task\UpdateTaskExceptionDto;
use App\Models\TaskException;
use App\Services\TaskService;

class UpdateTaskExceptionAction
{
    public function __construct(
        private TaskService $taskService
    ) {}

    public function execute(TaskException $exception, UpdateTaskExceptionDto $dto): TaskException
    {
        $attributes = $dto->toServiceAttributes();

        return $attributes !== []
            ? $this->taskService->updateTaskException($exception, $attributes)
            : $exception->fresh();
    }
}

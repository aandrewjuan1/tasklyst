<?php

namespace App\Actions\Llm;

use App\Actions\Task\CreateTaskAction;
use App\DataTransferObjects\Llm\ToolResultDto;
use App\DataTransferObjects\Task\CreateTaskDto;
use App\Models\User;

class CreateTaskFromLlmAction
{
    public function __construct(
        private readonly CreateTaskAction $createTaskAction,
    ) {}

    /**
     * @param  array<string, mixed>  $args
     */
    public function __invoke(array $args, User $user): ToolResultDto
    {
        $dto = new CreateTaskDto(
            title: (string) ($args['title'] ?? ''),
            description: $args['description'] ?? null,
            status: null,
            priority: null,
            complexity: null,
            duration: isset($args['duration']) ? (int) $args['duration'] : null,
            startDatetime: null,
            endDatetime: \App\Support\DateHelper::parseOptional($args['end_datetime'] ?? null),
            projectId: null,
            eventId: null,
            tagIds: [],
            recurrence: null,
        );

        $task = $this->createTaskAction->execute($user, $dto);

        return new ToolResultDto(
            tool: 'create_task',
            success: true,
            payload: [
                'id' => $task->id,
                'title' => $task->title,
                'end_datetime' => $task->end_datetime,
                'duration' => $task->duration,
            ],
        );
    }
}

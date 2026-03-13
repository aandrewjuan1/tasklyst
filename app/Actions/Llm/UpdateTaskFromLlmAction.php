<?php

namespace App\Actions\Llm;

use App\Actions\Task\UpdateTaskPropertyAction;
use App\DataTransferObjects\Llm\ToolResultDto;
use App\Models\Task;
use App\Models\User;
use App\Support\Validation\TaskPayloadValidation;

class UpdateTaskFromLlmAction
{
    public function __construct(
        private readonly UpdateTaskPropertyAction $updateTaskPropertyAction,
    ) {}

    /**
     * @param  array<string, mixed>  $args
     */
    public function __invoke(array $args, User $user): ToolResultDto
    {
        $rawId = (string) ($args['id'] ?? '');
        $numericId = (int) str_replace('task_', '', $rawId);

        /** @var Task $task */
        $task = Task::query()->forUser($user->id)->findOrFail($numericId);

        $fields = (array) ($args['fields'] ?? []);
        $allowed = TaskPayloadValidation::allowedUpdateProperties();
        $fields = array_intersect_key($fields, array_flip($allowed));

        $results = [];

        foreach ($fields as $property => $value) {
            $results[$property] = $this->updateTaskPropertyAction->execute($task, $property, $value, null, $user);
        }

        return new ToolResultDto(
            tool: 'update_task',
            success: true,
            payload: [
                'task_id' => $task->id,
                'updated_fields' => array_keys($fields),
            ],
        );
    }
}

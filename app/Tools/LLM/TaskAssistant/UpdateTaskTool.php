<?php

namespace App\Tools\LLM\TaskAssistant;

use App\Actions\Task\UpdateTaskPropertyAction;
use App\Models\Task;

class UpdateTaskTool extends DelegatingTool
{
    public function __construct(
        \App\Models\User $user,
        private readonly UpdateTaskPropertyAction $updateTaskPropertyAction
    ) {
        parent::__construct($user);

        $this->as('update_task')
            ->for('Update an existing task property. Use when the user wants to change a task.')
            ->withNumberParameter('taskId', 'ID of the task to update', true)
            ->withStringParameter('property', 'Property to update: title, description, status, priority, complexity, duration, startDatetime, endDatetime, projectId, eventId, tagIds, recurrence', true)
            ->withStringParameter('value', 'New value for the property (JSON for tagIds/recurrence)', true)
            ->withStringParameter('occurrenceDate', 'Optional date for recurring task status update (Y-m-d)', false)
            ->withStringParameter('operation_token', 'Optional idempotency token', false)
            ->using($this);

        $this->action = function (array $params): array {
            $task = Task::query()
                ->forUser($this->user->id)
                ->findOrFail((int) $params['taskId']);
            $property = (string) $params['property'];
            $value = $params['value'];
            if (in_array($property, ['tagIds', 'recurrence'], true) && is_string($value)) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : $value;
            }
            if ($property === 'duration' && is_numeric($value)) {
                $value = (int) $value;
            }
            $occurrenceDate = isset($params['occurrenceDate']) ? (string) $params['occurrenceDate'] : null;
            $this->updateTaskPropertyAction->execute($task, $property, $value, $occurrenceDate, $this->user);

            return [
                'ok' => true,
                'message' => __('Task updated.'),
                'task' => [
                    'id' => $task->id,
                    'title' => $task->title,
                ],
            ];
        };
    }

    public function __invoke(mixed ...$args): string
    {
        $params = $this->normalizeParams(...$args);
        $operationToken = isset($params['operation_token']) ? (string) $params['operation_token'] : null;

        return $this->runDelegatedAction($params, 'update_task', $operationToken);
    }
}

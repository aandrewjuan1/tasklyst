<?php

namespace App\Tools\TaskAssistant;

use App\Actions\Task\CreateTaskAction;
use App\DataTransferObjects\Task\CreateTaskDto;
use Illuminate\Support\Arr;

class CreateTaskTool extends DelegatingTool
{
    public function __construct(
        \App\Models\User $user,
        private readonly CreateTaskAction $createTaskAction
    ) {
        parent::__construct($user);

        $this->as('create_task')
            ->for('Create a new task. Use when the user wants to add a task.')
            ->withStringParameter('title', 'Short title of the task', true)
            ->withStringParameter('description', 'Optional description', false)
            ->withStringParameter('status', 'Optional status e.g. to_do, doing, done', false)
            ->withStringParameter('priority', 'Optional priority', false)
            ->withStringParameter('complexity', 'Optional complexity', false)
            ->withNumberParameter('duration', 'Optional duration in minutes', false)
            ->withStringParameter('startDatetime', 'Optional ISO8601 start datetime', false)
            ->withStringParameter('endDatetime', 'Optional ISO8601 end datetime', false)
            ->withNumberParameter('projectId', 'Optional project ID', false)
            ->withNumberParameter('eventId', 'Optional event ID', false)
            ->withStringParameter('tagIds', 'Optional JSON array of tag IDs e.g. [1,2,3]', false)
            ->withStringParameter('recurrence', 'Optional JSON recurrence object', false)
            ->withStringParameter('operation_token', 'Optional idempotency token', false)
            ->using($this);

        $this->action = function (array $params): array {
            $validated = $this->buildValidatedFromParams($params);
            $dto = CreateTaskDto::fromValidated($validated);
            $task = $this->createTaskAction->execute($this->user, $dto);

            return [
                'ok' => true,
                'message' => __('Task created.'),
                'task' => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'status' => $task->status?->value ?? $task->status,
                    'priority' => $task->priority?->value ?? $task->priority,
                    'project_id' => $task->project_id,
                    'event_id' => $task->event_id,
                    'start_datetime' => $task->start_datetime?->toIso8601String(),
                    'end_datetime' => $task->end_datetime?->toIso8601String(),
                ],
            ];
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function buildValidatedFromParams(array $params): array
    {
        $tagIds = $params['tagIds'] ?? [];
        if (is_string($tagIds)) {
            $decoded = json_decode($tagIds, true);
            $tagIds = is_array($decoded) ? array_map('intval', $decoded) : [];
        }
        $tagIds = Arr::wrap($tagIds);

        $recurrence = $params['recurrence'] ?? null;
        if (is_string($recurrence)) {
            $decoded = json_decode($recurrence, true);
            $recurrence = is_array($decoded) ? $decoded : null;
        }

        return [
            'title' => (string) ($params['title'] ?? ''),
            'description' => isset($params['description']) ? (string) $params['description'] : null,
            'status' => isset($params['status']) ? (string) $params['status'] : null,
            'priority' => isset($params['priority']) ? (string) $params['priority'] : null,
            'complexity' => isset($params['complexity']) ? (string) $params['complexity'] : null,
            'duration' => isset($params['duration']) ? (int) $params['duration'] : null,
            'startDatetime' => isset($params['startDatetime']) ? $params['startDatetime'] : null,
            'endDatetime' => isset($params['endDatetime']) ? $params['endDatetime'] : null,
            'projectId' => isset($params['projectId']) ? (int) $params['projectId'] : null,
            'eventId' => isset($params['eventId']) ? (int) $params['eventId'] : null,
            'tagIds' => $tagIds,
            'recurrence' => $recurrence,
        ];
    }

    public function __invoke(mixed ...$args): string
    {
        $params = $this->normalizeParams(...$args);
        $operationToken = isset($params['operation_token']) ? (string) $params['operation_token'] : null;

        return $this->runDelegatedAction($params, 'create_task', $operationToken);
    }
}

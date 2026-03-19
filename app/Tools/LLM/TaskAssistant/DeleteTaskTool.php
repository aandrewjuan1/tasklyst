<?php

namespace App\Tools\LLM\TaskAssistant;

use App\Actions\Task\DeleteTaskAction;
use App\Models\Task;

class DeleteTaskTool extends DelegatingTool
{
    public function __construct(
        \App\Models\User $user,
        private readonly DeleteTaskAction $deleteTaskAction
    ) {
        parent::__construct($user);

        $this->as('delete_task')
            ->for('Move a task to trash. Use when the user wants to delete or remove a task.')
            ->withNumberParameter('taskId', 'ID of the task to delete', true)
            ->withBooleanParameter('confirm', 'Set true to confirm deletion', false)
            ->withStringParameter('operation_token', 'Optional idempotency token', false)
            ->using($this);

        $this->action = function (array $params): array {
            $task = Task::query()
                ->forUser($this->user->id)
                ->findOrFail((int) $params['taskId']);
            $this->deleteTaskAction->execute($task, $this->user);

            return [
                'ok' => true,
                'message' => __('Task moved to trash.'),
                'task_id' => $task->id,
            ];
        };
    }

    public function __invoke(mixed ...$args): string
    {
        $params = $this->normalizeParams(...$args);
        if (($params['confirm'] ?? false) !== true) {
            return json_encode([
                'ok' => false,
                'message' => __('Please confirm by calling again with confirm: true'),
                'requires_confirm' => true,
            ]);
        }
        $operationToken = isset($params['operation_token']) ? (string) $params['operation_token'] : null;

        return $this->runDelegatedAction($params, 'delete_task', $operationToken);
    }
}

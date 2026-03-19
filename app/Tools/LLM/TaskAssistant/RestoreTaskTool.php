<?php

namespace App\Tools\LLM\TaskAssistant;

use App\Actions\Task\RestoreTaskAction;
use App\Models\Task;

class RestoreTaskTool extends DelegatingTool
{
    public function __construct(
        \App\Models\User $user,
        private readonly RestoreTaskAction $restoreTaskAction
    ) {
        parent::__construct($user);

        $this->as('restore_task')
            ->for('Restore a task from trash. Use when the user wants to undo a task deletion.')
            ->withNumberParameter('taskId', 'ID of the task to restore', true)
            ->withStringParameter('operation_token', 'Optional idempotency token', false)
            ->using($this);

        $this->action = function (array $params): array {
            $task = Task::query()
                ->forUser($this->user->id)
                ->onlyTrashed()
                ->findOrFail((int) $params['taskId']);
            $this->restoreTaskAction->execute($task, $this->user);

            return [
                'ok' => true,
                'message' => __('Task restored.'),
                'task_id' => $task->id,
            ];
        };
    }

    public function __invoke(mixed ...$args): string
    {
        $params = $this->normalizeParams(...$args);
        $operationToken = isset($params['operation_token']) ? (string) $params['operation_token'] : null;

        return $this->runDelegatedAction($params, 'restore_task', $operationToken);
    }
}

<?php

namespace App\Tools\LLM\TaskAssistant;

use App\Actions\Task\ForceDeleteTaskAction;
use App\Models\Task;

class ForceDeleteTaskTool extends DelegatingTool
{
    public function __construct(
        \App\Models\User $user,
        private readonly ForceDeleteTaskAction $forceDeleteTaskAction
    ) {
        parent::__construct($user);

        $this->as('force_delete_task')
            ->for('Permanently delete a task from trash. Use only when the user explicitly wants to permanently remove a task.')
            ->withNumberParameter('taskId', 'ID of the task to permanently delete', true)
            ->withBooleanParameter('confirm', 'Set true to confirm permanent deletion', false)
            ->withStringParameter('operation_token', 'Optional idempotency token', false)
            ->using($this);

        $this->action = function (array $params): array {
            $task = Task::query()
                ->forUser($this->user->id)
                ->onlyTrashed()
                ->findOrFail((int) $params['taskId']);
            $this->forceDeleteTaskAction->execute($task, $this->user);

            return [
                'ok' => true,
                'message' => __('Task permanently deleted.'),
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

        return $this->runDelegatedAction($params, 'force_delete_task', $operationToken);
    }
}

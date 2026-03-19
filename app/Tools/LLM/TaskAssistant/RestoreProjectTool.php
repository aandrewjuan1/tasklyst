<?php

namespace App\Tools\LLM\TaskAssistant;

use App\Actions\Project\RestoreProjectAction;
use App\Models\Project;

class RestoreProjectTool extends DelegatingTool
{
    public function __construct(
        \App\Models\User $user,
        private readonly RestoreProjectAction $restoreProjectAction
    ) {
        parent::__construct($user);

        $this->as('restore_project')
            ->for('Restore a project from trash.')
            ->withNumberParameter('projectId', 'ID of the project to restore', true)
            ->withStringParameter('operation_token', 'Optional idempotency token', false)
            ->using($this);

        $this->action = function (array $params): array {
            $project = Project::query()
                ->forUser($this->user->id)
                ->onlyTrashed()
                ->findOrFail((int) $params['projectId']);
            $this->restoreProjectAction->execute($project, $this->user);

            return [
                'ok' => true,
                'message' => __('Project restored.'),
                'project_id' => $project->id,
            ];
        };
    }

    public function __invoke(mixed ...$args): string
    {
        $params = $this->normalizeParams(...$args);
        $operationToken = isset($params['operation_token']) ? (string) $params['operation_token'] : null;

        return $this->runDelegatedAction($params, 'restore_project', $operationToken);
    }
}

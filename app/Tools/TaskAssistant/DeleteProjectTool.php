<?php

namespace App\Tools\TaskAssistant;

use App\Actions\Project\DeleteProjectAction;
use App\Models\Project;

class DeleteProjectTool extends DelegatingTool
{
    public function __construct(
        \App\Models\User $user,
        private readonly DeleteProjectAction $deleteProjectAction
    ) {
        parent::__construct($user);

        $this->as('delete_project')
            ->for('Move a project to trash.')
            ->withNumberParameter('projectId', 'ID of the project to delete', true)
            ->withBooleanParameter('confirm', 'Set true to confirm deletion', false)
            ->withStringParameter('operation_token', 'Optional idempotency token', false)
            ->using($this);

        $this->action = function (array $params): array {
            $project = Project::query()
                ->forUser($this->user->id)
                ->findOrFail((int) $params['projectId']);
            $this->deleteProjectAction->execute($project, $this->user);

            return [
                'ok' => true,
                'message' => __('Project moved to trash.'),
                'project_id' => $project->id,
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

        return $this->runDelegatedAction($params, 'delete_project', $operationToken);
    }
}

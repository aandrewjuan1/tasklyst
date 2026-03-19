<?php

namespace App\Tools\LLM\TaskAssistant;

use App\Actions\Project\UpdateProjectPropertyAction;
use App\Models\Project;

class UpdateProjectTool extends DelegatingTool
{
    public function __construct(
        \App\Models\User $user,
        private readonly UpdateProjectPropertyAction $updateProjectPropertyAction
    ) {
        parent::__construct($user);

        $this->as('update_project')
            ->for('Update an existing project property.')
            ->withNumberParameter('projectId', 'ID of the project to update', true)
            ->withStringParameter('property', 'Property to update: name, description, startDatetime, endDatetime', true)
            ->withStringParameter('value', 'New value', true)
            ->withStringParameter('operation_token', 'Optional idempotency token', false)
            ->using($this);

        $this->action = function (array $params): array {
            $project = Project::query()
                ->forUser($this->user->id)
                ->findOrFail((int) $params['projectId']);
            $property = (string) $params['property'];
            $value = $params['value'];
            $this->updateProjectPropertyAction->execute($project, $property, $value, $this->user);

            return [
                'ok' => true,
                'message' => __('Project updated.'),
                'project' => ['id' => $project->id, 'name' => $project->name],
            ];
        };
    }

    public function __invoke(mixed ...$args): string
    {
        $params = $this->normalizeParams(...$args);
        $operationToken = isset($params['operation_token']) ? (string) $params['operation_token'] : null;

        return $this->runDelegatedAction($params, 'update_project', $operationToken);
    }
}

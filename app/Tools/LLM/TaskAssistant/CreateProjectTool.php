<?php

namespace App\Tools\LLM\TaskAssistant;

use App\Actions\Project\CreateProjectAction;
use App\DataTransferObjects\Project\CreateProjectDto;

class CreateProjectTool extends DelegatingTool
{
    public function __construct(
        \App\Models\User $user,
        private readonly CreateProjectAction $createProjectAction
    ) {
        parent::__construct($user);

        $this->as('create_project')
            ->for('Create a new project. Use when the user wants to add a project.')
            ->withStringParameter('name', 'Name of the project', true)
            ->withStringParameter('description', 'Optional description', false)
            ->withStringParameter('startDatetime', 'Optional ISO8601 start datetime', false)
            ->withStringParameter('endDatetime', 'Optional ISO8601 end datetime', false)
            ->withStringParameter('operation_token', 'Optional idempotency token', false)
            ->using($this);

        $this->action = function (array $params): array {
            $validated = [
                'name' => (string) ($params['name'] ?? ''),
                'description' => isset($params['description']) ? (string) $params['description'] : null,
                'startDatetime' => $params['startDatetime'] ?? null,
                'endDatetime' => $params['endDatetime'] ?? null,
            ];
            $dto = CreateProjectDto::fromValidated($validated);
            $project = $this->createProjectAction->execute($this->user, $dto);

            return [
                'ok' => true,
                'message' => __('Project created.'),
                'project' => [
                    'id' => $project->id,
                    'name' => $project->name,
                ],
            ];
        };
    }

    public function __invoke(mixed ...$args): string
    {
        $params = $this->normalizeParams(...$args);
        $operationToken = isset($params['operation_token']) ? (string) $params['operation_token'] : null;

        return $this->runDelegatedAction($params, 'create_project', $operationToken);
    }
}

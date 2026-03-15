<?php

namespace App\Tools\TaskAssistant;

use App\Actions\Tag\CreateTagAction;
use App\DataTransferObjects\Tag\CreateTagDto;

class CreateTagTool extends DelegatingTool
{
    public function __construct(
        \App\Models\User $user,
        private readonly CreateTagAction $createTagAction
    ) {
        parent::__construct($user);

        $this->as('create_tag')
            ->for('Create a new tag. Use when the user wants to add a tag.')
            ->withStringParameter('name', 'Name of the tag', true)
            ->withStringParameter('operation_token', 'Optional idempotency token', false)
            ->using($this);

        $this->action = function (array $params): array {
            $name = trim((string) ($params['name'] ?? ''));
            $dto = CreateTagDto::fromValidated($name);
            $result = $this->createTagAction->execute($this->user, $dto);

            return [
                'ok' => true,
                'message' => $result->wasExisting ? __('Tag already exists.') : __('Tag created.'),
                'tag' => [
                    'id' => $result->tag->id,
                    'name' => $result->tag->name,
                ],
            ];
        };
    }

    public function __invoke(mixed ...$args): string
    {
        $params = $this->normalizeParams(...$args);
        $operationToken = isset($params['operation_token']) ? (string) $params['operation_token'] : null;

        return $this->runDelegatedAction($params, 'create_tag', $operationToken);
    }
}

<?php

namespace App\Tools\TaskAssistant;

use App\Actions\Tag\DeleteTagAction;
use App\Models\Tag;

class DeleteTagTool extends DelegatingTool
{
    public function __construct(
        \App\Models\User $user,
        private readonly DeleteTagAction $deleteTagAction
    ) {
        parent::__construct($user);

        $this->as('delete_tag')
            ->for('Delete a tag.')
            ->withNumberParameter('tagId', 'ID of the tag to delete', true)
            ->withBooleanParameter('confirm', 'Set true to confirm deletion', false)
            ->withStringParameter('operation_token', 'Optional idempotency token', false)
            ->using($this);

        $this->action = function (array $params): array {
            $tag = Tag::query()
                ->forUser($this->user->id)
                ->findOrFail((int) $params['tagId']);
            $this->deleteTagAction->execute($tag);

            return [
                'ok' => true,
                'message' => __('Tag deleted.'),
                'tag_id' => $tag->id,
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

        return $this->runDelegatedAction($params, 'delete_tag', $operationToken);
    }
}

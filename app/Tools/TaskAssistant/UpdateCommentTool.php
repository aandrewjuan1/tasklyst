<?php

namespace App\Tools\TaskAssistant;

use App\Actions\Comment\UpdateCommentAction;
use App\DataTransferObjects\Comment\UpdateCommentDto;
use App\Models\Comment;

class UpdateCommentTool extends DelegatingTool
{
    public function __construct(
        \App\Models\User $user,
        private readonly UpdateCommentAction $updateCommentAction
    ) {
        parent::__construct($user);

        $this->as('update_comment')
            ->for('Update a comment.')
            ->withNumberParameter('commentId', 'ID of the comment to update', true)
            ->withStringParameter('content', 'New comment text', true)
            ->withBooleanParameter('isPinned', 'Whether the comment is pinned', false)
            ->withStringParameter('operation_token', 'Optional idempotency token', false)
            ->using($this);

        $this->action = function (array $params): array {
            $comment = Comment::query()
                ->where('user_id', $this->user->id)
                ->findOrFail((int) $params['commentId']);
            $validated = [
                'content' => (string) ($params['content'] ?? ''),
                'isPinned' => (bool) ($params['isPinned'] ?? false),
            ];
            $dto = UpdateCommentDto::fromValidated($validated);
            $comment = $this->updateCommentAction->execute($comment, $dto);

            return [
                'ok' => true,
                'message' => __('Comment updated.'),
                'comment' => ['id' => $comment->id, 'content' => $comment->content],
            ];
        };
    }

    public function __invoke(mixed ...$args): string
    {
        $params = $this->normalizeParams(...$args);
        $operationToken = isset($params['operation_token']) ? (string) $params['operation_token'] : null;

        return $this->runDelegatedAction($params, 'update_comment', $operationToken);
    }
}

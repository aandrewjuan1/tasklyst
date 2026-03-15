<?php

namespace App\Tools\TaskAssistant;

use App\Actions\Comment\DeleteCommentAction;
use App\Models\Comment;

class DeleteCommentTool extends DelegatingTool
{
    public function __construct(
        \App\Models\User $user,
        private readonly DeleteCommentAction $deleteCommentAction
    ) {
        parent::__construct($user);

        $this->as('delete_comment')
            ->for('Delete a comment.')
            ->withNumberParameter('commentId', 'ID of the comment to delete', true)
            ->withBooleanParameter('confirm', 'Set true to confirm deletion', false)
            ->withStringParameter('operation_token', 'Optional idempotency token', false)
            ->using($this);

        $this->action = function (array $params): array {
            $comment = Comment::query()
                ->where('user_id', $this->user->id)
                ->findOrFail((int) $params['commentId']);
            $this->deleteCommentAction->execute($comment);

            return [
                'ok' => true,
                'message' => __('Comment deleted.'),
                'comment_id' => $comment->id,
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

        return $this->runDelegatedAction($params, 'delete_comment', $operationToken);
    }
}

<?php

namespace App\Tools\LLM\TaskAssistant;

use App\Actions\Comment\CreateCommentAction;
use App\DataTransferObjects\Comment\CreateCommentDto;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;

class CreateCommentTool extends DelegatingTool
{
    public function __construct(
        \App\Models\User $user,
        private readonly CreateCommentAction $createCommentAction
    ) {
        parent::__construct($user);

        $this->as('create_comment')
            ->for('Add a comment to a task, event, or project.')
            ->withStringParameter('commentableType', 'Type: Task, Event, or Project', true)
            ->withNumberParameter('commentableId', 'ID of the task, event, or project', true)
            ->withStringParameter('content', 'Comment text', true)
            ->withStringParameter('operation_token', 'Optional idempotency token', false)
            ->using($this);

        $this->action = function (array $params): array {
            $commentableType = (string) ($params['commentableType'] ?? '');
            $type = match (strtolower($commentableType)) {
                'task' => Task::class,
                'event' => Event::class,
                'project' => Project::class,
                default => throw new \InvalidArgumentException("Invalid commentableType: {$commentableType}. Use Task, Event, or Project."),
            };
            $validated = [
                'commentableType' => $type,
                'commentableId' => (int) $params['commentableId'],
                'content' => (string) ($params['content'] ?? ''),
            ];
            $dto = CreateCommentDto::fromValidated($validated);
            $comment = $this->createCommentAction->execute($this->user, $dto);

            return [
                'ok' => true,
                'message' => __('Comment added.'),
                'comment' => [
                    'id' => $comment->id,
                    'content' => $comment->content,
                ],
            ];
        };
    }

    public function __invoke(mixed ...$args): string
    {
        $params = $this->normalizeParams(...$args);
        $operationToken = isset($params['operation_token']) ? (string) $params['operation_token'] : null;

        return $this->runDelegatedAction($params, 'create_comment', $operationToken);
    }
}

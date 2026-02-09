<?php

namespace App\DataTransferObjects\Comment;

final readonly class CreateCommentDto
{
    public function __construct(
        public int $taskId,
        public string $content,
    ) {}

    /**
     * Create from validated commentPayload array.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            taskId: (int) $validated['taskId'],
            content: trim((string) $validated['content']),
        );
    }

    /**
     * Convert to array format expected by CommentService::createComment.
     * task_id and user_id are set by the service from the resolved Task and User.
     *
     * @return array<string, mixed>
     */
    public function toServiceAttributes(): array
    {
        return [
            'content' => $this->content,
        ];
    }
}

<?php

namespace App\DataTransferObjects\Comment;

final readonly class CreateCommentDto
{
    public function __construct(
        public string $commentableType,
        public int $commentableId,
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
            commentableType: (string) $validated['commentableType'],
            commentableId: (int) $validated['commentableId'],
            content: trim((string) $validated['content']),
        );
    }

    /**
     * Convert to array format expected by CommentService::createComment.
     * commentable_id, commentable_type, and user_id are set by the service from the resolved model and User.
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

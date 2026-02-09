<?php

namespace App\DataTransferObjects\Comment;

final readonly class UpdateCommentDto
{
    public function __construct(
        public string $content,
        public bool $isPinned,
    ) {}

    /**
     * Create from validated commentPayload array.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            content: trim((string) ($validated['content'] ?? '')),
            isPinned: (bool) ($validated['isPinned'] ?? false),
        );
    }

    /**
     * Convert to array format expected by CommentService::updateComment.
     * is_edited and edited_at are set by the service only when content has changed.
     *
     * @return array<string, mixed>
     */
    public function toServiceAttributes(): array
    {
        return [
            'content' => $this->content,
            'is_pinned' => $this->isPinned,
        ];
    }
}

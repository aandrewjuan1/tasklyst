<?php

namespace App\Actions\Comment;

use App\DataTransferObjects\Comment\UpdateCommentDto;
use App\Models\Comment;
use App\Services\CommentService;

class UpdateCommentAction
{
    public function __construct(
        private CommentService $commentService
    ) {}

    public function execute(Comment $comment, UpdateCommentDto $dto): Comment
    {
        return $this->commentService->updateComment($comment, $dto->toServiceAttributes());
    }
}

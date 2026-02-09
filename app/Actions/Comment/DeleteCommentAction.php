<?php

namespace App\Actions\Comment;

use App\Models\Comment;
use App\Services\CommentService;

class DeleteCommentAction
{
    public function __construct(
        private CommentService $commentService
    ) {}

    public function execute(Comment $comment): bool
    {
        return $this->commentService->deleteComment($comment);
    }
}

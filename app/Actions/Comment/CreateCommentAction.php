<?php

namespace App\Actions\Comment;

use App\DataTransferObjects\Comment\CreateCommentDto;
use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use App\Services\CommentService;

class CreateCommentAction
{
    public function __construct(
        private CommentService $commentService
    ) {}

    public function execute(User $user, Task $task, CreateCommentDto $dto): Comment
    {
        return $this->commentService->createComment($user, $task, $dto->toServiceAttributes());
    }
}

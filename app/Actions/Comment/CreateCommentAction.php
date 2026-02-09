<?php

namespace App\Actions\Comment;

use App\DataTransferObjects\Comment\CreateCommentDto;
use App\Models\Comment;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\CommentService;
use Illuminate\Database\Eloquent\Model;

class CreateCommentAction
{
    public function __construct(
        private CommentService $commentService
    ) {}

    public function execute(User $user, CreateCommentDto $dto): Comment
    {
        $commentable = $this->resolveCommentable($dto->commentableType, $dto->commentableId);

        return $this->commentService->createComment($user, $commentable, $dto->toServiceAttributes());
    }

    private function resolveCommentable(string $type, int $id): Model
    {
        return match ($type) {
            Task::class => Task::query()->findOrFail($id),
            Event::class => Event::query()->findOrFail($id),
            Project::class => Project::query()->findOrFail($id),
            default => throw new \InvalidArgumentException("Invalid commentable type: {$type}"),
        };
    }
}

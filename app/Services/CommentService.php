<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Enums\ActivityLogAction;
use App\Services\ActivityLogRecorder;

class CommentService
{
    public function __construct(
        private ActivityLogRecorder $activityLogRecorder,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createComment(User $user, Model $commentable, array $attributes): Comment
    {
        return DB::transaction(function () use ($user, $commentable, $attributes): Comment {
            $comment = Comment::query()->create([
                ...$attributes,
                'commentable_id' => $commentable->id,
                'commentable_type' => $commentable::class,
                'user_id' => $user->id,
            ]);

            $this->activityLogRecorder->record(
                $commentable,
                $user,
                ActivityLogAction::CommentCreated,
                [
                    'comment_id' => $comment->id,
                    'content' => $comment->content,
                ]
            );

            return $comment;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateComment(Comment $comment, array $attributes): Comment
    {
        $originalContent = $comment->content;
        $comment->loadMissing('commentable', 'user');
        $commentable = $comment->commentable;
        $actor = $comment->user;

        unset($attributes['commentable_id'], $attributes['commentable_type'], $attributes['user_id']);

        $contentWillChange = array_key_exists('content', $attributes) && $comment->content !== $attributes['content'];

        if ($contentWillChange) {
            $attributes['is_edited'] = true;
            $attributes['edited_at'] = now();
        }

        return DB::transaction(function () use ($comment, $attributes, $originalContent, $commentable, $actor, $contentWillChange): Comment {
            $comment->fill($attributes);
            $comment->save();

            if ($contentWillChange && $commentable !== null && $actor !== null) {
                $this->activityLogRecorder->record(
                    $commentable,
                    $actor,
                    ActivityLogAction::CommentUpdated,
                    [
                        'comment_id' => $comment->id,
                        'from' => $originalContent,
                        'to' => $comment->content,
                    ]
                );
            }

            return $comment;
        });
    }

    public function deleteComment(Comment $comment): bool
    {
        $comment->loadMissing('commentable', 'user');
        $commentable = $comment->commentable;
        $actor = $comment->user;
        $content = $comment->content;
        $id = $comment->id;

        return DB::transaction(function () use ($comment, $commentable, $actor, $content, $id): bool {
            $deleted = (bool) $comment->delete();

            if ($deleted && $commentable !== null) {
                $this->activityLogRecorder->record(
                    $commentable,
                    $actor,
                    ActivityLogAction::CommentDeleted,
                    [
                        'comment_id' => $id,
                        'content' => $content,
                    ]
                );
            }

            return $deleted;
        });
    }
}

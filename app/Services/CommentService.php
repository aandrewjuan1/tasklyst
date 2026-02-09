<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CommentService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createComment(User $user, Model $commentable, array $attributes): Comment
    {
        return DB::transaction(function () use ($user, $commentable, $attributes): Comment {
            return Comment::query()->create([
                ...$attributes,
                'commentable_id' => $commentable->id,
                'commentable_type' => $commentable::class,
                'user_id' => $user->id,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateComment(Comment $comment, array $attributes): Comment
    {
        unset($attributes['commentable_id'], $attributes['commentable_type'], $attributes['user_id']);

        if (array_key_exists('content', $attributes) && $comment->content !== $attributes['content']) {
            $attributes['is_edited'] = true;
            $attributes['edited_at'] = now();
        }

        return DB::transaction(function () use ($comment, $attributes): Comment {
            $comment->fill($attributes);
            $comment->save();

            return $comment;
        });
    }

    public function deleteComment(Comment $comment): bool
    {
        return DB::transaction(function () use ($comment): bool {
            return (bool) $comment->delete();
        });
    }
}

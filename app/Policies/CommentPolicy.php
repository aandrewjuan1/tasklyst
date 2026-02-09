<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Comment $comment): bool
    {
        $commentable = $comment->commentable;

        return $user->can('view', $commentable);
    }

    /**
     * Determine whether the user can create models.
     * Authorization is checked on the commentable model (Task, Event, or Project).
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Comment $comment): bool
    {
        $commentable = $comment->commentable;

        return $comment->user_id === $user->id || $user->can('update', $commentable);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Comment $comment): bool
    {
        $commentable = $comment->commentable;

        return $comment->user_id === $user->id || $user->can('update', $commentable);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Comment $comment): bool
    {
        $commentable = $comment->commentable;

        return $user->can('update', $commentable);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Comment $comment): bool
    {
        $commentable = $comment->commentable;

        return $user->can('update', $commentable);
    }
}

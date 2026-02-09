<?php

namespace App\Livewire\Concerns;

use App\DataTransferObjects\Comment\CreateCommentDto;
use App\DataTransferObjects\Comment\UpdateCommentDto;
use App\Models\Comment;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Support\Validation\CommentPayloadValidation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Async;
use Livewire\Attributes\Renderless;

trait HandlesComments
{
    /**
     * Add a comment to a task, event, or project.
     *
     * This mirrors the optimistic flows used for tasks / events / projects:
     * - return a non-null integer ID on success
     * - return null on any validation / authorization / persistence failure so the
     *   frontend can rollback its optimistic state.
     *
     * @param  array<string, mixed>  $payload
     */
    #[Async]
    #[Renderless]
    public function addComment(array $payload): ?int
    {
        $user = $this->requireAuth(__('You must be logged in to add comments.'));
        if ($user === null) {
            return null;
        }

        $payload = array_replace_recursive(CommentPayloadValidation::createDefaults(), $payload);

        $validator = Validator::make(['commentPayload' => $payload], CommentPayloadValidation::createRules());
        if ($validator->fails()) {
            $this->dispatch('toast', type: 'error', message: $validator->errors()->first() ?: __('Invalid comment.'));

            return null;
        }

        $validated = $validator->validated()['commentPayload'];
        $commentableType = $validated['commentableType'];
        $commentableId = (int) $validated['commentableId'];

        $commentable = match ($commentableType) {
            Task::class => Task::query()->forUser($user->id)->find($commentableId),
            Event::class => Event::query()->forUser($user->id)->find($commentableId),
            Project::class => Project::query()->forUser($user->id)->find($commentableId),
            default => null,
        };

        if ($commentable === null) {
            $this->dispatch('toast', type: 'error', message: __('Item not found.'));

            return null;
        }

        $this->authorize('update', $commentable);

        $dto = CreateCommentDto::fromValidated($validated);

        try {
            $comment = $this->createCommentAction->execute($user, $dto);
        } catch (\Throwable $e) {
            Log::error('Failed to add comment from workspace.', [
                'user_id' => $user->id,
                'commentable_type' => $commentableType,
                'commentable_id' => $commentableId,
                'exception' => $e,
            ]);
            $this->dispatch('toast', type: 'error', message: __('Could not add comment. Please try again.'));

            return null;
        }

        $this->dispatch('comment-added', commentId: $comment->id, commentableType: $commentableType, commentableId: $commentableId);
        $this->dispatch('toast', type: 'success', message: __('Comment added.'));

        return (int) $comment->id;
    }

    /**
     * Update a comment.
     *
     * Mirrors other optimistic flows (e.g. updateTaskProperty / updateEventProperty) by
     * returning a boolean status instead of relying on exceptions only.
     *
     * @param  array<string, mixed>  $payload
     */
    #[Async]
    #[Renderless]
    public function updateComment(int $commentId, array $payload): bool
    {
        $user = $this->requireAuth(__('You must be logged in to update comments.'));
        if ($user === null) {
            return false;
        }

        $comment = Comment::query()->with('commentable')->find($commentId);
        if ($comment === null) {
            $this->dispatch('toast', type: 'error', message: __('Comment not found.'));

            return false;
        }

        $commentable = $comment->commentable;
        if ($commentable === null) {
            $this->dispatch('toast', type: 'error', message: __('Item not found.'));

            return false;
        }

        $this->authorize('update', $comment);

        $payload = array_replace_recursive(CommentPayloadValidation::updateDefaults(), $payload);

        $validator = Validator::make(['commentPayload' => $payload], CommentPayloadValidation::updateRules());
        if ($validator->fails()) {
            $this->dispatch('toast', type: 'error', message: $validator->errors()->first() ?: __('Invalid comment.'));

            return false;
        }

        $dto = UpdateCommentDto::fromValidated($validator->validated()['commentPayload']);

        try {
            $this->updateCommentAction->execute($comment, $dto);
        } catch (\Throwable $e) {
            Log::error('Failed to update comment from workspace.', [
                'user_id' => $user->id,
                'comment_id' => $commentId,
                'exception' => $e,
            ]);
            $this->dispatch('toast', type: 'error', message: __('Could not update comment. Please try again.'));

            return false;
        }

        $this->dispatch('comment-updated', commentId: $comment->id, commentableType: $comment->commentable_type, commentableId: $comment->commentable_id);
        $this->dispatch('toast', type: 'success', message: __('Comment updated.'));

        return true;
    }

    /**
     * Delete a comment.
     */
    #[Async]
    #[Renderless]
    public function deleteComment(int $commentId): bool
    {
        $user = $this->requireAuth(__('You must be logged in to delete comments.'));
        if ($user === null) {
            return false;
        }

        $comment = Comment::query()->with('commentable')->find($commentId);
        if ($comment === null) {
            $this->dispatch('toast', type: 'error', message: __('Comment not found.'));

            return false;
        }

        $this->authorize('delete', $comment);

        try {
            $deleted = $this->deleteCommentAction->execute($comment);
        } catch (\Throwable $e) {
            Log::error('Failed to delete comment from workspace.', [
                'user_id' => $user->id,
                'comment_id' => $commentId,
                'exception' => $e,
            ]);
            $this->dispatch('toast', type: 'error', message: __('Could not delete comment. Please try again.'));

            return false;
        }

        if (! $deleted) {
            $this->dispatch('toast', type: 'error', message: __('Could not delete comment. Please try again.'));

            return false;
        }

        $this->dispatch('comment-deleted', commentId: $commentId, commentableType: $comment->commentable_type, commentableId: $comment->commentable_id);
        $this->dispatch('toast', type: 'success', message: __('Comment deleted.'));

        return true;
    }
}

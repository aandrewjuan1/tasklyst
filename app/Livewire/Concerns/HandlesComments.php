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
     * @param  array<string, mixed>  $payload
     */
    #[Async]
    #[Renderless]
    public function addComment(array $payload): void
    {
        $user = $this->requireAuth(__('You must be logged in to add comments.'));
        if ($user === null) {
            return;
        }

        $payload = array_replace_recursive(CommentPayloadValidation::createDefaults(), $payload);

        $validator = Validator::make(['commentPayload' => $payload], CommentPayloadValidation::createRules());
        if ($validator->fails()) {
            $this->dispatch('toast', type: 'error', message: $validator->errors()->first() ?: __('Invalid comment.'));

            return;
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

            return;
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

            return;
        }

        $this->dispatch('comment-added', commentId: $comment->id, commentableType: $commentableType, commentableId: $commentableId);
        $this->dispatch('toast', type: 'success', message: __('Comment added.'));
        $this->dispatch('$refresh');
    }

    /**
     * Update a comment.
     *
     * @param  array<string, mixed>  $payload
     */
    #[Async]
    #[Renderless]
    public function updateComment(int $commentId, array $payload): void
    {
        $user = $this->requireAuth(__('You must be logged in to update comments.'));
        if ($user === null) {
            return;
        }

        $comment = Comment::query()->with('commentable')->find($commentId);
        if ($comment === null) {
            $this->dispatch('toast', type: 'error', message: __('Comment not found.'));

            return;
        }

        $commentable = $comment->commentable;
        if ($commentable === null) {
            $this->dispatch('toast', type: 'error', message: __('Item not found.'));

            return;
        }

        $this->authorize('update', $comment);

        $payload = array_replace_recursive(CommentPayloadValidation::updateDefaults(), $payload);

        $validator = Validator::make(['commentPayload' => $payload], CommentPayloadValidation::updateRules());
        if ($validator->fails()) {
            $this->dispatch('toast', type: 'error', message: $validator->errors()->first() ?: __('Invalid comment.'));

            return;
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

            return;
        }

        $this->dispatch('comment-updated', commentId: $comment->id, commentableType: $comment->commentable_type, commentableId: $comment->commentable_id);
        $this->dispatch('toast', type: 'success', message: __('Comment updated.'));
        $this->dispatch('$refresh');
    }

    /**
     * Delete a comment.
     */
    #[Async]
    #[Renderless]
    public function deleteComment(int $commentId): void
    {
        $user = $this->requireAuth(__('You must be logged in to delete comments.'));
        if ($user === null) {
            return;
        }

        $comment = Comment::query()->with('commentable')->find($commentId);
        if ($comment === null) {
            $this->dispatch('toast', type: 'error', message: __('Comment not found.'));

            return;
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

            return;
        }

        if (! $deleted) {
            $this->dispatch('toast', type: 'error', message: __('Could not delete comment. Please try again.'));

            return;
        }

        $this->dispatch('comment-deleted', commentId: $commentId, commentableType: $comment->commentable_type, commentableId: $comment->commentable_id);
        $this->dispatch('toast', type: 'success', message: __('Comment deleted.'));
        $this->dispatch('$refresh');
    }
}

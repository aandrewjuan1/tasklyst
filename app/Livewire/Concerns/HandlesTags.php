<?php

namespace App\Livewire\Concerns;

use App\DataTransferObjects\Tag\CreateTagDto;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Async;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Renderless;

trait HandlesTags
{
    /**
     * Create a new tag for the authenticated user.
     *
     * @param  bool  $silentToasts  When true, do not dispatch success/info toasts (e.g. when creating from list-item-card so only "Task updated." is shown).
     */
    #[Async]
    #[Renderless]
    public function createTag(string $name, bool $silentToasts = false): void
    {
        $user = $this->requireAuth(__('You must be logged in to create tags.'));
        if ($user === null) {
            return;
        }

        $this->authorize('create', Tag::class);

        $name = trim($name);

        $validator = Validator::make(
            ['name' => $name],
            [
                'name' => ['required', 'string', 'max:255', 'regex:/\S/'],
            ],
            [
                'name.required' => __('Tag name is required.'),
                'name.max' => __('Tag name cannot exceed 255 characters.'),
                'name.regex' => __('Tag name cannot be empty.'),
            ]
        );

        if ($validator->fails()) {
            Log::error('Tag validation failed', [
                'errors' => $validator->errors()->all(),
                'name' => $name,
            ]);
            $this->dispatch('toast', type: 'error', message: $validator->errors()->first('name') ?: __('Please fix the tag name and try again.'));

            return;
        }

        $validatedName = $validator->validated()['name'];
        $dto = CreateTagDto::fromValidated($validatedName);

        try {
            $result = $this->createTagAction->execute($user, $dto);
        } catch (\Throwable $e) {
            Log::error('Failed to create tag from workspace.', [
                'user_id' => $user->id,
                'name' => $name,
                'exception' => $e,
            ]);

            $this->dispatch('toast', type: 'error', message: __('Something went wrong creating the tag.'));

            return;
        }

        $this->dispatch('tag-created', id: $result->tag->id, name: $result->tag->name);
        if (! $result->wasExisting && ! $silentToasts) {
            $this->dispatch('toast', type: 'success', message: __('Tag created.'));
        }
        $this->dispatch('$refresh');
    }

    /**
     * Delete a tag for the authenticated user.
     *
     * @param  bool  $silentToasts  When true, do not dispatch success toast (e.g. when deleting from list-item-card so only "Task updated." is shown).
     */
    #[Async]
    #[Renderless]
    public function deleteTag(int $tagId, bool $silentToasts = false): void
    {
        $user = $this->requireAuth(__('You must be logged in to delete tags.'));
        if ($user === null) {
            return;
        }

        $tag = Tag::query()->forUser($user->id)->find($tagId);

        if ($tag === null) {
            if (! $silentToasts) {
                $this->dispatch('toast', type: 'error', message: __('Tag not found.'));
            }

            return;
        }

        $this->authorize('delete', $tag);

        try {
            $deleted = $this->deleteTagAction->execute($tag);
        } catch (\Throwable $e) {
            Log::error('Failed to delete tag from workspace.', [
                'user_id' => $user->id,
                'tag_id' => $tagId,
                'exception' => $e,
            ]);

            $this->dispatch('toast', type: 'error', message: __('Something went wrong deleting the tag.'));

            return;
        }

        if (! $deleted) {
            $this->dispatch('toast', type: 'error', message: __('Something went wrong deleting the tag.'));

            return;
        }

        $this->dispatch('tag-deleted', id: $tagId);
        if (! $silentToasts) {
            $this->dispatch('toast', type: 'success', message: __('Tag ":name" deleted.', ['name' => $tag->name]));
        }
        $this->dispatch('$refresh');
    }

    /**
     * Get tags for the authenticated user.
     */
    #[Computed]
    public function tags(): Collection
    {
        $userId = Auth::id();

        if ($userId === null) {
            return collect();
        }

        return Tag::query()
            ->forUser($userId)
            ->orderBy('name')
            ->get();
    }
}

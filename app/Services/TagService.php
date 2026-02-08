<?php

namespace App\Services;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TagService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createTag(User $user, array $attributes): Tag
    {
        return DB::transaction(function () use ($user, $attributes): Tag {
            return Tag::query()->create([
                ...$attributes,
                'user_id' => $user->id,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateTag(Tag $tag, array $attributes): Tag
    {
        unset($attributes['user_id']);

        return DB::transaction(function () use ($tag, $attributes): Tag {
            $tag->fill($attributes);
            $tag->save();

            return $tag;
        });
    }

    public function deleteTag(Tag $tag): bool
    {
        return DB::transaction(function () use ($tag): bool {
            return (bool) $tag->delete();
        });
    }

    /**
     * Resolve tag IDs from validated payload (tagIds + pendingTagNames).
     * Finds existing tags by name or creates new ones for pending names.
     *
     * @param  array{tagIds?: int[], pendingTagNames?: string[]}  $validated
     * @return array<int>
     */
    public function resolveTagIdsFromPayload(User $user, array $validated, string $context = 'task'): array
    {
        $tagIds = array_values(array_unique(array_map('intval', $validated['tagIds'] ?? [])));
        $pendingTagNames = $validated['pendingTagNames'] ?? [];

        foreach ($pendingTagNames as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }

            $existingTag = Tag::query()->forUser($user->id)->byName($name)->first();
            if ($existingTag !== null) {
                $tagIds[] = $existingTag->id;

                continue;
            }

            try {
                $tag = $this->createTag($user, ['name' => $name]);
                $tagIds[] = $tag->id;
            } catch (\Throwable $e) {
                Log::error("Failed to create tag when creating {$context}.", [
                    'user_id' => $user->id,
                    'name' => $name,
                    'exception' => $e,
                ]);
            }
        }

        return array_values(array_unique($tagIds));
    }
}

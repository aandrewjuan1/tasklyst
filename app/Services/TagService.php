<?php

namespace App\Services;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
}

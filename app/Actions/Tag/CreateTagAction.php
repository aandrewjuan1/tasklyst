<?php

namespace App\Actions\Tag;

use App\DataTransferObjects\Tag\CreateTagDto;
use App\DataTransferObjects\Tag\CreateTagResult;
use App\Models\Tag;
use App\Models\User;
use App\Services\TagService;

class CreateTagAction
{
    public function __construct(
        private TagService $tagService
    ) {}

    public function execute(User $user, CreateTagDto $dto): CreateTagResult
    {
        $existingTag = Tag::query()->forUser($user->id)->byName($dto->name)->first();

        if ($existingTag !== null) {
            return new CreateTagResult(tag: $existingTag, wasExisting: true);
        }

        $tag = $this->tagService->createTag($user, ['name' => $dto->name]);

        return new CreateTagResult(tag: $tag, wasExisting: false);
    }
}

<?php

namespace App\Actions\Tag;

use App\Models\Tag;
use App\Services\TagService;

class DeleteTagAction
{
    public function __construct(
        private TagService $tagService
    ) {}

    public function execute(Tag $tag): bool
    {
        return $this->tagService->deleteTag($tag);
    }
}

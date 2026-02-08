<?php

namespace App\DataTransferObjects\Tag;

use App\Models\Tag;

final readonly class CreateTagResult
{
    public function __construct(
        public Tag $tag,
        public bool $wasExisting,
    ) {}
}

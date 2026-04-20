<?php

namespace App\DataTransferObjects\Tag;

use App\Models\Tag;

final readonly class CreateTagDto
{
    public function __construct(
        public string $name,
    ) {}

    public static function fromValidated(string $name): self
    {
        $trimmed = trim($name);
        self::assertNameLength($trimmed);

        return new self(name: $trimmed);
    }

    private static function assertNameLength(string $name): void
    {
        if (mb_strlen($name) > Tag::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException('Tag name is too long.');
        }
    }
}

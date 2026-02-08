<?php

namespace App\DataTransferObjects\Tag;

final readonly class CreateTagDto
{
    public function __construct(
        public string $name,
    ) {}

    public static function fromValidated(string $name): self
    {
        return new self(name: trim($name));
    }
}

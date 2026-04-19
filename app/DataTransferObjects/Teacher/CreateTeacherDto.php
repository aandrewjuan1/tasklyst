<?php

namespace App\DataTransferObjects\Teacher;

final readonly class CreateTeacherDto
{
    public function __construct(
        public string $name,
    ) {}

    public static function fromValidated(string $name): self
    {
        return new self(name: trim($name));
    }
}

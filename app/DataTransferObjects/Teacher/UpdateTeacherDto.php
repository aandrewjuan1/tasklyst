<?php

namespace App\DataTransferObjects\Teacher;

use App\Models\Teacher;

final readonly class UpdateTeacherDto
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
        if (mb_strlen($name) > Teacher::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException('Teacher name is too long.');
        }
    }
}

<?php

namespace App\DataTransferObjects\Teacher;

use App\Models\Teacher;

final readonly class CreateTeacherResult
{
    public function __construct(
        public Teacher $teacher,
        public bool $wasExisting,
    ) {}
}

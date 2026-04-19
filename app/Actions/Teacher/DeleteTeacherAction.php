<?php

namespace App\Actions\Teacher;

use App\Models\Teacher;
use App\Services\TeacherService;

class DeleteTeacherAction
{
    public function __construct(
        private TeacherService $teacherService
    ) {}

    public function execute(Teacher $teacher): bool
    {
        return $this->teacherService->deleteTeacher($teacher);
    }
}

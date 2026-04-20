<?php

namespace App\Actions\Teacher;

use App\Models\Teacher;
use App\Services\TeacherService;

class DeleteTeacherAction
{
    public function __construct(
        private TeacherService $teacherService
    ) {}

    /**
     * @return array{deleted: bool, affectedClassCount: int}
     */
    public function execute(Teacher $teacher): array
    {
        return $this->teacherService->deleteTeacher($teacher);
    }
}

<?php

namespace App\Actions\Teacher;

use App\DataTransferObjects\Teacher\UpdateTeacherDto;
use App\Models\Teacher;
use App\Services\TeacherService;

class UpdateTeacherAction
{
    public function __construct(
        private TeacherService $teacherService
    ) {}

    public function execute(Teacher $teacher, UpdateTeacherDto $dto): Teacher
    {
        return $this->teacherService->updateTeacher($teacher, ['name' => $dto->name]);
    }
}

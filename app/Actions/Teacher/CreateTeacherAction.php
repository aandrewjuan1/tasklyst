<?php

namespace App\Actions\Teacher;

use App\DataTransferObjects\Teacher\CreateTeacherDto;
use App\DataTransferObjects\Teacher\CreateTeacherResult;
use App\Models\Teacher;
use App\Models\User;

class CreateTeacherAction
{
    public function execute(User $user, CreateTeacherDto $dto): CreateTeacherResult
    {
        $teacher = Teacher::firstOrCreateByDisplayName($user->id, $dto->name);

        return new CreateTeacherResult(
            teacher: $teacher,
            wasExisting: ! $teacher->wasRecentlyCreated,
        );
    }
}

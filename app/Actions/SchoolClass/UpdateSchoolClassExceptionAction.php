<?php

namespace App\Actions\SchoolClass;

use App\DataTransferObjects\SchoolClass\UpdateSchoolClassExceptionDto;
use App\Models\SchoolClassException;
use App\Models\User;
use App\Services\SchoolClassService;
use Illuminate\Support\Facades\Gate;

class UpdateSchoolClassExceptionAction
{
    public function __construct(
        private SchoolClassService $schoolClassService
    ) {}

    public function execute(User $user, SchoolClassException $exception, UpdateSchoolClassExceptionDto $dto): SchoolClassException
    {
        $schoolClass = $exception->recurringSchoolClass->schoolClass;
        Gate::forUser($user)->authorize('update', $schoolClass);

        $attributes = $dto->toServiceAttributes();

        return $attributes !== []
            ? $this->schoolClassService->updateSchoolClassException($exception, $attributes)
            : $exception->fresh();
    }
}

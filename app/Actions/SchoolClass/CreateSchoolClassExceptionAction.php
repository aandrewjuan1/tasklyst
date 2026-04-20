<?php

namespace App\Actions\SchoolClass;

use App\DataTransferObjects\SchoolClass\CreateSchoolClassExceptionDto;
use App\Models\RecurringSchoolClass;
use App\Models\SchoolClassException;
use App\Models\SchoolClassInstance;
use App\Models\User;
use App\Services\SchoolClassService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class CreateSchoolClassExceptionAction
{
    public function __construct(
        private SchoolClassService $schoolClassService
    ) {}

    public function execute(User $user, CreateSchoolClassExceptionDto $dto): SchoolClassException
    {
        $recurringSchoolClass = RecurringSchoolClass::query()->findOrFail($dto->recurringSchoolClassId);
        $schoolClass = $recurringSchoolClass->schoolClass;
        Gate::forUser($user)->authorize('update', $schoolClass);

        $date = Carbon::parse($dto->exceptionDate);

        $replacement = null;
        if ($dto->replacementInstanceId !== null) {
            $replacement = SchoolClassInstance::query()
                ->where('recurring_school_class_id', $recurringSchoolClass->id)
                ->findOrFail($dto->replacementInstanceId);
        }

        return $this->schoolClassService->createSchoolClassException(
            $recurringSchoolClass,
            $date,
            $dto->isDeleted,
            $replacement,
            $user,
            $dto->reason
        );
    }
}

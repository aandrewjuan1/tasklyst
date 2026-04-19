<?php

namespace App\Actions\SchoolClass;

use App\Models\SchoolClassException;
use App\Models\User;
use App\Services\SchoolClassService;
use Illuminate\Support\Facades\Gate;

class DeleteSchoolClassExceptionAction
{
    public function __construct(
        private SchoolClassService $schoolClassService
    ) {}

    public function execute(User $user, SchoolClassException $exception): bool
    {
        $schoolClass = $exception->recurringSchoolClass->schoolClass;
        Gate::forUser($user)->authorize('update', $schoolClass);

        return $this->schoolClassService->deleteSchoolClassException($exception);
    }
}

<?php

namespace App\Actions\SchoolClass;

use App\Models\SchoolClass;
use App\Models\User;
use App\Services\SchoolClassService;
use Illuminate\Support\Facades\Gate;

class DeleteSchoolClassAction
{
    public function __construct(
        private SchoolClassService $schoolClassService
    ) {}

    public function execute(SchoolClass $schoolClass, ?User $actor = null): bool
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('delete', $schoolClass);
        }

        return $this->schoolClassService->deleteSchoolClass($schoolClass, $actor);
    }
}

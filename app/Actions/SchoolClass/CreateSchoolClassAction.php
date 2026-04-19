<?php

namespace App\Actions\SchoolClass;

use App\DataTransferObjects\SchoolClass\CreateSchoolClassDto;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\SchoolClassService;
use Illuminate\Support\Facades\Gate;

class CreateSchoolClassAction
{
    public function __construct(
        private SchoolClassService $schoolClassService
    ) {}

    public function execute(User $user, CreateSchoolClassDto $dto): SchoolClass
    {
        Gate::forUser($user)->authorize('create', SchoolClass::class);

        return $this->schoolClassService->createSchoolClass($user, $dto->toServiceAttributes());
    }
}

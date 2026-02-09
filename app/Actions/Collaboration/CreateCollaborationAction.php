<?php

namespace App\Actions\Collaboration;

use App\DataTransferObjects\Collaboration\CreateCollaborationDto;
use App\Models\Collaboration;
use App\Services\CollaborationService;

class CreateCollaborationAction
{
    public function __construct(
        private CollaborationService $collaborationService
    ) {}

    public function execute(CreateCollaborationDto $dto): Collaboration
    {
        return $this->collaborationService->createCollaboration($dto->toServiceAttributes());
    }
}

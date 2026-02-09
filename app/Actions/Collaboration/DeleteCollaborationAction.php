<?php

namespace App\Actions\Collaboration;

use App\Models\Collaboration;
use App\Services\CollaborationService;

class DeleteCollaborationAction
{
    public function __construct(
        private CollaborationService $collaborationService
    ) {}

    public function execute(Collaboration $collaboration): bool
    {
        return $this->collaborationService->deleteCollaboration($collaboration);
    }
}

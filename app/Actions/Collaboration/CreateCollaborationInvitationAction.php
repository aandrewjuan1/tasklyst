<?php

namespace App\Actions\Collaboration;

use App\DataTransferObjects\Collaboration\CreateCollaborationInvitationDto;
use App\Models\CollaborationInvitation;
use App\Services\CollaborationInvitationService;

class CreateCollaborationInvitationAction
{
    public function __construct(
        private CollaborationInvitationService $invitationService
    ) {}

    public function execute(CreateCollaborationInvitationDto $dto): CollaborationInvitation
    {
        return $this->invitationService->createInvitation($dto->toServiceAttributes());
    }
}

<?php

namespace App\Actions\Collaboration;

use App\Enums\ActivityLogAction;
use App\Models\Collaboration;
use App\Models\User;
use App\Services\ActivityLogRecorder;
use App\Services\CollaborationService;

class DeleteCollaborationAction
{
    public function __construct(
        private ActivityLogRecorder $activityLogRecorder,
        private CollaborationService $collaborationService
    ) {}

    public function execute(Collaboration $collaboration, ?User $actor = null): bool
    {
        $collaboration->load('collaboratable');
        if ($collaboration->collaboratable !== null) {
            $action = $actor !== null && (int) $actor->id === (int) $collaboration->user_id
                ? ActivityLogAction::CollaboratorLeft
                : ActivityLogAction::CollaboratorRemoved;

            $this->activityLogRecorder->record(
                $collaboration->collaboratable,
                $actor,
                $action,
                [
                    'removed_user_id' => $collaboration->user_id,
                    'permission' => $collaboration->permission?->value,
                ]
            );
        }

        return $this->collaborationService->deleteCollaboration($collaboration);
    }
}

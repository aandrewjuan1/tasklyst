<?php

namespace App\Actions\Collaboration;

use App\Enums\ActivityLogAction;
use App\Enums\CollaborationPermission;
use App\Models\Collaboration;
use App\Services\ActivityLogRecorder;

class UpdateCollaborationPermissionAction
{
    public function __construct(
        private ActivityLogRecorder $activityLogRecorder
    ) {}

    public function execute(Collaboration $collaboration, CollaborationPermission $permission): Collaboration
    {
        $oldPermission = $collaboration->permission;
        $collaboration->permission = $permission;
        $collaboration->save();

        $collaboration->load('collaboratable');
        if ($collaboration->collaboratable !== null) {
            $this->activityLogRecorder->record(
                $collaboration->collaboratable,
                auth()->user(),
                ActivityLogAction::CollaboratorPermissionUpdated,
                [
                    'target_user_id' => $collaboration->user_id,
                    'from' => $oldPermission?->value,
                    'to' => $permission->value,
                ]
            );
        }

        return $collaboration;
    }
}

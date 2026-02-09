<?php

namespace App\Actions\Collaboration;

use App\Enums\CollaborationPermission;
use App\Models\Collaboration;

class UpdateCollaborationPermissionAction
{
    public function execute(Collaboration $collaboration, CollaborationPermission $permission): Collaboration
    {
        $collaboration->permission = $permission;
        $collaboration->save();

        return $collaboration;
    }
}

<?php

namespace App\Models;

use App\Enums\CollaborationInviteNotificationState;
use Illuminate\Notifications\DatabaseNotification as IlluminateDatabaseNotification;

class DatabaseNotification extends IlluminateDatabaseNotification
{
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'collaboration_invite_state' => CollaborationInviteNotificationState::class,
        ];
    }
}

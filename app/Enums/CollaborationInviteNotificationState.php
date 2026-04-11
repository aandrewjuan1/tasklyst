<?php

namespace App\Enums;

enum CollaborationInviteNotificationState: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
}

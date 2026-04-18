<?php

namespace App\Enums;

enum AssistantSchedulePlanItemStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Dismissed = 'dismissed';
}

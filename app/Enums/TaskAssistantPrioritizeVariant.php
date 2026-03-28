<?php

namespace App\Enums;

enum TaskAssistantPrioritizeVariant: string
{
    case Rank = 'rank';
    case Browse = 'browse';
    case FollowupSlice = 'followup_slice';
}

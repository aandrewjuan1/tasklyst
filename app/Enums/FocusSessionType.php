<?php

namespace App\Enums;

enum FocusSessionType: string
{
    case Work = 'work';
    case ShortBreak = 'short_break';
    case LongBreak = 'long_break';
}

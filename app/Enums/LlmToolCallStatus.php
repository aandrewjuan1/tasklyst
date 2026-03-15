<?php

namespace App\Enums;

enum LlmToolCallStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
}

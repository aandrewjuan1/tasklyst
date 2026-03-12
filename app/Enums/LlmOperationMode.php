<?php

namespace App\Enums;

enum LlmOperationMode: string
{
    case Schedule = 'schedule';
    case Prioritize = 'prioritize';
    case General = 'general';
    case Update = 'update';
    case Create = 'create';
    case ResolveDependency = 'resolve_dependency';
}

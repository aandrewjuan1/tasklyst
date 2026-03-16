<?php

namespace App\Enums;

enum TaskAssistantIntent: string
{
    case PlanNextTask = 'plan_next_task';
    case GeneralAdvice = 'general_advice';
    case MutatingAction = 'mutating_action';
}

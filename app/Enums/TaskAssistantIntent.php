<?php

namespace App\Enums;

enum TaskAssistantIntent: string
{
    case TaskPrioritization = 'task_prioritization';
    case TimeManagement = 'time_management';
    case StudyPlanning = 'study_planning';
    case ProgressReview = 'progress_review';
    case TaskManagement = 'task_management';
    case ProductivityCoaching = 'productivity_coaching';
}

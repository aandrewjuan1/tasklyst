<?php

namespace App\Enums;

enum LlmIntent: string
{
    case ScheduleTask = 'schedule_task';
    case ScheduleEvent = 'schedule_event';
    case ScheduleProject = 'schedule_project';
    case PrioritizeTasks = 'prioritize_tasks';
    case PrioritizeEvents = 'prioritize_events';
    case PrioritizeProjects = 'prioritize_projects';
    case ResolveDependency = 'resolve_dependency';
    case AdjustTaskDeadline = 'adjust_task_deadline';
    case AdjustEventTime = 'adjust_event_time';
    case AdjustProjectTimeline = 'adjust_project_timeline';
    case GeneralQuery = 'general_query';

    public function isReadonly(): bool
    {
        return match ($this) {
            self::PrioritizeEvents, self::PrioritizeProjects => true,
            default => false,
        };
    }

    public function isActionable(): bool
    {
        return ! $this->isReadonly() && $this !== self::GeneralQuery;
    }
}

<?php

namespace App\Enums;

enum LlmIntent: string
{
    case ScheduleTask = 'schedule_task';
    case ScheduleEvent = 'schedule_event';
    case ScheduleProject = 'schedule_project';
    case ScheduleTasksAndEvents = 'schedule_tasks_and_events';
    case ScheduleTasksAndProjects = 'schedule_tasks_and_projects';
    case ScheduleEventsAndProjects = 'schedule_events_and_projects';
    case ScheduleAll = 'schedule_all';
    case PrioritizeTasks = 'prioritize_tasks';
    case PrioritizeEvents = 'prioritize_events';
    case PrioritizeProjects = 'prioritize_projects';
    case PrioritizeTasksAndEvents = 'prioritize_tasks_and_events';
    case PrioritizeTasksAndProjects = 'prioritize_tasks_and_projects';
    case PrioritizeEventsAndProjects = 'prioritize_events_and_projects';
    case PrioritizeAll = 'prioritize_all';
    case ResolveDependency = 'resolve_dependency';
    case AdjustTaskDeadline = 'adjust_task_deadline';
    case AdjustEventTime = 'adjust_event_time';
    case AdjustProjectTimeline = 'adjust_project_timeline';
    case UpdateTaskProperties = 'update_task_properties';
    case UpdateEventProperties = 'update_event_properties';
    case UpdateProjectProperties = 'update_project_properties';
    case CreateTask = 'create_task';
    case CreateEvent = 'create_event';
    case CreateProject = 'create_project';
    case GeneralQuery = 'general_query';

    public function isReadonly(): bool
    {
        return match ($this) {
            self::ScheduleTasksAndEvents, self::ScheduleTasksAndProjects, self::ScheduleEventsAndProjects, self::ScheduleAll,
            self::PrioritizeEvents, self::PrioritizeProjects, self::PrioritizeTasksAndEvents,
            self::PrioritizeTasksAndProjects, self::PrioritizeEventsAndProjects, self::PrioritizeAll => true,
            default => false,
        };
    }

    public function isActionable(): bool
    {
        return ! $this->isReadonly() && $this !== self::GeneralQuery;
    }
}

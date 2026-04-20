<?php

namespace App\Services\LLM\Prioritization;

use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;

final class AssistantCandidateProvider
{
    /**
     * Build a bounded, DB-truthful candidate set for deterministic ranking.
     *
     * This is intentionally a "presentation-safe" shape (arrays), so downstream
     * flows can hydrate only selected items without leaking large DB payloads.
     *
     * Each task's `teacher_name` field is the effective label from {@see Task::resolvedTeacherName()}.
     *
     * @return array{
     *   today: string,
     *   timezone: string,
     *   tasks: list<array{id:int,title:string,subject_name:?string,teacher_name:?string,tags:list<string>,status:?string,priority:?string,complexity:?string,ends_at:?string,project_id:?int,event_id:?int,school_class_id:?int,duration_minutes:?int,is_recurring:bool}>,
     *   events: list<array{id:int,title:string,starts_at:?string,ends_at:?string,all_day:bool,status:?string}>,
     *   projects: list<array{id:int,name:string,start_at:?string,end_at:?string}>
     * }
     */
    public function candidatesForUser(
        User $user,
        int $taskLimit = 200,
        int $eventHoursAhead = 24,
        int $eventHoursBack = 6,
        int $eventLimit = 25,
        int $projectLimit = 20,
    ): array {
        $timezone = (string) config('app.timezone');
        $now = now()->setTimezone($timezone);

        $tasks = Task::query()
            ->with(['tags', 'recurringTask', 'schoolClass.teacher'])
            ->forUser($user->id)
            ->incomplete()
            ->orderByPriority()
            ->orderBy('end_datetime')
            ->limit(max(1, $taskLimit))
            ->get()
            ->map(function (Task $task): array {
                return [
                    'id' => $task->id,
                    'title' => Str::limit((string) $task->title, 160),
                    'subject_name' => $task->subject_name,
                    'teacher_name' => $task->resolvedTeacherName(),
                    'tags' => $task->tags->pluck('name')->values()->all(),
                    'status' => $task->status?->value,
                    'priority' => $task->priority?->value,
                    'complexity' => $task->complexity?->value,
                    'ends_at' => $task->end_datetime?->toIso8601String(),
                    'project_id' => $task->project_id,
                    'event_id' => $task->event_id,
                    'school_class_id' => $task->school_class_id,
                    'duration_minutes' => $task->duration,
                    'is_recurring' => $task->recurringTask !== null,
                ];
            })
            ->values()
            ->all();

        $events = Event::query()
            ->forAssistantSnapshot($user->id, $now, $eventHoursAhead, $eventLimit, $eventHoursBack)
            ->get()
            ->map(function (Event $event): array {
                return [
                    'id' => $event->id,
                    'title' => Str::limit((string) $event->title, 160),
                    'starts_at' => $event->start_datetime?->toIso8601String(),
                    'ends_at' => $event->end_datetime?->toIso8601String(),
                    'all_day' => (bool) $event->all_day,
                    'status' => $event->status?->value,
                ];
            })
            ->values()
            ->all();

        $projects = Project::query()
            ->forUser($user->id)
            ->notArchived()
            ->withIncompleteTasks()
            ->orderByStartTime()
            ->orderByName()
            ->limit(max(1, $projectLimit))
            ->get()
            ->map(function (Project $project): array {
                return [
                    'id' => $project->id,
                    'name' => Str::limit((string) $project->name, 160),
                    'start_at' => $project->start_datetime?->toIso8601String(),
                    'end_at' => $project->end_datetime?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return [
            'today' => $now->toDateString(),
            'timezone' => $timezone,
            'tasks' => $tasks,
            'events' => $events,
            'projects' => $projects,
        ];
    }
}

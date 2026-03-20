<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;

class TaskAssistantSnapshotService
{
    /**
     * Build a lightweight snapshot of the user's current state for the task assistant.
     *
     * @return array{
     *     today: string,
     *     timezone: string,
     *     tasks: list<array{id:int,title:string,subject_name:?string,teacher_name:?string,tags:list<string>,status:?string,priority:?string,ends_at:?string,project_id:?int,event_id:?int,duration_minutes:?int}>,
     *     events: list<array{id:int,title:string,starts_at:?string,ends_at:?string,all_day:bool,status:?string}>,
     *     projects: list<array{id:int,name:string,start_at:?string,end_at:?string}>
     * }
     */
    public function buildForUser(User $user, int $taskLimit = 20): array
    {
        $timezone = config('app.timezone');
        $now = now()->setTimezone($timezone);

        $tasks = Task::query()
            ->with(['tags'])
            ->forAssistantSnapshot($user->id, $now, $taskLimit)
            ->get()
            ->map(function (Task $task): array {
                return [
                    'id' => $task->id,
                    'title' => Str::limit((string) $task->title, 160),
                    'subject_name' => $task->subject_name,
                    'teacher_name' => $task->teacher_name,
                    'tags' => $task->tags->pluck('name')->values()->all(),
                    'status' => $task->status?->value,
                    'priority' => $task->priority?->value,
                    'ends_at' => $task->end_datetime?->toIso8601String(),
                    'project_id' => $task->project_id,
                    'event_id' => $task->event_id,
                    'duration_minutes' => $task->duration,
                ];
            })
            ->values()
            ->all();

        $events = Event::query()
            // Make the event window large enough for "meeting soon" style prompts.
            // Still bounded by an explicit limit to avoid oversized prompts.
            ->forAssistantSnapshot($user->id, $now, 168, 30, 24)
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
            ->forAssistantSnapshot($user->id, $now)
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

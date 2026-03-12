<?php

namespace App\Services\Llm;

use App\DataTransferObjects\Llm\LlmContextConstraints;
use App\Enums\LlmEntityType;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;

class CanonicalEntityContextFetcher
{
    public function __construct(
        private ContextConstraintApplier $constraintApplier,
    ) {}

    /**
     * @param  array<int, LlmEntityType>  $targets
     * @return array<string, mixed>
     */
    public function fetch(
        User $user,
        LlmEntityType $entityScope,
        array $targets,
        ?int $entityId,
        ?LlmContextConstraints $constraints
    ): array {
        $resolvedTargets = $entityScope === LlmEntityType::Multiple
            ? $targets
            : [$entityScope];

        if ($resolvedTargets === []) {
            $resolvedTargets = [LlmEntityType::Task];
        }

        $payload = [];
        if (in_array(LlmEntityType::Task, $resolvedTargets, true)) {
            $payload['tasks'] = $this->fetchTasks($user, $entityScope === LlmEntityType::Task ? $entityId : null, $constraints);
        }
        if (in_array(LlmEntityType::Event, $resolvedTargets, true)) {
            $payload['events'] = $this->fetchEvents($user, $entityScope === LlmEntityType::Event ? $entityId : null, $constraints);
        }
        if (in_array(LlmEntityType::Project, $resolvedTargets, true)) {
            $payload['projects'] = $this->fetchProjects($user, $entityScope === LlmEntityType::Project ? $entityId : null, $constraints);
        }

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchTasks(User $user, ?int $entityId, ?LlmContextConstraints $constraints): array
    {
        $limit = (int) config('tasklyst.context.task_limit', 12);
        $now = now();
        $timezone = config('app.timezone', 'Asia/Manila');

        $query = Task::query()
            ->forUser($user->id)
            ->incomplete()
            ->with(['recurringTask', 'project', 'event'])
            ->orderByRaw('CASE WHEN end_datetime IS NULL THEN 1 ELSE 0 END')
            ->orderBy('end_datetime');

        if ($entityId !== null) {
            $query->whereKey($entityId);
        }

        $this->constraintApplier->applyTaskConstraints($query, $constraints);

        return $query->limit($limit)->get()->map(function (Task $task) use ($now, $timezone): array {
            return [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $this->limitText($task->description, 180),
                'status' => $task->status?->value,
                'priority' => $task->priority?->value,
                'complexity' => $task->complexity?->value,
                'duration' => $task->duration,
                'start_datetime' => $task->start_datetime?->toIso8601String(),
                'end_datetime' => $task->end_datetime?->toIso8601String(),
                'start_datetime_human' => $task->start_datetime?->copy()->setTimezone($timezone)->toDayDateTimeString(),
                'end_datetime_human' => $task->end_datetime?->copy()->setTimezone($timezone)->toDayDateTimeString(),
                'project_id' => $task->project_id,
                'event_id' => $task->event_id,
                'project_name' => $task->project?->name,
                'event_title' => $task->event?->title,
                'is_recurring' => $task->recurringTask !== null,
                'is_overdue' => $task->end_datetime !== null && $task->end_datetime->lt($now),
                'due_today' => $task->end_datetime !== null && $task->end_datetime->isSameDay($now),
                'is_assessment' => $this->isAssessmentTitle($task->title),
            ];
        })->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchEvents(User $user, ?int $entityId, ?LlmContextConstraints $constraints): array
    {
        $limit = (int) config('tasklyst.context.event_limit', 10);
        $now = now();
        $timezone = config('app.timezone', 'Asia/Manila');

        $query = Event::query()
            ->forUser($user->id)
            ->notCancelled()
            ->notCompleted()
            ->with('recurringEvent')
            ->orderBy('start_datetime');

        if ($entityId !== null) {
            $query->whereKey($entityId);
        }

        $this->constraintApplier->applyEventConstraints($query, $constraints);

        return $query->limit($limit)->get()->map(function (Event $event) use ($now, $timezone): array {
            return [
                'id' => $event->id,
                'title' => $event->title,
                'description' => $this->limitText($event->description, 180),
                'start_datetime' => $event->start_datetime?->toIso8601String(),
                'end_datetime' => $event->end_datetime?->toIso8601String(),
                'start_datetime_human' => $event->start_datetime?->copy()->setTimezone($timezone)->toDayDateTimeString(),
                'end_datetime_human' => $event->end_datetime?->copy()->setTimezone($timezone)->toDayDateTimeString(),
                'all_day' => $event->all_day,
                'status' => $event->status?->value,
                'is_recurring' => $event->recurringEvent !== null,
                'starts_within_24h' => $event->start_datetime !== null && $event->start_datetime->between($now, $now->copy()->addDay()),
                'starts_within_7_days' => $event->start_datetime !== null && $event->start_datetime->between($now, $now->copy()->addDays(7)),
                'is_assessment' => $this->isAssessmentTitle($event->title),
            ];
        })->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchProjects(User $user, ?int $entityId, ?LlmContextConstraints $constraints): array
    {
        $limit = (int) config('tasklyst.context.project_limit', 5);
        $tasksPerProject = (int) config('tasklyst.context.project_tasks_limit', 10);
        $now = now();
        $timezone = config('app.timezone', 'Asia/Manila');

        $query = Project::query()
            ->forUser($user->id)
            ->notArchived()
            ->orderBy('start_datetime')
            ->orderBy('name');

        if ($entityId !== null) {
            $query->whereKey($entityId);
        }

        $this->constraintApplier->applyProjectConstraints($query, $constraints);

        return $query->limit($limit)->get()->map(function (Project $project) use ($user, $tasksPerProject, $now, $timezone): array {
            $tasks = $project->tasks()
                ->forUser($user->id)
                ->incomplete()
                ->with('recurringTask')
                ->orderByRaw('CASE WHEN end_datetime IS NULL THEN 1 ELSE 0 END')
                ->orderBy('end_datetime')
                ->limit($tasksPerProject)
                ->get()
                ->map(fn (Task $task): array => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'end_datetime' => $task->end_datetime?->toIso8601String(),
                    'end_datetime_human' => $task->end_datetime?->copy()->setTimezone($timezone)->toDayDateTimeString(),
                    'priority' => $task->priority?->value,
                    'is_recurring' => $task->recurringTask !== null,
                ])
                ->values()
                ->all();

            return [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $this->limitText($project->description, 180),
                'start_datetime' => $project->start_datetime?->toIso8601String(),
                'end_datetime' => $project->end_datetime?->toIso8601String(),
                'start_datetime_human' => $project->start_datetime?->copy()->setTimezone($timezone)->toDayDateTimeString(),
                'end_datetime_human' => $project->end_datetime?->copy()->setTimezone($timezone)->toDayDateTimeString(),
                'tasks' => $tasks,
                'has_incomplete_tasks' => $tasks !== [],
                'has_assessment_task' => collect($tasks)->contains(fn (array $task): bool => $this->isAssessmentTitle((string) ($task['title'] ?? ''))),
                'is_overdue' => $project->end_datetime !== null && $project->end_datetime->lt($now->copy()->startOfDay()),
                'starts_soon' => $project->start_datetime !== null && $project->start_datetime->between($now->copy()->startOfDay(), $now->copy()->addDays(7)->endOfDay()),
            ];
        })->values()->all();
    }

    private function isAssessmentTitle(?string $title): bool
    {
        $t = mb_strtolower(trim((string) $title));
        if ($t === '') {
            return false;
        }

        return str_contains($t, 'quiz')
            || str_contains($t, 'exam')
            || str_contains($t, 'test')
            || str_contains($t, 'take-home');
    }

    private function limitText(?string $text, int $maxLength): ?string
    {
        if ($text === null) {
            return null;
        }

        $trimmed = trim($text);
        if ($trimmed === '') {
            return null;
        }

        return Str::limit($trimmed, $maxLength, '');
    }
}

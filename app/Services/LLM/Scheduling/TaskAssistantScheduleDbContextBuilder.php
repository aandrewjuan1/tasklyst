<?php

namespace App\Services\LLM\Scheduling;

use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * DB-first deterministic scheduling context.
 *
 * This intentionally avoids using the existing assistant snapshot payload as the
 * deterministic source of truth for schedule placement.
 *
 * Output shape is designed to plug into the existing scheduling pipeline:
 * - tasks/events/projects arrays
 * - timezone/today metadata
 * - schedule_target_skips when target tasks are missing or already completed
 */
final class TaskAssistantScheduleDbContextBuilder
{
    public function __construct(
        private readonly TaskAssistantScheduleContextBuilder $scheduleContextBuilder,
    ) {}

    /**
     * @param  array<string, mixed>  $options  Common: target_entities, schedule_user_id
     * @return array{
     *   context: array<string, mixed>,
     *   snapshot: array<string, mixed>
     * }
     */
    public function buildForUser(
        User $user,
        string $userMessageContent,
        array $options = [],
    ): array {
        $timezone = (string) config('app.timezone', 'UTC');
        $now = CarbonImmutable::now($timezone);
        $today = $now->toDateString();

        // Deterministically resolve placement horizon from the user's message.
        $context = $this->scheduleContextBuilder->build(
            $userMessageContent,
            [
                'timezone' => $timezone,
                'today' => $today,
                'now' => $now->toIso8601String(),
                'refinement_anchor_date' => is_string($options['refinement_anchor_date'] ?? null)
                    ? (string) $options['refinement_anchor_date']
                    : null,
                'refinement_explicit_day_override' => is_string($options['refinement_explicit_day_override'] ?? null)
                    ? (string) $options['refinement_explicit_day_override']
                    : null,
            ]
        );

        $horizon = $context['schedule_horizon'] ?? null;
        $windowStart = $this->resolveHorizonStart($horizon, $today, $timezone);
        $windowEnd = $this->resolveHorizonEnd($horizon, $windowStart, $timezone);

        $taskLimit = max(1, (int) ($options['task_limit'] ?? 200));
        $eventsLimit = max(1, (int) ($options['event_limit'] ?? 80));
        $projectsLimit = max(1, (int) ($options['project_limit'] ?? 20));

        $tasks = $this->queryTasksForSchedule($user, $now, $taskLimit);
        $events = $this->queryEventsForBusy($user, $windowStart, $windowEnd, $eventsLimit);
        $projects = $this->queryProjectsForSchedule($user, $now, $projectsLimit);

        $scheduleTargetSkips = [];
        $targetTaskIds = $this->extractTargetTaskIds($options['target_entities'] ?? null);
        $scheduleUserId = (int) ($options['schedule_user_id'] ?? 0);
        if ($scheduleUserId > 0 && $targetTaskIds !== []) {
            [$tasks, $scheduleTargetSkips] = $this->mergeMissingOrCompletedTargetTasks(
                $user,
                $tasks,
                $targetTaskIds,
            );
        }

        $snapshot = [
            'today' => $today,
            'timezone' => $timezone,
            // Preserve the exact \"now\" used for horizon resolution so downstream
            // placement logic can avoid proposing blocks in the past for today.
            'now' => $now->toIso8601String(),
            'tasks' => $tasks,
            'events' => $events,
            'events_for_busy' => $events, // preserved by downstream logic
            'projects' => $projects,
            'schedule_target_skips' => $scheduleTargetSkips,
        ];

        return [
            'context' => $context,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $targetEntities
     * @return list<int>
     */
    private function extractTargetTaskIds(mixed $targetEntities): array
    {
        if (! is_array($targetEntities)) {
            return [];
        }

        $ids = [];
        foreach ($targetEntities as $entity) {
            if (! is_array($entity)) {
                continue;
            }
            if ((string) ($entity['entity_type'] ?? '') !== 'task') {
                continue;
            }
            $id = (int) ($entity['entity_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique($ids));

        return $ids;
    }

    /**
     * @param  array<string, mixed>|null  $horizon
     */
    private function resolveHorizonStart(mixed $horizon, string $today, string $timezone): CarbonImmutable
    {
        $tz = $timezone !== '' ? $timezone : (string) config('app.timezone', 'UTC');

        if (is_array($horizon)
            && isset($horizon['start_date'])
            && is_string((string) $horizon['start_date'])
            && trim((string) $horizon['start_date']) !== ''
        ) {
            return CarbonImmutable::parse((string) $horizon['start_date'], $tz)->startOfDay();
        }

        return CarbonImmutable::parse($today, $tz)->startOfDay();
    }

    private function resolveHorizonEnd(mixed $horizon, CarbonImmutable $windowStart, string $timezone): CarbonImmutable
    {
        $tz = $timezone !== '' ? $timezone : (string) config('app.timezone', 'UTC');

        if (is_array($horizon)
            && isset($horizon['end_date'])
            && is_string((string) $horizon['end_date'])
            && trim((string) $horizon['end_date']) !== ''
        ) {
            // End of day inclusive.
            return CarbonImmutable::parse((string) $horizon['end_date'], $tz)->endOfDay();
        }

        return $windowStart->copy()->endOfDay();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queryTasksForSchedule(User $user, CarbonImmutable $now, int $taskLimit): array
    {
        return Task::query()
            ->with(['tags', 'recurringTask', 'schoolClass.teacher'])
            ->forUser($user->id)
            ->incomplete()
            ->orderByPriority()
            ->orderBy('end_datetime')
            ->limit($taskLimit)
            ->get()
            ->map(static function (Task $task): array {
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
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queryEventsForBusy(
        User $user,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
        int $eventsLimit,
    ): array {
        /** @var Builder $query */
        $query = Event::query()
            ->forUser($user->id)
            ->notCancelled()
            ->notCompleted()
            ->whereNotNull('start_datetime')
            ->where('start_datetime', '<=', $windowEnd)
            ->where(function (Builder $q) use ($windowStart): void {
                $q->whereNull('end_datetime')
                    ->orWhere('end_datetime', '>=', $windowStart);
            })
            ->orderBy('start_datetime');

        return $query
            ->limit($eventsLimit)
            ->get()
            ->map(static function (Event $event): array {
                return [
                    'id' => (int) $event->id,
                    'title' => Str::limit((string) $event->title, 160),
                    'starts_at' => $event->start_datetime?->toIso8601String(),
                    'ends_at' => $event->end_datetime?->toIso8601String(),
                    'all_day' => (bool) $event->all_day,
                    'status' => $event->status?->value,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queryProjectsForSchedule(User $user, CarbonImmutable $now, int $projectsLimit): array
    {
        return Project::query()
            ->forAssistantSnapshot($user->id, $now, $projectsLimit)
            ->get()
            ->map(static function (Project $project): array {
                return [
                    'id' => (int) $project->id,
                    'name' => Str::limit((string) $project->name, 160),
                    'start_at' => $project->start_datetime?->toIso8601String(),
                    'end_at' => $project->end_datetime?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Merge target task IDs that are missing from the limited task candidate set.
     *
     * Returns:
     * - tasks: possibly appended with still-incomplete tasks
     * - scheduleTargetSkips: reasons for missing/completed targets
     *
     * @param  array<int, array<string, mixed>>  $tasks
     * @param  list<int>  $targetTaskIds
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function mergeMissingOrCompletedTargetTasks(
        User $user,
        array $tasks,
        array $targetTaskIds,
    ): array {
        $existing = [];
        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            $id = (int) ($task['id'] ?? 0);
            if ($id > 0) {
                $existing[$id] = true;
            }
        }

        $missingIds = array_values(array_filter(
            $targetTaskIds,
            static fn (int $id): bool => ! isset($existing[$id])
        ));

        if ($missingIds === []) {
            return [$tasks, []];
        }

        $skips = [];
        $fetched = Task::query()
            ->with(['tags', 'recurringTask', 'schoolClass.teacher'])
            ->forUser($user->id)
            ->whereIn('id', $missingIds)
            ->get()
            ->keyBy(static fn (Task $task): int => (int) $task->id);

        $appended = $tasks;

        foreach ($missingIds as $mid) {
            $task = $fetched->get($mid);
            if ($task === null) {
                $skips[] = [
                    'entity_type' => 'task',
                    'entity_id' => $mid,
                    'reason' => 'task_not_found',
                ];

                continue;
            }

            if ($task->completed_at !== null) {
                $skips[] = [
                    'entity_type' => 'task',
                    'entity_id' => $mid,
                    'reason' => 'task_completed',
                ];

                continue;
            }

            $appended[] = [
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
        }

        return [$appended, $skips];
    }
}

<?php

namespace App\Services\Llm;

use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Enums\TaskComplexity;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\AssistantThread;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Builds intent-conditioned context payloads for the LLM.
 *
 * Context contract: we send only the entity type and fields needed for the
 * classified intent. Schedule/adjust intents get time + description + IDs for
 * conflict checks; prioritize intents get a slimmer set (id, title, end_datetime,
 * priority, is_recurring, status for tasks; no description/complexity). GeneralQuery
 * gets the full payload so the model can answer list/filter questions (e.g. tasks
 * with no due date, low priority). Description max length is configurable (slim
 * for prioritize, full for others). See docs/llm-context-layer-enhancement-plan.md.
 */
class ContextBuilder
{
    private const DESCRIPTION_MAX_CHARS_SLIM = 80;

    private const DESCRIPTION_MAX_CHARS_FULL = 200;

    /**
     * Task field keys for full context (GeneralQuery, list/filter).
     *
     * @var list<string>
     */
    private const TASK_FIELDS_FULL = [
        'id', 'title', 'description', 'status', 'priority', 'complexity',
        'duration', 'start_datetime', 'end_datetime', 'project_id', 'event_id', 'is_recurring',
    ];

    /**
     * Task field keys for schedule/adjust intents (time slots, blockers).
     *
     * @var list<string>
     */
    private const TASK_FIELDS_SCHEDULE_ADJUST = [
        'id', 'title', 'description', 'status', 'priority', 'end_datetime',
        'duration', 'start_datetime', 'is_recurring', 'project_id', 'event_id',
    ];

    /**
     * Task field keys for prioritize intents (ranking only).
     *
     * @var list<string>
     */
    private const TASK_FIELDS_PRIORITIZE = [
        'id',
        'title',
        'end_datetime',
        'priority',
        'complexity',
        'duration',
        'is_recurring',
        'status',
        // Derived helper flags and relationship hints for smarter prioritization.
        'is_overdue',
        'due_today',
        'is_someday',
        'project_name',
        'event_title',
    ];

    /**
     * Event field keys for full context.
     *
     * @var list<string>
     */
    private const EVENT_FIELDS_FULL = [
        'id', 'title', 'description', 'start_datetime', 'end_datetime',
        'all_day', 'status', 'is_recurring',
    ];

    /**
     * Event field keys for schedule/adjust intents.
     *
     * @var list<string>
     */
    private const EVENT_FIELDS_SCHEDULE_ADJUST = [
        'id', 'title', 'description', 'start_datetime', 'end_datetime',
        'all_day', 'status', 'is_recurring',
    ];

    /**
     * Event field keys for prioritize intents.
     *
     * @var list<string>
     */
    private const EVENT_FIELDS_PRIORITIZE = [
        'id',
        'title',
        'start_datetime',
        'end_datetime',
        'is_recurring',
        'status',
        'all_day',
        // Derived helper flags for urgency.
        'starts_within_24h',
        'starts_within_7_days',
    ];

    /**
     * Project top-level field keys for full context.
     *
     * @var list<string>
     */
    private const PROJECT_FIELDS_FULL = [
        'id', 'name', 'description', 'start_datetime', 'end_datetime', 'tasks',
    ];

    /**
     * Project top-level field keys for prioritize (name + tasks only).
     *
     * @var list<string>
     */
    private const PROJECT_FIELDS_PRIORITIZE = [
        'id',
        'name',
        'tasks',
        // Aggregate helper flags.
        'has_incomplete_tasks',
        'is_overdue',
        'starts_soon',
    ];

    /**
     * Nested task field keys inside a project (full).
     *
     * @var list<string>
     */
    private const PROJECT_TASK_FIELDS_FULL = [
        'id', 'title', 'end_datetime', 'priority', 'is_recurring',
    ];

    /**
     * Nested task field keys inside a project (prioritize only).
     *
     * @var list<string>
     */
    private const PROJECT_TASK_FIELDS_PRIORITIZE = [
        'id',
        'title',
        'end_datetime',
        'priority',
        'is_recurring',
    ];

    /**
     * @return list<string>
     */
    private function taskFieldsForIntent(LlmIntent $intent): array
    {
        return match ($intent) {
            LlmIntent::PrioritizeTasks => self::TASK_FIELDS_PRIORITIZE,
            LlmIntent::ScheduleTask,
            LlmIntent::AdjustTaskDeadline => self::TASK_FIELDS_SCHEDULE_ADJUST,
            default => self::TASK_FIELDS_FULL,
        };
    }

    /**
     * @return list<string>
     */
    private function eventFieldsForIntent(LlmIntent $intent): array
    {
        return match ($intent) {
            LlmIntent::PrioritizeEvents => self::EVENT_FIELDS_PRIORITIZE,
            LlmIntent::ScheduleEvent,
            LlmIntent::AdjustEventTime => self::EVENT_FIELDS_SCHEDULE_ADJUST,
            default => self::EVENT_FIELDS_FULL,
        };
    }

    /**
     * @return list<string>
     */
    private function projectFieldsForIntent(LlmIntent $intent): array
    {
        return match ($intent) {
            LlmIntent::PrioritizeProjects => self::PROJECT_FIELDS_PRIORITIZE,
            default => self::PROJECT_FIELDS_FULL,
        };
    }

    /**
     * @return list<string>
     */
    private function projectTaskFieldsForIntent(LlmIntent $intent): array
    {
        return match ($intent) {
            LlmIntent::PrioritizeProjects => self::PROJECT_TASK_FIELDS_PRIORITIZE,
            default => self::PROJECT_TASK_FIELDS_FULL,
        };
    }

    private function descriptionMaxCharsForIntent(LlmIntent $intent): int
    {
        $slim = (int) config('tasklyst.context.description_max_chars_slim', self::DESCRIPTION_MAX_CHARS_SLIM);
        $full = (int) config('tasklyst.context.description_max_chars_full', self::DESCRIPTION_MAX_CHARS_FULL);

        return match ($intent) {
            LlmIntent::PrioritizeTasks,
            LlmIntent::PrioritizeEvents,
            LlmIntent::PrioritizeProjects => $slim,
            default => $full,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function taskPayloadItem(Task $task, LlmIntent $intent): array
    {
        $fields = $this->taskFieldsForIntent($intent);
        $maxDesc = $this->descriptionMaxCharsForIntent($intent);
        $out = [];
        $now = now();

        if (in_array('id', $fields, true)) {
            $out['id'] = $task->id;
        }
        if (in_array('title', $fields, true)) {
            $out['title'] = $task->title;
        }
        if (in_array('description', $fields, true)) {
            $out['description'] = $this->limitText($task->description, $maxDesc);
        }
        if (in_array('status', $fields, true)) {
            $out['status'] = $task->status?->value;
        }
        if (in_array('priority', $fields, true)) {
            $out['priority'] = $task->priority?->value;
        }
        if (in_array('complexity', $fields, true)) {
            $out['complexity'] = $task->complexity?->value;
        }
        if (in_array('duration', $fields, true)) {
            $out['duration'] = $task->duration;
        }
        if (in_array('start_datetime', $fields, true)) {
            $out['start_datetime'] = $task->start_datetime?->toIso8601String();
        }
        if (in_array('end_datetime', $fields, true)) {
            $out['end_datetime'] = $task->end_datetime?->toIso8601String();
        }
        if (in_array('project_id', $fields, true)) {
            $out['project_id'] = $task->project_id;
        }
        if (in_array('event_id', $fields, true)) {
            $out['event_id'] = $task->event_id;
        }
        if (in_array('is_recurring', $fields, true)) {
            $out['is_recurring'] = $this->isTaskRecurring($task);
        }
        if (in_array('is_overdue', $fields, true)) {
            $out['is_overdue'] = $task->end_datetime !== null && $task->end_datetime->lt($now);
        }
        if (in_array('due_today', $fields, true)) {
            $out['due_today'] = $task->end_datetime !== null
                && $task->end_datetime->isSameDay($now);
        }
        if (in_array('is_someday', $fields, true)) {
            $out['is_someday'] = $task->start_datetime === null && $task->end_datetime === null;
        }
        if (in_array('project_name', $fields, true) && $task->relationLoaded('project')) {
            $name = $task->project?->name;
            if (is_string($name) && trim($name) !== '') {
                $out['project_name'] = trim($name);
            }
        }
        if (in_array('event_title', $fields, true) && $task->relationLoaded('event')) {
            $title = $task->event?->title;
            if (is_string($title) && trim($title) !== '') {
                $out['event_title'] = trim($title);
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayloadItem(Event $event, LlmIntent $intent): array
    {
        $fields = $this->eventFieldsForIntent($intent);
        $maxDesc = $this->descriptionMaxCharsForIntent($intent);
        $out = [];
        $now = now();

        if (in_array('id', $fields, true)) {
            $out['id'] = $event->id;
        }
        if (in_array('title', $fields, true)) {
            $out['title'] = $event->title;
        }
        if (in_array('description', $fields, true)) {
            $out['description'] = $this->limitText($event->description, $maxDesc);
        }
        if (in_array('start_datetime', $fields, true)) {
            $out['start_datetime'] = $event->start_datetime?->toIso8601String();
        }
        if (in_array('end_datetime', $fields, true)) {
            $out['end_datetime'] = $event->end_datetime?->toIso8601String();
        }
        if (in_array('all_day', $fields, true)) {
            $out['all_day'] = $event->all_day;
        }
        if (in_array('status', $fields, true)) {
            $out['status'] = $event->status?->value;
        }
        if (in_array('is_recurring', $fields, true)) {
            $out['is_recurring'] = $this->isEventRecurring($event);
        }
        if (in_array('all_day', $fields, true)) {
            $out['all_day'] = $event->all_day;
        }
        if (in_array('starts_within_24h', $fields, true)) {
            $out['starts_within_24h'] = $event->start_datetime !== null
                && $event->start_datetime->gte($now)
                && $event->start_datetime->lte($now->copy()->addDay());
        }
        if (in_array('starts_within_7_days', $fields, true)) {
            $out['starts_within_7_days'] = $event->start_datetime !== null
                && $event->start_datetime->gte($now)
                && $event->start_datetime->lte($now->copy()->addDays(7));
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function projectTaskPayloadItem(Task $task, LlmIntent $intent): array
    {
        $fields = $this->projectTaskFieldsForIntent($intent);
        $out = [];

        if (in_array('id', $fields, true)) {
            $out['id'] = $task->id;
        }
        if (in_array('title', $fields, true)) {
            $out['title'] = $task->title;
        }
        if (in_array('end_datetime', $fields, true)) {
            $out['end_datetime'] = $task->end_datetime?->toIso8601String();
        }
        if (in_array('priority', $fields, true)) {
            $out['priority'] = $task->priority?->value;
        }
        if (in_array('is_recurring', $fields, true)) {
            $out['is_recurring'] = $this->isTaskRecurring($task);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function projectPayloadItem(Project $project, User $user, LlmIntent $intent, int $tasksPerProject): array
    {
        $fields = $this->projectFieldsForIntent($intent);
        $maxDesc = $this->descriptionMaxCharsForIntent($intent);
        $out = [];
        $now = now();

        if (in_array('id', $fields, true)) {
            $out['id'] = $project->id;
        }
        if (in_array('name', $fields, true)) {
            $out['name'] = $project->name;
        }
        if (in_array('description', $fields, true)) {
            $out['description'] = $this->limitText($project->description, $maxDesc);
        }
        if (in_array('start_datetime', $fields, true)) {
            $out['start_datetime'] = $project->start_datetime?->toIso8601String();
        }
        if (in_array('end_datetime', $fields, true)) {
            $out['end_datetime'] = $project->end_datetime?->toIso8601String();
        }
        if (in_array('tasks', $fields, true)) {
            $tasks = $project->tasks()
                ->forUser($user->id)
                ->incomplete()
                ->with('recurringTask')
                ->orderByRaw('CASE WHEN end_datetime IS NULL THEN 1 ELSE 0 END')
                ->orderBy('end_datetime')
                ->limit($tasksPerProject)
                ->get();

            $out['tasks'] = $tasks->map(fn (Task $t) => $this->projectTaskPayloadItem($t, $intent))->values()->all();
            if (in_array('has_incomplete_tasks', $fields, true)) {
                $out['has_incomplete_tasks'] = $tasks->isNotEmpty();
            }
        }
        if (in_array('is_overdue', $fields, true)) {
            $out['is_overdue'] = $project->end_datetime !== null
                && $project->end_datetime->lt($now->copy()->startOfDay());
        }
        if (in_array('starts_soon', $fields, true)) {
            $out['starts_soon'] = $project->start_datetime !== null
                && $project->start_datetime->gte($now->copy()->startOfDay())
                && $project->start_datetime->lte($now->copy()->addDays(7)->endOfDay());
        }

        return $out;
    }

    public function build(
        User $user,
        LlmIntent $intent,
        LlmEntityType $entityType,
        ?int $entityId,
        ?AssistantThread $thread = null,
        ?string $userMessage = null
    ): array {
        $payload = [
            'current_time' => now()->toIso8601String(),
            'timezone' => config('app.timezone', 'Asia/Manila'),
        ];

        $entityPayload = match ($entityType) {
            LlmEntityType::Task => $this->buildTaskContext($user, $intent, $entityId, $userMessage, $thread),
            LlmEntityType::Event => $this->buildEventContext($user, $intent, $entityId, $userMessage, $thread),
            LlmEntityType::Project => $this->buildProjectContext($user, $intent, $entityId, $userMessage, $thread),
            LlmEntityType::Multiple => match ($intent) {
                LlmIntent::PrioritizeTasksAndEvents => $this->buildTasksAndEventsContext($user, $thread, $userMessage),
                LlmIntent::PrioritizeTasksAndProjects => $this->buildTasksAndProjectsContext($user, $thread, $userMessage),
                LlmIntent::PrioritizeEventsAndProjects => $this->buildEventsAndProjectsContext($user, $thread, $userMessage),
                LlmIntent::PrioritizeAll => $this->buildAllContext($user, $thread, $userMessage),
                LlmIntent::ScheduleTasksAndEvents => $this->buildScheduleTasksAndEventsContext($user, $thread, $userMessage),
                LlmIntent::ScheduleTasksAndProjects => $this->buildScheduleTasksAndProjectsContext($user, $thread, $userMessage),
                LlmIntent::ScheduleEventsAndProjects => $this->buildScheduleEventsAndProjectsContext($user, $thread, $userMessage),
                LlmIntent::ScheduleAll => $this->buildScheduleAllContext($user, $thread, $userMessage),
                default => $this->buildTaskContext($user, LlmIntent::PrioritizeTasks, $entityId, $userMessage, $thread),
            },
        };

        if ($intent === LlmIntent::ResolveDependency) {
            $payload = array_merge($payload, $this->buildResolveDependencyContext($user, $thread, $userMessage));
        } else {
            $payload = array_merge($payload, $entityPayload);
        }

        if (in_array($intent, [
            LlmIntent::ScheduleTask,
            LlmIntent::AdjustTaskDeadline,
            LlmIntent::ScheduleEvent,
            LlmIntent::AdjustEventTime,
            LlmIntent::ScheduleProject,
            LlmIntent::AdjustProjectTimeline,
            LlmIntent::ScheduleTasksAndEvents,
            LlmIntent::ScheduleTasksAndProjects,
            LlmIntent::ScheduleEventsAndProjects,
            LlmIntent::ScheduleAll,
        ], true)) {
            $payload['availability'] = $this->buildAvailabilityContext($user);
        }

        $payload['conversation_history'] = $this->buildConversationHistory($thread, $userMessage);

        return $this->enforceTokenAwareness($payload);
    }

    /**
     * Build a coarse-grained availability view (busy windows) for the next few days.
     * Used by scheduling and adjust-intents so the LLM can see when the user is busy.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildAvailabilityContext(User $user): array
    {
        $days = (int) config('tasklyst.context.availability_days', 7);
        $maxWindowsPerDay = (int) config('tasklyst.context.availability_max_windows_per_day', 12);

        $startOfRange = now()->startOfDay();
        $endOfRange = $startOfRange->copy()->addDays($days)->endOfDay();

        $events = Event::query()
            ->forUser($user->id)
            ->notCancelled()
            ->notCompleted()
            ->whereNotNull('start_datetime')
            ->whereBetween('start_datetime', [$startOfRange, $endOfRange])
            ->get();

        $tasks = Task::query()
            ->forUser($user->id)
            ->incomplete()
            ->whereNotNull('start_datetime')
            ->whereNotNull('end_datetime')
            ->whereBetween('start_datetime', [$startOfRange, $endOfRange])
            ->get();

        $daysMap = [];

        for ($i = 0; $i <= $days; $i++) {
            $date = $startOfRange->copy()->addDays($i)->toDateString();
            $daysMap[$date] = [
                'date' => $date,
                'busy_windows' => [],
            ];
        }

        foreach ($events as $event) {
            if ($event->start_datetime === null) {
                continue;
            }
            $dateKey = $event->start_datetime->toDateString();
            if (! isset($daysMap[$dateKey])) {
                continue;
            }
            $daysMap[$dateKey]['busy_windows'][] = [
                'start' => $event->start_datetime->toIso8601String(),
                'end' => $event->end_datetime?->toIso8601String(),
                'label' => $event->title,
                'entity_type' => 'event',
            ];
        }

        foreach ($tasks as $task) {
            if ($task->start_datetime === null) {
                continue;
            }
            $dateKey = $task->start_datetime->toDateString();
            if (! isset($daysMap[$dateKey])) {
                continue;
            }
            $daysMap[$dateKey]['busy_windows'][] = [
                'start' => $task->start_datetime->toIso8601String(),
                'end' => $task->end_datetime?->toIso8601String(),
                'label' => $task->title,
                'entity_type' => 'task',
            ];
        }

        foreach ($daysMap as &$day) {
            if ($day['busy_windows'] === []) {
                continue;
            }

            usort($day['busy_windows'], static function (array $a, array $b): int {
                return strcmp((string) ($a['start'] ?? ''), (string) ($b['start'] ?? ''));
            });

            if (count($day['busy_windows']) > $maxWindowsPerDay) {
                $day['busy_windows'] = array_slice($day['busy_windows'], 0, $maxWindowsPerDay);
            }
        }
        unset($day);

        $out = [];
        foreach ($daysMap as $day) {
            if ($day['busy_windows'] === []) {
                continue;
            }
            $out[] = $day;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTaskContext(User $user, LlmIntent $intent, ?int $entityId, ?string $userMessage = null, ?AssistantThread $thread = null, ?int $limitOverride = null): array
    {
        $limit = $limitOverride ?? match (true) {
            $intent === LlmIntent::AdjustTaskDeadline => 5,
            $intent === LlmIntent::GeneralQuery => config('tasklyst.context.general_query_task_limit', 8),
            default => config('tasklyst.context.task_limit', 12),
        };

        $query = Task::query()
            ->forUser($user->id)
            ->incomplete()
            ->whereIn('status', [TaskStatus::ToDo->value, TaskStatus::Doing->value])
            ->with('recurringTask')
            ->orderByRaw('CASE WHEN end_datetime IS NULL THEN 1 ELSE 0 END')
            ->orderBy('end_datetime');

        if ($entityId !== null) {
            $query->where('id', $entityId);
        }

        if ($intent === LlmIntent::GeneralQuery && $userMessage !== null && $userMessage !== '') {
            $this->applyTaskListFilterToQuery($query, $userMessage);
        }

        $intentUsesPreviousList = in_array($intent, [
            LlmIntent::PrioritizeTasks,
            LlmIntent::ScheduleTask,
            LlmIntent::AdjustTaskDeadline,
            LlmIntent::GeneralQuery,
        ], true);
        if ($intentUsesPreviousList && $thread !== null && $userMessage !== null && $userMessage !== ''
            && $this->userMessageReferencesPreviousList($userMessage)) {
            $previousTitles = $this->getPreviousListTitlesFromThread($thread, LlmEntityType::Task);
            if ($previousTitles !== null && $previousTitles !== []) {
                $query->whereIn('title', $previousTitles);
            }
        }

        $tasks = $query->limit($limit)->get();

        $tasksPayload = $tasks->map(fn (Task $t) => $this->taskPayloadItem($t, $intent))->values()->all();

        return [
            'tasks' => $tasksPayload,
        ];
    }

    /**
     * Pre-filter task query for GeneralQuery list/filter intents so context contains only matching tasks.
     */
    private function applyTaskListFilterToQuery(\Illuminate\Database\Eloquent\Builder $query, string $userMessage): void
    {
        $normalized = mb_strtolower(trim($userMessage));

        // Upcoming week / next week: tasks due within the next 7 days (inclusive).
        if (
            str_contains($normalized, 'upcoming week')
            || str_contains($normalized, 'next week')
            || str_contains($normalized, 'next 7 days')
            || str_contains($normalized, 'coming week')
        ) {
            $start = now();
            $end = now()->addDays(7);

            $query->whereNotNull('end_datetime')
                ->whereBetween('end_datetime', [$start, $end]);

            return;
        }

        if (str_contains($normalized, 'low prio') || str_contains($normalized, 'low priority') || str_contains($normalized, 'low prioritiy')) {
            $query->where('priority', TaskPriority::Low->value);

            return;
        }
        if (str_contains($normalized, 'high prio') || str_contains($normalized, 'high priority')) {
            $query->where('priority', TaskPriority::High->value);

            return;
        }
        if (str_contains($normalized, 'urgent')) {
            $query->where('priority', TaskPriority::Urgent->value);

            return;
        }
        if (str_contains($normalized, 'medium prio') || str_contains($normalized, 'medium priority')) {
            $query->where('priority', TaskPriority::Medium->value);

            return;
        }
        if (str_contains($normalized, 'simple') || str_contains($normalized, 'easy')) {
            $query->byComplexity(TaskComplexity::Simple->value);

            return;
        }
        if (str_contains($normalized, 'moderate complexity') || str_contains($normalized, 'medium complexity') || str_contains($normalized, 'moderate')) {
            $query->byComplexity(TaskComplexity::Moderate->value);

            return;
        }
        if (str_contains($normalized, 'complex') || str_contains($normalized, 'hard') || str_contains($normalized, 'difficult')) {
            $query->byComplexity(TaskComplexity::Complex->value);

            return;
        }
        if (str_contains($normalized, 'no set dates') || str_contains($normalized, 'no dates')
            || str_contains($normalized, 'without dates') || str_contains($normalized, 'has no dates')) {
            $query->whereNull('start_datetime')->whereNull('end_datetime');

            return;
        }
        if (str_contains($normalized, 'no due date') || str_contains($normalized, 'no due dates')
            || str_contains($normalized, 'without due date') || str_contains($normalized, 'without deadline')) {
            $query->whereNull('end_datetime');

            return;
        }
        if (str_contains($normalized, 'no start date') || str_contains($normalized, 'without start date')) {
            $query->whereNull('start_datetime');

            return;
        }
        if (str_contains($normalized, 'recurring')) {
            $query->whereHas('recurringTask');

            return;
        }
    }

    /**
     * Pre-filter event query for GeneralQuery list/filter intents.
     */
    private function applyEventListFilterToQuery(\Illuminate\Database\Eloquent\Builder $query, string $userMessage): void
    {
        $normalized = mb_strtolower(trim($userMessage));

        if (str_contains($normalized, 'no start date') || str_contains($normalized, 'without start date')) {
            $query->whereNull('start_datetime');

            return;
        }
        if (str_contains($normalized, 'no end date') || str_contains($normalized, 'without end date')
            || str_contains($normalized, 'no due date') || str_contains($normalized, 'without due date')) {
            $query->whereNull('end_datetime');

            return;
        }
        if (str_contains($normalized, 'all-day') || str_contains($normalized, 'all day')) {
            $query->where('all_day', true);

            return;
        }
    }

    /**
     * Pre-filter project query for GeneralQuery list/filter intents.
     */
    private function applyProjectListFilterToQuery(\Illuminate\Database\Eloquent\Builder $query, string $userMessage): void
    {
        $normalized = mb_strtolower(trim($userMessage));

        if (str_contains($normalized, 'no end date') || str_contains($normalized, 'without end date')
            || str_contains($normalized, 'no due date') || str_contains($normalized, 'without due date')) {
            $query->whereNull('end_datetime');

            return;
        }
        if (str_contains($normalized, 'no start date') || str_contains($normalized, 'without start date')) {
            $query->whereNull('start_datetime');

            return;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEventContext(User $user, LlmIntent $intent, ?int $entityId, ?string $userMessage = null, ?AssistantThread $thread = null, ?int $limitOverride = null): array
    {
        $limit = $limitOverride ?? match (true) {
            $intent === LlmIntent::AdjustEventTime => 5,
            $intent === LlmIntent::GeneralQuery => config('tasklyst.context.general_query_event_limit', 6),
            default => config('tasklyst.context.event_limit', 10),
        };

        $query = Event::query()
            ->forUser($user->id)
            ->notCancelled()
            ->notCompleted()
            ->with('recurringEvent')
            ->orderBy('start_datetime');

        if ($entityId !== null) {
            $query->where('id', $entityId);
        }

        if ($intent === LlmIntent::GeneralQuery && $userMessage !== null && $userMessage !== '') {
            $this->applyEventListFilterToQuery($query, $userMessage);
        }

        $intentUsesPreviousList = in_array($intent, [
            LlmIntent::PrioritizeEvents,
            LlmIntent::ScheduleEvent,
            LlmIntent::AdjustEventTime,
            LlmIntent::GeneralQuery,
        ], true);
        if ($intentUsesPreviousList && $thread !== null && $userMessage !== null && $userMessage !== ''
            && $this->userMessageReferencesPreviousList($userMessage)) {
            $previousTitles = $this->getPreviousListTitlesFromThread($thread, LlmEntityType::Event);
            if ($previousTitles !== null && $previousTitles !== []) {
                $query->whereIn('title', $previousTitles);
            }
        }

        $events = $query->limit($limit)->get();

        $eventsPayload = $events->map(fn (Event $e) => $this->eventPayloadItem($e, $intent))->values()->all();

        return [
            'events' => $eventsPayload,
        ];
    }

    /**
     * Build combined tasks + events context for PrioritizeTasksAndEvents (multi-entity).
     * Uses reduced per-entity limits to stay within token budget.
     *
     * @return array<string, mixed>
     */
    private function buildTasksAndEventsContext(User $user, ?AssistantThread $thread = null, ?string $userMessage = null): array
    {
        $taskLimit = (int) config('tasklyst.context.multi_entity_task_limit', 6);
        $eventLimit = (int) config('tasklyst.context.multi_entity_event_limit', 6);

        $taskContext = $this->buildTaskContext(
            $user,
            LlmIntent::PrioritizeTasks,
            null,
            $userMessage,
            $thread,
            $taskLimit
        );
        $eventContext = $this->buildEventContext(
            $user,
            LlmIntent::PrioritizeEvents,
            null,
            $userMessage,
            $thread,
            $eventLimit
        );

        return [
            'tasks' => $taskContext['tasks'] ?? [],
            'events' => $eventContext['events'] ?? [],
        ];
    }

    /**
     * Build combined tasks + projects context for PrioritizeTasksAndProjects.
     *
     * @return array<string, mixed>
     */
    private function buildTasksAndProjectsContext(User $user, ?AssistantThread $thread = null, ?string $userMessage = null): array
    {
        $taskLimit = (int) config('tasklyst.context.multi_entity_task_limit', 6);
        $projectLimit = (int) config('tasklyst.context.multi_entity_project_limit', 4);
        $tasksPerProject = (int) config('tasklyst.context.multi_entity_project_tasks_limit', 3);

        $taskContext = $this->buildTaskContext($user, LlmIntent::PrioritizeTasks, null, $userMessage, $thread, $taskLimit);
        $projectContext = $this->buildProjectContext($user, LlmIntent::PrioritizeProjects, null, $userMessage, $thread, $projectLimit, $tasksPerProject);

        return [
            'tasks' => $taskContext['tasks'] ?? [],
            'projects' => $projectContext['projects'] ?? [],
        ];
    }

    /**
     * Build combined events + projects context for PrioritizeEventsAndProjects.
     *
     * @return array<string, mixed>
     */
    private function buildEventsAndProjectsContext(User $user, ?AssistantThread $thread = null, ?string $userMessage = null): array
    {
        $eventLimit = (int) config('tasklyst.context.multi_entity_event_limit', 6);
        $projectLimit = (int) config('tasklyst.context.multi_entity_project_limit', 4);
        $tasksPerProject = (int) config('tasklyst.context.multi_entity_project_tasks_limit', 3);

        $eventContext = $this->buildEventContext($user, LlmIntent::PrioritizeEvents, null, $userMessage, $thread, $eventLimit);
        $projectContext = $this->buildProjectContext($user, LlmIntent::PrioritizeProjects, null, $userMessage, $thread, $projectLimit, $tasksPerProject);

        return [
            'events' => $eventContext['events'] ?? [],
            'projects' => $projectContext['projects'] ?? [],
        ];
    }

    /**
     * Build combined tasks + events + projects context for PrioritizeAll.
     *
     * @return array<string, mixed>
     */
    private function buildAllContext(User $user, ?AssistantThread $thread = null, ?string $userMessage = null): array
    {
        $taskLimit = (int) config('tasklyst.context.multi_entity_all_task_limit', 4);
        $eventLimit = (int) config('tasklyst.context.multi_entity_all_event_limit', 4);
        $projectLimit = (int) config('tasklyst.context.multi_entity_all_project_limit', 3);
        $tasksPerProject = (int) config('tasklyst.context.multi_entity_project_tasks_limit', 3);

        $taskContext = $this->buildTaskContext($user, LlmIntent::PrioritizeTasks, null, $userMessage, $thread, $taskLimit);
        $eventContext = $this->buildEventContext($user, LlmIntent::PrioritizeEvents, null, $userMessage, $thread, $eventLimit);
        $projectContext = $this->buildProjectContext($user, LlmIntent::PrioritizeProjects, null, $userMessage, $thread, $projectLimit, $tasksPerProject);

        return [
            'tasks' => $taskContext['tasks'] ?? [],
            'events' => $eventContext['events'] ?? [],
            'projects' => $projectContext['projects'] ?? [],
        ];
    }

    /**
     * Build combined tasks + events context for ScheduleTasksAndEvents.
     *
     * @return array<string, mixed>
     */
    private function buildScheduleTasksAndEventsContext(User $user, ?AssistantThread $thread = null, ?string $userMessage = null): array
    {
        $taskLimit = (int) config('tasklyst.context.multi_entity_schedule_task_limit', 5);
        $eventLimit = (int) config('tasklyst.context.multi_entity_schedule_event_limit', 5);

        $taskContext = $this->buildTaskContext($user, LlmIntent::ScheduleTask, null, $userMessage, $thread, $taskLimit);
        $eventContext = $this->buildEventContext($user, LlmIntent::ScheduleEvent, null, $userMessage, $thread, $eventLimit);

        return [
            'tasks' => $taskContext['tasks'] ?? [],
            'events' => $eventContext['events'] ?? [],
        ];
    }

    /**
     * Build combined tasks + projects context for ScheduleTasksAndProjects.
     *
     * @return array<string, mixed>
     */
    private function buildScheduleTasksAndProjectsContext(User $user, ?AssistantThread $thread = null, ?string $userMessage = null): array
    {
        $taskLimit = (int) config('tasklyst.context.multi_entity_schedule_task_limit', 5);
        $projectLimit = (int) config('tasklyst.context.multi_entity_schedule_project_limit', 3);
        $tasksPerProject = (int) config('tasklyst.context.multi_entity_project_tasks_limit', 3);

        $taskContext = $this->buildTaskContext($user, LlmIntent::ScheduleTask, null, $userMessage, $thread, $taskLimit);
        $projectContext = $this->buildProjectContext($user, LlmIntent::ScheduleProject, null, $userMessage, $thread, $projectLimit, $tasksPerProject);

        return [
            'tasks' => $taskContext['tasks'] ?? [],
            'projects' => $projectContext['projects'] ?? [],
        ];
    }

    /**
     * Build combined events + projects context for ScheduleEventsAndProjects.
     *
     * @return array<string, mixed>
     */
    private function buildScheduleEventsAndProjectsContext(User $user, ?AssistantThread $thread = null, ?string $userMessage = null): array
    {
        $eventLimit = (int) config('tasklyst.context.multi_entity_schedule_event_limit', 5);
        $projectLimit = (int) config('tasklyst.context.multi_entity_schedule_project_limit', 3);
        $tasksPerProject = (int) config('tasklyst.context.multi_entity_project_tasks_limit', 3);

        $eventContext = $this->buildEventContext($user, LlmIntent::ScheduleEvent, null, $userMessage, $thread, $eventLimit);
        $projectContext = $this->buildProjectContext($user, LlmIntent::ScheduleProject, null, $userMessage, $thread, $projectLimit, $tasksPerProject);

        return [
            'events' => $eventContext['events'] ?? [],
            'projects' => $projectContext['projects'] ?? [],
        ];
    }

    /**
     * Build combined tasks + events + projects context for ScheduleAll.
     *
     * @return array<string, mixed>
     */
    private function buildScheduleAllContext(User $user, ?AssistantThread $thread = null, ?string $userMessage = null): array
    {
        $taskLimit = (int) config('tasklyst.context.multi_entity_schedule_all_task_limit', 4);
        $eventLimit = (int) config('tasklyst.context.multi_entity_schedule_all_event_limit', 4);
        $projectLimit = (int) config('tasklyst.context.multi_entity_schedule_all_project_limit', 3);
        $tasksPerProject = (int) config('tasklyst.context.multi_entity_project_tasks_limit', 3);

        $taskContext = $this->buildTaskContext($user, LlmIntent::ScheduleTask, null, $userMessage, $thread, $taskLimit);
        $eventContext = $this->buildEventContext($user, LlmIntent::ScheduleEvent, null, $userMessage, $thread, $eventLimit);
        $projectContext = $this->buildProjectContext($user, LlmIntent::ScheduleProject, null, $userMessage, $thread, $projectLimit, $tasksPerProject);

        return [
            'tasks' => $taskContext['tasks'] ?? [],
            'events' => $eventContext['events'] ?? [],
            'projects' => $projectContext['projects'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProjectContext(User $user, LlmIntent $intent, ?int $entityId, ?string $userMessage = null, ?AssistantThread $thread = null, ?int $limitOverride = null, ?int $tasksPerProjectOverride = null): array
    {
        $projectLimit = $limitOverride ?? ($intent === LlmIntent::GeneralQuery
            ? config('tasklyst.context.general_query_project_limit', 3)
            : config('tasklyst.context.project_limit', 5));
        $tasksPerProject = $tasksPerProjectOverride ?? ($intent === LlmIntent::GeneralQuery
            ? config('tasklyst.context.general_query_project_tasks_limit', 5)
            : config('tasklyst.context.project_tasks_limit', 10));

        $query = Project::query()
            ->forUser($user->id)
            ->notArchived();

        if ($entityId !== null) {
            $query->where('id', $entityId);
        }

        if ($intent === LlmIntent::GeneralQuery && $userMessage !== null && $userMessage !== '') {
            $this->applyProjectListFilterToQuery($query, $userMessage);
        }

        $intentUsesPreviousList = in_array($intent, [
            LlmIntent::PrioritizeProjects,
            LlmIntent::ScheduleProject,
            LlmIntent::AdjustProjectTimeline,
            LlmIntent::GeneralQuery,
        ], true);
        if ($intentUsesPreviousList && $thread !== null && $userMessage !== null && $userMessage !== ''
            && $this->userMessageReferencesPreviousList($userMessage)) {
            $previousNames = $this->getPreviousListTitlesFromThread($thread, LlmEntityType::Project);
            if ($previousNames !== null && $previousNames !== []) {
                $query->whereIn('name', $previousNames);
            }
        }

        $projects = $query->limit($projectLimit * 2)->get()->take($projectLimit);

        $projectsPayload = $projects->map(fn (Project $p) => $this->projectPayloadItem($p, $user, $intent, $tasksPerProject))->values()->all();

        return [
            'projects' => $projectsPayload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResolveDependencyContext(User $user, ?AssistantThread $thread = null, ?string $userMessage = null): array
    {
        $limit = config('tasklyst.context.resolve_dependency_entity_limit', 5);

        $taskQuery = Task::query()
            ->forUser($user->id)
            ->incomplete()
            ->whereIn('status', [TaskStatus::ToDo->value, TaskStatus::Doing->value])
            ->with('recurringTask')
            ->orderBy('end_datetime');

        $eventQuery = Event::query()
            ->forUser($user->id)
            ->notCancelled()
            ->notCompleted()
            ->with('recurringEvent')
            ->orderBy('start_datetime');

        if ($thread !== null && $userMessage !== null && $userMessage !== ''
            && $this->userMessageReferencesPreviousList($userMessage)) {
            $previousTitles = $this->getPreviousListTitlesFromThreadAllEntities($thread);
            if ($previousTitles !== null && $previousTitles !== []) {
                $taskQuery->whereIn('title', $previousTitles);
                $eventQuery->whereIn('title', $previousTitles);
            }
        }

        $tasks = $taskQuery->limit($limit)->get();
        $events = $eventQuery->limit(max(0, $limit - $tasks->count()))->get();

        $taskPayload = $tasks->map(fn (Task $t) => [
            'entity_type' => 'task',
            'id' => $t->id,
            'title' => $t->title,
            'end_datetime' => $t->end_datetime?->toIso8601String(),
            'status' => $t->status?->value,
            'is_recurring' => $this->isTaskRecurring($t),
        ])->values()->all();

        $eventPayload = $events->map(fn (Event $e) => [
            'entity_type' => 'event',
            'id' => $e->id,
            'title' => $e->title,
            'start_datetime' => $e->start_datetime?->toIso8601String(),
            'end_datetime' => $e->end_datetime?->toIso8601String(),
            'is_recurring' => $this->isEventRecurring($e),
        ])->values()->all();

        return [
            'tasks' => $taskPayload,
            'events' => $eventPayload,
        ];
    }

    /**
     * Whether the user message refers to a list from the previous assistant reply (e.g. "in those 2", "of those",
     * "schedule that event", "those tasks", "these events").
     */
    private function userMessageReferencesPreviousList(string $userMessage): bool
    {
        $normalized = mb_strtolower(trim($userMessage));
        if ($normalized === '') {
            return false;
        }

        $phrases = [
            'those 2', 'those two', 'those 3', 'those three', 'in those', 'of those', 'from those',
            'these 2', 'these two', 'in these', 'of these', 'from these',
            'from that list', 'from the list', 'that list', 'the list above', 'from above',
            'the ones you listed', 'the ones you mentioned', 'the tasks you listed', 'the events you listed',
            'the two you mentioned', 'the 2 you mentioned', 'among those', 'among these',
            'that event', 'that task', 'that project', 'this event', 'this task', 'this project',
            'that one', 'this one', 'schedule that', 'schedule this', 'adjust that', 'adjust this',
            'those tasks', 'those events', 'those projects', 'these tasks', 'these events', 'these projects',
            'about those', 'about these', 'for those', 'for these', 'with those', 'with these',
            // Explicit references to the top item from a previous list.
            'top 1', 'top one', 'top task', 'top item', 'top from that list', 'top from the list',
        ];

        foreach ($phrases as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }

        if (preg_match('/\b(those|these)\b/', $normalized)) {
            return true;
        }

        return false;
    }

    /**
     * Extract item titles (or project names) from the most recent assistant message's listed/ranked payload.
     * Used to scope context when the user refers to a previous list (e.g. "in those 2 what should i do first",
     * "schedule that event", "schedule that task").
     *
     * @return array<int, string>|null
     */
    private function getPreviousListTitlesFromThread(AssistantThread $thread, LlmEntityType $entityType): ?array
    {
        $messages = $thread->lastMessages(10);
        $lastAssistant = $messages->reverse()->first(fn ($m) => $m->role === 'assistant');
        if ($lastAssistant === null) {
            return null;
        }

        $metadata = $lastAssistant->metadata ?? [];
        $snapshot = $metadata['recommendation_snapshot'] ?? null;
        if (! is_array($snapshot)) {
            return null;
        }

        $structured = $snapshot['structured'] ?? [];
        if (! is_array($structured)) {
            return null;
        }

        $titles = [];

        if (isset($structured['listed_items']) && is_array($structured['listed_items'])) {
            foreach ($structured['listed_items'] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $label = $item['title'] ?? $item['name'] ?? null;
                if (is_string($label) && trim($label) !== '') {
                    $titles[] = trim($label);
                }
            }
        }

        if ($entityType === LlmEntityType::Task && isset($structured['ranked_tasks']) && is_array($structured['ranked_tasks'])) {
            foreach ($structured['ranked_tasks'] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $title = $item['title'] ?? null;
                if (is_string($title) && trim($title) !== '' && ! in_array(trim($title), $titles, true)) {
                    $titles[] = trim($title);
                }
            }
        }

        if ($entityType === LlmEntityType::Event && isset($structured['ranked_events']) && is_array($structured['ranked_events'])) {
            foreach ($structured['ranked_events'] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $title = $item['title'] ?? null;
                if (is_string($title) && trim($title) !== '' && ! in_array(trim($title), $titles, true)) {
                    $titles[] = trim($title);
                }
            }
        }

        if ($entityType === LlmEntityType::Project && isset($structured['ranked_projects']) && is_array($structured['ranked_projects'])) {
            foreach ($structured['ranked_projects'] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $name = $item['name'] ?? $item['title'] ?? null;
                if (is_string($name) && trim($name) !== '' && ! in_array(trim($name), $titles, true)) {
                    $titles[] = trim($name);
                }
            }
        }

        return $titles !== [] ? array_values(array_unique($titles)) : null;
    }

    /**
     * Extract all item titles from the most recent assistant message (listed_items, ranked_tasks, ranked_events, ranked_projects).
     * Used for ResolveDependency when the user refers to a previous mixed list.
     *
     * @return array<int, string>|null
     */
    private function getPreviousListTitlesFromThreadAllEntities(AssistantThread $thread): ?array
    {
        $titles = [];

        foreach ([LlmEntityType::Task, LlmEntityType::Event, LlmEntityType::Project] as $entityType) {
            $entityTitles = $this->getPreviousListTitlesFromThread($thread, $entityType);
            if ($entityTitles !== null) {
                foreach ($entityTitles as $t) {
                    if (! in_array($t, $titles, true)) {
                        $titles[] = $t;
                    }
                }
            }
        }

        return $titles !== [] ? array_values(array_unique($titles)) : null;
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function buildConversationHistory(?AssistantThread $thread, ?string $currentUserMessage = null): array
    {
        if ($thread === null) {
            return [];
        }

        $limit = (int) config('tasklyst.context.conversation_history_limit', 5);
        $maxChars = (int) config('tasklyst.context.conversation_history_message_max_chars', 200);
        $messages = $thread->lastMessages($limit + 1);

        if ($messages->isNotEmpty() && $messages->last()->role === 'user') {
            $messages = $messages->slice(0, -1);
        }

        $messages = $messages->take($limit);

        return $messages->map(fn ($m) => [
            'role' => $m->role,
            'content' => $this->limitText($m->content, $maxChars) ?? '',
        ])->values()->all();
    }

    /**
     * Ensure payload stays within token budget. Uses a safety margin so total prompt
     * (system + user + context) fits. Trims conversation_history first; then
     * truncates long text; finally strips to minimal context if still over cap.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enforceTokenAwareness(array $payload): array
    {
        $maxTokens = (int) config('tasklyst.context.max_tokens', 1200);
        $ratio = (float) config('tasklyst.context.safety_margin_ratio', 0.9);
        $cap = (int) floor($maxTokens * $ratio);

        $payload = $this->shrinkPayloadToTokenCap($payload, $cap);

        if ($this->estimateTokens($payload) <= $cap) {
            return $payload;
        }

        $payload = $this->truncateLongTextInPayload($payload, 80);
        if ($this->estimateTokens($payload) <= $cap) {
            return $payload;
        }

        $payload = $this->truncateLongTextInPayload($payload, 40);
        if ($this->estimateTokens($payload) <= $cap) {
            return $payload;
        }

        unset($payload['conversation_history']);
        if ($this->estimateTokens($payload) <= $cap) {
            return $payload;
        }

        return [
            'current_time' => $payload['current_time'] ?? now()->toIso8601String(),
        ];
    }

    /**
     * Shrink conversation_history (oldest first) until under cap. Guarantees
     * returned payload is at or under maxTokens (or no history left to remove).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function shrinkPayloadToTokenCap(array $payload, int $maxTokens): array
    {
        if ($this->estimateTokens($payload) <= $maxTokens) {
            return $payload;
        }

        if (! isset($payload['conversation_history']) || ! is_array($payload['conversation_history'])) {
            return $payload;
        }

        while ($payload['conversation_history'] !== [] && $this->estimateTokens($payload) > $maxTokens) {
            $payload['conversation_history'] = array_slice($payload['conversation_history'], 1);
        }

        return $payload;
    }

    /**
     * Recursively truncate 'description' and 'content' string values to max length.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function truncateLongTextInPayload(array $payload, int $maxLength): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->truncateLongTextInPayload($value, $maxLength);
            } elseif (is_string($value) && in_array($key, ['description', 'content'], true) && Str::length($value) > $maxLength) {
                $payload[$key] = Str::substr($value, 0, $maxLength);
            }
        }

        return $payload;
    }

    private function isTaskRecurring(Task $task): bool
    {
        if ($task->relationLoaded('recurringTask')) {
            return $task->recurringTask !== null;
        }

        return $task->recurringTask()->exists();
    }

    private function isEventRecurring(Event $event): bool
    {
        if ($event->relationLoaded('recurringEvent')) {
            return $event->recurringEvent !== null;
        }

        return $event->recurringEvent()->exists();
    }

    private function limitText(?string $text, int $maxLength): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim($text);
        if ($text === '') {
            return null;
        }

        return Str::limit($text, $maxLength, '');
    }

    /**
     * Estimate prompt tokens for the JSON-encoded payload.
     * Uses a simple heuristic: ~4 characters per token.
     */
    private function estimateTokens(array $payload): int
    {
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            return PHP_INT_MAX;
        }

        return (int) (strlen($json) / 4);
    }
}

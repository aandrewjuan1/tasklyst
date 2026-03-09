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
        $now = now();
        $timezone = config('app.timezone', 'Asia/Manila');
        $currentDate = $now->toDateString();
        $currentTimeIso = $now->toIso8601String();

        // Most important context first: user's current request, then multiturn/previous-list reference.
        $payload = [];
        if ($userMessage !== null && trim($userMessage) !== '') {
            $payload['user_current_request'] = trim($userMessage);
        }
        $payload['multiturn_instruction'] = 'This Context supports multiturn. The user_current_request may refer to your previous reply (e.g. "top task", "schedule the first one", "those 2"). When they do, use the previous_list_context and the ordered tasks/events/projects arrays—the first item is #1 (top). Never assume a different item is "top" based on urgency alone; respect the order from the previous list.';

        $payload['current_time'] = $currentTimeIso;
        $payload['current_date'] = $currentDate;
        $payload['timezone'] = $timezone;
        $payload['current_time_human'] = $now->format('Y-m-d H:i').' '.$timezone.' ('.$now->format('g:i A').')';
        $payload['scheduling_rule'] = 'Any suggested start_datetime must be strictly after current_time. Do not suggest times in the past. When the user says "later", suggest a time at least 30 minutes after current_time so they have time to get ready (e.g. if it is 3:00 PM, suggest 4:00 PM or later, not 3:15 or 3:30).';

        if (in_array($intent, [LlmIntent::PrioritizeTasks, LlmIntent::PrioritizeEvents, LlmIntent::PrioritizeProjects], true)
            && $userMessage !== null && trim($userMessage) !== '') {
            $requestedTopN = $this->extractRequestedTopN($userMessage);
            if ($requestedTopN !== null) {
                $payload['requested_top_n'] = $requestedTopN;
                $payload['requested_top_n_instruction'] = 'The user asked for the top '.$requestedTopN.' items. If the relevant context array (tasks/events/projects) contains at least '.$requestedTopN.' items, you MUST return exactly '.$requestedTopN.' ranked_* items (no fewer). Only when there are fewer than '.$requestedTopN.' items available in context may you return fewer ranked_* entries.';
            }
        }

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

        $isScheduleIntent = in_array($intent, [
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
        ], true);

        if ($intent === LlmIntent::ResolveDependency) {
            $payload = array_merge($payload, $this->buildResolveDependencyContext($user, $thread, $userMessage));
        } elseif ($isScheduleIntent) {
            $payload['availability'] = $this->buildAvailabilityContext($user);
            $payload['availability_meaning'] = 'Each date lists busy_windows (when the user is busy). Empty busy_windows = free day. Suggest start_datetime in gaps between windows or on free days.';
            $payload['date_anchor'] = 'When the user says "later", "today", "tonight", "this evening", or "after lunch", the date for start_datetime MUST be current_date ('.$payload['current_date'].'). The year in start_datetime MUST match the year in current_time. Never use a past year (e.g. 2023). Suggest start_datetime at least 30 minutes after current_time so the user has time to get ready (e.g. if current_time is 15:00, suggest 16:00 or later, not 15:15 or 15:30).';
            if ($userMessage !== null && $userMessage !== '') {
                $payload['user_scheduling_request'] = trim($userMessage);
            }
            $payload = array_merge($payload, $entityPayload);

            if ($userMessage !== null && $userMessage !== '' && $this->userMessageReferencesTopOrFirstTask($userMessage)
                && isset($payload['tasks']) && is_array($payload['tasks']) && $payload['tasks'] !== []) {
                $payload['scheduling_hint'] = 'The user asked to schedule their top 1 / top task / most important task. The first task in the tasks list is the recommended one. You MUST name that task by its exact title in recommended_action and in reasoning—never say only "your top task" or "the one due on [date]" without stating the task title. Set the title field in your JSON to that exact title.';
            }
        } else {
            $payload = array_merge($payload, $entityPayload);
        }

        $previousListContext = $this->buildPreviousListContext($thread, $entityType, $userMessage);
        if ($previousListContext !== null) {
            $payload['previous_list_context'] = $previousListContext;
        }

        $payload['conversation_history'] = $this->buildConversationHistory($thread, $userMessage);

        if ($isScheduleIntent) {
            $payload['context_authority'] = 'The tasks, events, and projects arrays in this Context are the ONLY source of truth. You MUST only reference items that appear in these arrays. Never invent or assume task, event, or project names. The "id" and "title" (or "name") in your JSON must exactly match an entry in the corresponding context array. When previous_list_context is present, the user is referring to that list—the #1 (position 1) item is what they mean by "top task" or "the first one". Use the first item in the tasks/events/projects array (it is already ordered to match the previous list).';
        }

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
            usort($day['busy_windows'], static function (array $a, array $b): int {
                return strcmp((string) ($a['start'] ?? ''), (string) ($b['start'] ?? ''));
            });

            if (count($day['busy_windows']) > $maxWindowsPerDay) {
                $day['busy_windows'] = array_slice($day['busy_windows'], 0, $maxWindowsPerDay);
            }
        }
        unset($day);

        return array_values($daysMap);
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
        $previousTitles = null;
        if ($intentUsesPreviousList && $thread !== null && $userMessage !== null && $userMessage !== ''
            && $this->userMessageReferencesPreviousList($userMessage)) {
            $previousTitles = $this->getPreviousListTitlesFromThread($thread, LlmEntityType::Task);
            if ($previousTitles !== null && $previousTitles !== []) {
                $query->whereIn('title', $previousTitles);
            }
        }

        $tasks = $query->limit($limit)->get();

        if (in_array($intent, [LlmIntent::ScheduleTask, LlmIntent::AdjustTaskDeadline], true)
            && $userMessage !== null && $userMessage !== '') {
            $mentionedFirst = $this->getTasksMentionedInMessageNotInList($user, $userMessage, $tasks);
            $mentionedIds = $mentionedFirst->pluck('id')->all();
            $remaining = $tasks->filter(fn (Task $t): bool => ! in_array($t->id, $mentionedIds, true))->values();
            $tasks = $mentionedFirst->merge($remaining);
        }

        if ($previousTitles !== null && $previousTitles !== []) {
            $tasks = $this->orderTasksByOrderedTitles($tasks, $previousTitles);
        } elseif (in_array($intent, [LlmIntent::ScheduleTask, LlmIntent::AdjustTaskDeadline], true)
            && $userMessage !== null && $userMessage !== ''
            && $this->userMessageReferencesTopOrFirstTask($userMessage)) {
            $tasks = $this->orderTasksByUrgency($tasks);
        } elseif (in_array($intent, [LlmIntent::ScheduleTask, LlmIntent::AdjustTaskDeadline], true)
            && $userMessage !== null && $userMessage !== '') {
            $tasks = $this->orderTasksByUserMessageTitleMatch($tasks, $userMessage);
        }

        $tasksPayload = $tasks->map(fn (Task $t) => $this->taskPayloadItem($t, $intent))->values()->all();

        return [
            'tasks' => $tasksPayload,
        ];
    }

    /**
     * Order tasks by urgency so the first is the natural "top" task when user says "top 1" or "top task".
     * Order: overdue first, then due today, then by priority (Urgent > High > Medium > Low), then by end_datetime ASC.
     *
     * @param  \Illuminate\Support\Collection<int, Task>  $tasks
     * @return \Illuminate\Support\Collection<int, Task>
     */
    private function orderTasksByUrgency(\Illuminate\Support\Collection $tasks): \Illuminate\Support\Collection
    {
        $now = now();
        $startOfToday = $now->copy()->startOfDay();

        $priorityWeight = function (?string $priority): int {
            return match ($priority) {
                'urgent' => 4,
                'high' => 3,
                'medium' => 2,
                'low' => 1,
                default => 0,
            };
        };

        $sorted = $tasks->sort(function (Task $a, Task $b) use ($startOfToday, $priorityWeight): int {
            $aOverdue = $a->end_datetime !== null && $a->end_datetime->lt($startOfToday);
            $bOverdue = $b->end_datetime !== null && $b->end_datetime->lt($startOfToday);
            if ($aOverdue !== $bOverdue) {
                return $aOverdue ? -1 : 1;
            }
            $aDueToday = $a->end_datetime !== null && $a->end_datetime->isSameDay($startOfToday);
            $bDueToday = $b->end_datetime !== null && $b->end_datetime->isSameDay($startOfToday);
            if ($aDueToday !== $bDueToday) {
                return $aDueToday ? -1 : 1;
            }
            $aWeight = $priorityWeight($a->priority?->value);
            $bWeight = $priorityWeight($b->priority?->value);
            if ($aWeight !== $bWeight) {
                return $bWeight <=> $aWeight;
            }
            $aEnd = $a->end_datetime?->getTimestamp() ?? PHP_INT_MAX;
            $bEnd = $b->end_datetime?->getTimestamp() ?? PHP_INT_MAX;

            return $aEnd <=> $bEnd;
        });

        return $sorted->values();
    }

    /**
     * Order tasks to match the previous ranked/list order so "top task" is consistent across intents.
     *
     * @param  \Illuminate\Support\Collection<int, Task>  $tasks
     * @param  array<int, string>  $orderedTitles
     * @return \Illuminate\Support\Collection<int, Task>
     */
    private function orderTasksByOrderedTitles(\Illuminate\Support\Collection $tasks, array $orderedTitles): \Illuminate\Support\Collection
    {
        $byTitle = [];
        foreach ($tasks as $task) {
            $key = mb_strtolower(trim($task->title));
            $byTitle[$key] = $task;
        }
        $ordered = [];
        foreach ($orderedTitles as $title) {
            $key = mb_strtolower(trim($title));
            if (isset($byTitle[$key])) {
                $ordered[] = $byTitle[$key];
                unset($byTitle[$key]);
            }
        }
        foreach ($byTitle as $task) {
            $ordered[] = $task;
        }

        return new \Illuminate\Support\Collection($ordered);
    }

    /**
     * For ScheduleTask/AdjustTaskDeadline: put the task whose title (or core) appears in the user message first.
     * Matches full core/title, or any substring of core (min 6 chars) so "antas/teorya task" matches "Antas/Teorya ng wika".
     *
     * @param  \Illuminate\Support\Collection<int, Task>  $tasks
     * @return \Illuminate\Support\Collection<int, Task>
     */
    private function orderTasksByUserMessageTitleMatch(\Illuminate\Support\Collection $tasks, string $userMessage): \Illuminate\Support\Collection
    {
        $msgLower = ' '.mb_strtolower(preg_replace('/\s+/', ' ', $userMessage)).' ';
        $minSubstringLen = 6;
        $withPos = [];
        foreach ($tasks as $task) {
            $title = trim((string) $task->title);
            $core = $this->taskTitleCoreForMatch($title);
            $pos = $this->earliestTaskTitlePositionInMessage($msgLower, $title, $core, $minSubstringLen);
            $withPos[] = ['task' => $task, 'pos' => $pos === null ? PHP_INT_MAX : $pos];
        }
        usort($withPos, static fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);

        return new \Illuminate\Support\Collection(array_column($withPos, 'task'));
    }

    /**
     * Tasks whose title is mentioned in the user message but not in the given list (e.g. not in top 12).
     * Prepending these ensures narrative resolution can find the task the user asked about.
     *
     * @param  \Illuminate\Support\Collection<int, Task>  $existingTasks
     * @return \Illuminate\Support\Collection<int, Task>
     */
    private function getTasksMentionedInMessageNotInList(User $user, string $userMessage, \Illuminate\Support\Collection $existingTasks): \Illuminate\Support\Collection
    {
        $existingIds = $existingTasks->pluck('id')->all();
        $msgLower = ' '.mb_strtolower(preg_replace('/\s+/', ' ', $userMessage)).' ';
        $minSubstringLen = 6;

        $candidates = Task::query()
            ->forUser($user->id)
            ->incomplete()
            ->whereIn('status', [TaskStatus::ToDo->value, TaskStatus::Doing->value])
            ->whereNotIn('id', $existingIds)
            ->orderByRaw('CASE WHEN end_datetime IS NULL THEN 1 ELSE 0 END')
            ->orderBy('end_datetime')
            ->limit(50)
            ->get();

        $withPos = [];
        foreach ($candidates as $task) {
            $title = trim((string) $task->title);
            $core = $this->taskTitleCoreForMatch($title);
            $pos = $this->earliestTaskTitlePositionInMessage($msgLower, $title, $core, $minSubstringLen);
            if ($pos !== null) {
                $withPos[] = ['task' => $task, 'pos' => $pos];
            }
        }
        usort($withPos, static fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);

        return new \Illuminate\Support\Collection(array_column($withPos, 'task'));
    }

    /**
     * Earliest position in message where the task title (or core, or any 6+ char substring of core) appears.
     */
    private function earliestTaskTitlePositionInMessage(string $msgLower, string $title, string $core, int $minSubstringLen): ?int
    {
        if ($core !== '' && mb_strlen($core) >= 2) {
            $needle = mb_strtolower($core);
            $p = mb_strpos($msgLower, $needle);
            if ($p !== false) {
                return $p;
            }
            // Message may say "antas/teorya task" without "ng wika" - match substring of core (min 6 chars).
            $coreLower = mb_strtolower($core);
            $len = mb_strlen($coreLower);
            $best = null;
            for ($n = $minSubstringLen; $n <= $len; $n++) {
                for ($i = 0; $i <= $len - $n; $i++) {
                    $sub = mb_substr($coreLower, $i, $n);
                    $p = mb_strpos($msgLower, $sub);
                    if ($p !== false && ($best === null || $p < $best)) {
                        $best = $p;
                    }
                }
            }
            if ($best !== null) {
                return $best;
            }
        }
        if ($title !== '') {
            $needle = mb_strtolower($title);
            $p = mb_strpos($msgLower, $needle);
            if ($p !== false) {
                return $p;
            }
        }

        return null;
    }

    /**
     * Core part of a task title for matching (strip trailing " - ...") so "Antas/Teorya ng wika" matches "Antas/Teorya ng wika - Due".
     */
    private function taskTitleCoreForMatch(string $title): string
    {
        $trimmed = trim($title);
        if (preg_match('/^(.+?)\s*[-–—]\s+/u', $trimmed, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^(.+?)\s*\([^)]*\)\s*$/u', $trimmed, $m)) {
            return trim($m[1]);
        }

        return $trimmed;
    }

    /**
     * Order events to match the previous ranked/list order so "top event" is consistent across intents.
     *
     * @param  \Illuminate\Support\Collection<int, Event>  $events
     * @param  array<int, string>  $orderedTitles
     * @return \Illuminate\Support\Collection<int, Event>
     */
    private function orderEventsByOrderedTitles(\Illuminate\Support\Collection $events, array $orderedTitles): \Illuminate\Support\Collection
    {
        $byTitle = [];
        foreach ($events as $event) {
            $key = mb_strtolower(trim($event->title));
            $byTitle[$key] = $event;
        }
        $ordered = [];
        foreach ($orderedTitles as $title) {
            $key = mb_strtolower(trim($title));
            if (isset($byTitle[$key])) {
                $ordered[] = $byTitle[$key];
                unset($byTitle[$key]);
            }
        }
        foreach ($byTitle as $event) {
            $ordered[] = $event;
        }

        return new \Illuminate\Support\Collection($ordered);
    }

    /**
     * Order projects to match the previous ranked/list order so "top project" is consistent across intents.
     *
     * @param  \Illuminate\Support\Collection<int, Project>  $projects
     * @param  array<int, string>  $orderedNames
     * @return \Illuminate\Support\Collection<int, Project>
     */
    private function orderProjectsByOrderedNames(\Illuminate\Support\Collection $projects, array $orderedNames): \Illuminate\Support\Collection
    {
        $byName = [];
        foreach ($projects as $project) {
            $key = mb_strtolower(trim($project->name));
            $byName[$key] = $project;
        }
        $ordered = [];
        foreach ($orderedNames as $name) {
            $key = mb_strtolower(trim($name));
            if (isset($byName[$key])) {
                $ordered[] = $byName[$key];
                unset($byName[$key]);
            }
        }
        foreach ($byName as $project) {
            $ordered[] = $project;
        }

        return new \Illuminate\Support\Collection($ordered);
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
        $previousTitles = null;
        if ($intentUsesPreviousList && $thread !== null && $userMessage !== null && $userMessage !== ''
            && $this->userMessageReferencesPreviousList($userMessage)) {
            $previousTitles = $this->getPreviousListTitlesFromThread($thread, LlmEntityType::Event);
            if ($previousTitles !== null && $previousTitles !== []) {
                $query->whereIn('title', $previousTitles);
            }
        }

        $events = $query->limit($limit)->get();

        if ($previousTitles !== null && $previousTitles !== []) {
            $events = $this->orderEventsByOrderedTitles($events, $previousTitles);
        }

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
        $previousNames = null;
        if ($intentUsesPreviousList && $thread !== null && $userMessage !== null && $userMessage !== ''
            && $this->userMessageReferencesPreviousList($userMessage)) {
            $previousNames = $this->getPreviousListTitlesFromThread($thread, LlmEntityType::Project);
            if ($previousNames !== null && $previousNames !== []) {
                $query->whereIn('name', $previousNames);
            }
        }

        $projects = $query->limit($projectLimit * 2)->get();

        if ($previousNames !== null && $previousNames !== []) {
            $projects = $this->orderProjectsByOrderedNames($projects, $previousNames);
        }
        $projects = $projects->take($projectLimit);

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
     * Build explicit previous-list reference for multiturn. When the user says "top task", "schedule the first one",
     * etc., this tells the LLM exactly which items are in the previous list and that position 1 = top.
     *
     * @return array<string, mixed>|null
     */
    private function buildPreviousListContext(?AssistantThread $thread, LlmEntityType $entityType, ?string $userMessage): ?array
    {
        if ($thread === null || $userMessage === null || trim($userMessage) === ''
            || ! $this->userMessageReferencesPreviousList($userMessage)) {
            return null;
        }

        $entityTypes = match ($entityType) {
            LlmEntityType::Task => [LlmEntityType::Task],
            LlmEntityType::Event => [LlmEntityType::Event],
            LlmEntityType::Project => [LlmEntityType::Project],
            LlmEntityType::Multiple => [LlmEntityType::Task, LlmEntityType::Event, LlmEntityType::Project],
        };

        $itemsInOrder = [];
        $usedEntityType = null;

        foreach ($entityTypes as $et) {
            $titles = $this->getPreviousListTitlesFromThread($thread, $et);
            if ($titles !== null && $titles !== []) {
                $usedEntityType = $et;
                $position = 1;
                foreach ($titles as $title) {
                    $itemsInOrder[] = [
                        'position' => $position,
                        'title' => $title,
                    ];
                    $position++;
                }
                break;
            }
        }

        if ($itemsInOrder === [] || $usedEntityType === null) {
            return null;
        }

        $entityLabel = match ($usedEntityType) {
            LlmEntityType::Task => 'task',
            LlmEntityType::Event => 'event',
            LlmEntityType::Project => 'project',
            default => 'item',
        };

        return [
            'entity_type' => $usedEntityType->value,
            'instruction' => 'The user is referring to the list from your previous reply. When they say "top '.$entityLabel.'", "schedule the top one", "the first one", etc., they mean position 1 below. The tasks/events/projects array in this Context is already ordered to match—the first item is #1 (top). You MUST use that exact item.',
            'items_in_order' => $itemsInOrder,
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
            'top 1', 'top one', 'top task', 'top item', 'top event', 'top project',
            'top from that list', 'top from the list', 'schedule the top', 'the top one', 'the top task',
            'the first one', 'first task', 'first event', 'first project', 'number 1', 'number one',
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
     * Whether the user message references scheduling their "top 1", "top task", "first task", or "most important" task.
     * Used to add scheduling_hint and urgency ordering so the model names the task and treats the first in list as top.
     */
    private function userMessageReferencesTopOrFirstTask(string $userMessage): bool
    {
        $normalized = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $userMessage) ?? $userMessage));
        $normalized = (string) preg_replace('/\s+/', ' ', $normalized);

        if ($normalized === '') {
            return false;
        }

        $phrases = [
            'top 1', 'top one', 'top task', 'first task', 'most important task', 'main task', 'priority task',
            'number one', 'the one due', 'my top', 'schedule my top', 'schedule the top', 'schedule my first',
            'schedule the first', 'my first task', 'the first task', 'top item', 'first one',
        ];

        foreach ($phrases as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }

        if (preg_match('/#\s*1\b|\bno\.\s*1\b/', $normalized)) {
            return true;
        }

        return false;
    }

    private function extractRequestedTopN(string $userMessage): ?int
    {
        $normalized = mb_strtolower(trim($userMessage));
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/\btop\s+(\d{1,2})\b/', $normalized, $m)) {
            $n = (int) $m[1];

            return $n > 0 ? min($n, 20) : null;
        }

        $wordMap = [
            'one' => 1,
            'two' => 2,
            'three' => 3,
            'four' => 4,
            'five' => 5,
            'six' => 6,
            'seven' => 7,
            'eight' => 8,
            'nine' => 9,
            'ten' => 10,
        ];
        if (preg_match('/\btop\s+(one|two|three|four|five|six|seven|eight|nine|ten)\b/', $normalized, $m)) {
            return $wordMap[$m[1]] ?? null;
        }

        return null;
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
     * Build conversation history for multiturn. Prioritizes the last exchange (most recent user + assistant)
     * with a higher char limit so the LLM sees the previous list/reply clearly.
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function buildConversationHistory(?AssistantThread $thread, ?string $currentUserMessage = null): array
    {
        if ($thread === null) {
            return [];
        }

        $limit = (int) config('tasklyst.context.conversation_history_limit', 6);
        $maxCharsDefault = (int) config('tasklyst.context.conversation_history_message_max_chars', 200);
        $maxCharsLastAssistant = (int) config('tasklyst.context.conversation_history_last_assistant_max_chars', 600);
        $messages = $thread->lastMessages($limit + 2);

        if ($messages->isNotEmpty() && $messages->last()->role === 'user') {
            $messages = $messages->slice(0, -1);
        }

        $messages = $messages->take($limit)->values();
        $lastAssistantIndex = null;
        foreach ($messages->reverse()->values() as $i => $m) {
            if ($m->role === 'assistant') {
                $lastAssistantIndex = $messages->count() - 1 - $i;
                break;
            }
        }

        return $messages->map(function ($m, $i) use ($maxCharsDefault, $maxCharsLastAssistant, $lastAssistantIndex) {
            $isLastAssistant = $lastAssistantIndex !== null && $i === $lastAssistantIndex;
            $maxChars = $isLastAssistant ? $maxCharsLastAssistant : $maxCharsDefault;

            return [
                'role' => $m->role,
                'content' => $this->limitText($m->content, $maxChars) ?? '',
            ];
        })->values()->all();
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

        $now = now();
        $timezone = $payload['timezone'] ?? config('app.timezone', 'Asia/Manila');
        $minimal = [
            'current_time' => $payload['current_time'] ?? $now->toIso8601String(),
            'current_date' => $payload['current_date'] ?? $now->toDateString(),
            'timezone' => $timezone,
            'current_time_human' => $payload['current_time_human'] ?? $now->format('Y-m-d H:i').' '.$timezone.' ('.$now->format('g:i A').')',
            'scheduling_rule' => $payload['scheduling_rule'] ?? 'Any suggested start_datetime must be strictly after current_time. Do not suggest times in the past.',
            'conversation_history' => [],
        ];
        if (isset($payload['user_current_request'])) {
            $minimal['user_current_request'] = $payload['user_current_request'];
        }
        if (isset($payload['previous_list_context'])) {
            $minimal['previous_list_context'] = $payload['previous_list_context'];
        }
        foreach (['tasks', 'events', 'projects'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key]) && $payload[$key] !== []) {
                $minimal[$key] = $payload[$key];
            }
        }
        if (isset($payload['availability']) && is_array($payload['availability'])) {
            $minimal['availability'] = $payload['availability'];
        }
        if (isset($payload['context_authority'])) {
            $minimal['context_authority'] = $payload['context_authority'];
        }

        return $minimal;
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

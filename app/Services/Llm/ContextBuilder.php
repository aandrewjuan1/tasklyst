<?php

namespace App\Services\Llm;

use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
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
        'id', 'title', 'end_datetime', 'priority', 'is_recurring', 'status',
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
        'id', 'title', 'start_datetime', 'end_datetime', 'is_recurring',
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
        'id', 'name', 'tasks',
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
        'id', 'title', 'end_datetime', 'priority',
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
        }

        return $out;
    }

    public function build(
        User $user,
        LlmIntent $intent,
        LlmEntityType $entityType,
        ?int $entityId,
        ?AssistantThread $thread = null
    ): array {
        $payload = [
            'current_time' => now()->toIso8601String(),
        ];

        $entityPayload = match ($entityType) {
            LlmEntityType::Task => $this->buildTaskContext($user, $intent, $entityId),
            LlmEntityType::Event => $this->buildEventContext($user, $intent, $entityId),
            LlmEntityType::Project => $this->buildProjectContext($user, $intent, $entityId),
        };

        if ($intent === LlmIntent::ResolveDependency) {
            $payload = array_merge($payload, $this->buildResolveDependencyContext($user));
        } else {
            $payload = array_merge($payload, $entityPayload);
        }

        $payload['conversation_history'] = $this->buildConversationHistory($thread);

        return $this->enforceTokenAwareness($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTaskContext(User $user, LlmIntent $intent, ?int $entityId): array
    {
        $limit = match (true) {
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

        $tasks = $query->limit($limit)->get();

        $tasksPayload = $tasks->map(fn (Task $t) => $this->taskPayloadItem($t, $intent))->values()->all();

        return [
            'tasks' => $tasksPayload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEventContext(User $user, LlmIntent $intent, ?int $entityId): array
    {
        $limit = match (true) {
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

        $events = $query->limit($limit)->get();

        $eventsPayload = $events->map(fn (Event $e) => $this->eventPayloadItem($e, $intent))->values()->all();

        return [
            'events' => $eventsPayload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProjectContext(User $user, LlmIntent $intent, ?int $entityId): array
    {
        $projectLimit = $intent === LlmIntent::GeneralQuery
            ? config('tasklyst.context.general_query_project_limit', 3)
            : config('tasklyst.context.project_limit', 5);
        $tasksPerProject = $intent === LlmIntent::GeneralQuery
            ? config('tasklyst.context.general_query_project_tasks_limit', 5)
            : config('tasklyst.context.project_tasks_limit', 10);

        $query = Project::query()
            ->forUser($user->id)
            ->notArchived();

        if ($entityId !== null) {
            $query->where('id', $entityId);
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
    private function buildResolveDependencyContext(User $user): array
    {
        $limit = config('tasklyst.context.resolve_dependency_entity_limit', 5);

        $tasks = Task::query()
            ->forUser($user->id)
            ->incomplete()
            ->whereIn('status', [TaskStatus::ToDo->value, TaskStatus::Doing->value])
            ->with('recurringTask')
            ->orderBy('end_datetime')
            ->limit($limit)
            ->get();

        $taskPayload = $tasks->map(fn (Task $t) => [
            'entity_type' => 'task',
            'id' => $t->id,
            'title' => $t->title,
            'end_datetime' => $t->end_datetime?->toIso8601String(),
            'status' => $t->status?->value,
            'is_recurring' => $this->isTaskRecurring($t),
        ])->values()->all();

        $events = Event::query()
            ->forUser($user->id)
            ->notCancelled()
            ->notCompleted()
            ->with('recurringEvent')
            ->orderBy('start_datetime')
            ->limit(max(0, $limit - $tasks->count()))
            ->get();

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
     * @return array<int, array{role: string, content: string}>
     */
    private function buildConversationHistory(?AssistantThread $thread): array
    {
        if ($thread === null) {
            return [];
        }

        $limit = config('tasklyst.context.conversation_history_limit', 5);
        $maxChars = (int) config('tasklyst.context.conversation_history_message_max_chars', 200);
        $messages = $thread->lastMessages($limit);

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

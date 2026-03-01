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

class ContextBuilder
{
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

        $tasksPayload = $tasks->map(fn (Task $t) => [
            'id' => $t->id,
            'title' => $t->title,
            'description' => $this->limitText($t->description, 200),
            'status' => $t->status?->value,
            'priority' => $t->priority?->value,
            'complexity' => $t->complexity?->value,
            'duration' => $t->duration,
            'start_datetime' => $t->start_datetime?->toIso8601String(),
            'end_datetime' => $t->end_datetime?->toIso8601String(),
            'project_id' => $t->project_id,
            'event_id' => $t->event_id,
            'is_recurring' => $this->isTaskRecurring($t),
        ])->values()->all();

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

        $eventsPayload = $events->map(fn (Event $e) => [
            'id' => $e->id,
            'title' => $e->title,
            'description' => $this->limitText($e->description, 200),
            'start_datetime' => $e->start_datetime?->toIso8601String(),
            'end_datetime' => $e->end_datetime?->toIso8601String(),
            'all_day' => $e->all_day,
            'status' => $e->status?->value,
            'is_recurring' => $this->isEventRecurring($e),
        ])->values()->all();

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

        $projectsPayload = $projects->map(function (Project $p) use ($user, $tasksPerProject): array {
            $tasks = $p->tasks()
                ->forUser($user->id)
                ->incomplete()
                ->with('recurringTask')
                ->orderByRaw('CASE WHEN end_datetime IS NULL THEN 1 ELSE 0 END')
                ->orderBy('end_datetime')
                ->limit($tasksPerProject)
                ->get();

            return [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $this->limitText($p->description, 200),
                'start_datetime' => $p->start_datetime?->toIso8601String(),
                'end_datetime' => $p->end_datetime?->toIso8601String(),
                'tasks' => $tasks->map(fn (Task $t) => [
                    'id' => $t->id,
                    'title' => $t->title,
                    'end_datetime' => $t->end_datetime?->toIso8601String(),
                    'priority' => $t->priority?->value,
                    'is_recurring' => $this->isTaskRecurring($t),
                ])->values()->all(),
            ];
        })->values()->all();

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

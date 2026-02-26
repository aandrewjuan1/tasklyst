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

        if ($intent === LlmIntent::GeneralQuery) {
            $payload['conversation_history'] = $this->buildConversationHistory($thread);

            return $this->enforceTokenAwareness($payload);
        }

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
        $limit = $intent === LlmIntent::AdjustTaskDeadline ? 5 : config('tasklyst.context.task_limit', 12);

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
            'description' => $t->description ? substr($t->description, 0, 200) : null,
            'status' => $t->status?->value,
            'priority' => $t->priority?->value,
            'complexity' => $t->complexity?->value,
            'duration' => $t->duration,
            'start_datetime' => $t->start_datetime?->toIso8601String(),
            'end_datetime' => $t->end_datetime?->toIso8601String(),
            'project_id' => $t->project_id,
            'event_id' => $t->event_id,
            'is_recurring' => $t->relationLoaded('recurringTask') ? $t->recurringTask !== null : (bool) $t->recurringTask?->exists(),
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
        $limit = in_array($intent, [LlmIntent::AdjustEventTime], true) ? 5 : config('tasklyst.context.event_limit', 10);

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
            'description' => $e->description ? substr($e->description, 0, 200) : null,
            'start_datetime' => $e->start_datetime?->toIso8601String(),
            'end_datetime' => $e->end_datetime?->toIso8601String(),
            'all_day' => $e->all_day,
            'status' => $e->status?->value,
            'is_recurring' => $e->relationLoaded('recurringEvent') ? $e->recurringEvent !== null : (bool) $e->recurringEvent?->exists(),
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
        $projectLimit = config('tasklyst.context.project_limit', 5);
        $tasksPerProject = config('tasklyst.context.project_tasks_limit', 10);

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
                'description' => $p->description ? substr($p->description, 0, 200) : null,
                'start_datetime' => $p->start_datetime?->toIso8601String(),
                'end_datetime' => $p->end_datetime?->toIso8601String(),
                'tasks' => $tasks->map(fn (Task $t) => [
                    'id' => $t->id,
                    'title' => $t->title,
                    'end_datetime' => $t->end_datetime?->toIso8601String(),
                    'priority' => $t->priority?->value,
                    'is_recurring' => (bool) $t->recurringTask?->exists(),
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
            'is_recurring' => (bool) $t->recurringTask?->exists(),
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
            'is_recurring' => (bool) $e->recurringEvent?->exists(),
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
        $messages = $thread->lastMessages($limit);

        return $messages->map(fn ($m) => [
            'role' => $m->role,
            'content' => $m->content,
        ])->values()->all();
    }

    /**
     * Ensure payload stays within token budget. Simple heuristic: ~4 chars per token.
     * Trims conversation_history first; if still over cap, truncates long text fields.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enforceTokenAwareness(array $payload): array
    {
        $maxTokens = config('tasklyst.context.max_tokens', 1200);
        $payload = $this->trimPayloadToTokenCap($payload, $maxTokens);

        $json = json_encode($payload);
        $estimatedTokens = (int) (strlen($json ?? '') / 4);

        if ($estimatedTokens <= $maxTokens) {
            return $payload;
        }

        $payload = $this->truncateLongTextInPayload($payload, 80);
        $json = json_encode($payload);
        $estimatedTokens = (int) (strlen($json ?? '') / 4);

        if ($estimatedTokens <= $maxTokens) {
            return $payload;
        }

        $payload = $this->truncateLongTextInPayload($payload, 40);

        return $payload;
    }

    /**
     * Trim conversation_history to reduce token count when over cap.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function trimPayloadToTokenCap(array $payload, int $maxTokens): array
    {
        $json = json_encode($payload);
        $estimatedTokens = (int) (strlen($json ?? '') / 4);

        if ($estimatedTokens <= $maxTokens) {
            return $payload;
        }

        if (isset($payload['conversation_history']) && count($payload['conversation_history']) > 0) {
            $drop = min(count($payload['conversation_history']), (int) ceil(($estimatedTokens - $maxTokens) / 100));
            $payload['conversation_history'] = array_slice($payload['conversation_history'], $drop);
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
            } elseif (is_string($value) && in_array($key, ['description', 'content'], true) && strlen($value) > $maxLength) {
                $payload[$key] = substr($value, 0, $maxLength);
            }
        }

        return $payload;
    }
}

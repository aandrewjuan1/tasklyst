<?php

namespace App\Tools\LLM\TaskAssistant;

use App\Models\Task;
use Prism\Prism\Tool;

class ListTasksTool extends Tool
{
    public function __construct(protected readonly \App\Models\User $user)
    {
        parent::__construct();

        $this->as('list_tasks')
            ->for('List tasks for the user. Use when the user wants to see their tasks. Optionally filter by project or event.')
            ->withNumberParameter('projectId', 'Optional project ID to filter tasks', false)
            ->withNumberParameter('eventId', 'Optional event ID to filter tasks', false)
            ->withNumberParameter('limit', 'Max number of tasks to return (default 50)', false)
            ->using($this);
    }

    public function __invoke(mixed ...$args): string
    {
        $params = $this->normalizeParams(...$args);
        $query = Task::query()
            ->forUser($this->user->id)
            ->incomplete()
            ->orderByDesc('updated_at');
        if (isset($params['projectId'])) {
            $query->forProject((int) $params['projectId']);
        }
        if (isset($params['eventId'])) {
            $query->forEvent((int) $params['eventId']);
        }
        $limit = isset($params['limit']) ? min(100, (int) $params['limit']) : 50;
        $tasks = $query
            ->with(['tags'])
            ->limit($limit)
            ->get();

        $list = $tasks->map(fn (Task $task): array => [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status?->value ?? $task->status,
            'priority' => $task->priority?->value ?? $task->priority,
            'project_id' => $task->project_id,
            'event_id' => $task->event_id,
            'teacher_name' => $task->teacher_name,
            'subject_name' => $task->subject_name,
            'start_datetime' => $task->start_datetime?->toIso8601String(),
            'end_datetime' => $task->end_datetime?->toIso8601String(),
            'duration_minutes' => (int) ($task->duration ?? 0),
            'tags' => $task->tags->pluck('name')->values()->all(),
        ])->values()->all();

        return json_encode(['ok' => true, 'tasks' => $list]);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeParams(mixed ...$args): array
    {
        if (count($args) === 1 && is_array($args[0]) && ! array_is_list($args[0])) {
            return $args[0];
        }
        if (count($args) === 1 && is_array($args[0])) {
            return $args[0];
        }
        $params = [];
        foreach ($args as $key => $value) {
            if (! is_int($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }
}

<?php

use App\Models\Task;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantSnapshotService;

it('withTasksFromProposals appends tasks missing from the snapshot for schedule validation', function (): void {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create(['title' => 'Only in proposals']);

    $snapshot = [
        'tasks' => [],
        'events' => [],
        'projects' => [],
    ];

    $proposals = [
        [
            'entity_type' => 'task',
            'entity_id' => $task->id,
            'title' => $task->title,
            'start_datetime' => '2026-03-30T18:00:00+00:00',
            'end_datetime' => '2026-03-30T19:00:00+00:00',
            'duration_minutes' => 60,
        ],
    ];

    $service = app(TaskAssistantSnapshotService::class);
    $merged = $service->withTasksFromProposals($user, $snapshot, $proposals);

    $ids = array_map(fn (array $t): int => (int) ($t['id'] ?? 0), $merged['tasks'] ?? []);
    expect($ids)->toContain($task->id);
});

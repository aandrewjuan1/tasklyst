<?php

use App\Enums\MessageRole;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantService;

it('collects pending proposal intervals from prior schedule drafts for busy merging', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Draft schedule',
        'metadata' => [
            'schedule' => [
                'schema_version' => 2,
                'proposals' => [
                    [
                        'proposal_id' => 'p1',
                        'status' => 'pending',
                        'entity_type' => 'task',
                        'entity_id' => 10,
                        'title' => 'Task A',
                        'start_datetime' => '2026-04-23T08:00:00+08:00',
                        'end_datetime' => '2026-04-23T08:45:00+08:00',
                    ],
                    [
                        'proposal_id' => 'p2',
                        'status' => 'pending',
                        'entity_type' => 'task',
                        'entity_id' => 11,
                        'title' => 'Task B',
                        'start_datetime' => '2026-04-23T09:00:00+08:00',
                        'end_datetime' => '2026-04-23T09:45:00+08:00',
                    ],
                ],
            ],
        ],
    ]);

    $service = app(TaskAssistantService::class);
    $method = new ReflectionMethod(TaskAssistantService::class, 'collectPendingScheduleBusyIntervals');
    $method->setAccessible(true);

    $busy = $method->invoke($service, $thread, 0);

    expect($busy)->toHaveCount(2);
    expect($busy[0]['title'] ?? null)->toContain('pending_schedule:');
    expect((string) ($busy[0]['starts_at'] ?? ''))->not->toBe('');
    expect((string) ($busy[0]['ends_at'] ?? ''))->not->toBe('');
});

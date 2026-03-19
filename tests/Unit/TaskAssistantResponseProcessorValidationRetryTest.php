<?php

use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantPromptData;
use App\Services\LLM\TaskAssistant\TaskAssistantResponseProcessor;
use App\Services\LLM\TaskAssistant\TaskAssistantSnapshotService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

it('rejects daily_schedule blocks with task_id not present in snapshot', function (): void {
    $processor = new TaskAssistantResponseProcessor(
        new TaskAssistantPromptData,
        mock(TaskAssistantSnapshotService::class)
    );

    $snapshot = [
        'today' => now()->toDateString(),
        'tasks' => [
            ['id' => 1, 'title' => 'Task 1'],
        ],
        'events' => [],
        'projects' => [],
    ];

    $invalidData = [
        'blocks' => [
            [
                'start_time' => '09:00',
                'end_time' => '10:00',
                'task_id' => 999,
                'event_id' => null,
                'label' => 'Invalid block',
                'note' => 'Should fail snapshot membership',
            ],
        ],
        'summary' => 'Some summary',
    ];

    $result = $processor->processResponse('daily_schedule', $invalidData, $snapshot);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->not->toBeEmpty();
    expect(implode(' ', $result['errors']))->toContain('blocks.0.task_id');
});

it('retries daily_schedule when validation fails, then returns validated content', function (): void {
    $processor = new TaskAssistantResponseProcessor(
        new TaskAssistantPromptData,
        mock(TaskAssistantSnapshotService::class)
    );

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $snapshot = [
        'today' => now()->toDateString(),
        'tasks' => [
            ['id' => 1, 'title' => 'Task 1'],
        ],
        'events' => [],
        'projects' => [],
    ];

    $invalidData = [
        'blocks' => [
            [
                'start_time' => '09:00',
                'end_time' => '10:00',
                'task_id' => 999,
                'event_id' => null,
                'label' => 'Invalid block',
                'note' => 'Triggers retry',
            ],
        ],
        'summary' => 'Some summary',
    ];

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'blocks' => [
                    [
                        'start_time' => '09:00',
                        'end_time' => '10:00',
                        'task_id' => 999,
                        'event_id' => null,
                        'label' => 'Still invalid block',
                        'note' => 'Retry attempt output',
                    ],
                ],
                'summary' => 'Retry attempt (still invalid)',
            ])
            ->withUsage(new Usage(3, 7)),
        StructuredResponseFake::make()
            ->withStructured([
                'blocks' => [
                    [
                        'start_time' => '09:00',
                        'end_time' => '10:00',
                        'task_id' => 1,
                        'event_id' => null,
                        'label' => 'Valid block',
                        'note' => 'Second attempt output',
                    ],
                ],
                'summary' => 'Valid schedule',
            ])
            ->withUsage(new Usage(4, 9)),
    ]);

    $result = $processor->processResponse(
        flow: 'daily_schedule',
        data: $invalidData,
        snapshot: $snapshot,
        thread: $thread,
        originalUserMessage: 'Plan my day'
    );

    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
    expect($result['structured_data']['blocks'][0]['task_id'] ?? null)->toBe(1);

    // Prevent Prism fakes from leaking into subsequent tests.
    Prism::fake([]);
});

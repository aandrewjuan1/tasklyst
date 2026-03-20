<?php

use App\Services\LLM\TaskAssistant\TaskAssistantPromptData;
use App\Services\LLM\TaskAssistant\TaskAssistantResponseProcessor;
use App\Services\LLM\TaskAssistant\TaskAssistantSnapshotService;

it('validates and formats task_list flow responses', function (): void {
    $processor = new TaskAssistantResponseProcessor(
        mock(TaskAssistantPromptData::class),
        mock(TaskAssistantSnapshotService::class)
    );

    $now = now();
    $todayIso = $now->format('c');
    $tomorrowIso = $now->copy()->addDay()->format('c');

    $data = [
        'summary' => 'Your top tasks.',
        'limit_used' => 2,
        'items' => [
            [
                'task_id' => 1,
                'title' => 'Math homework',
                'due_date' => $todayIso,
                'priority' => 'urgent',
                'reason' => 'Selected as Urgent priority task due today',
                'next_steps' => [
                    'Start with a 20-minute focused session',
                    'Break it into 2-3 smallest subtasks and begin',
                    'Set a short follow-up time',
                ],
            ],
            [
                'task_id' => 2,
                'title' => 'History reading',
                'due_date' => $tomorrowIso,
                'priority' => 'medium',
                'reason' => 'Selected as Medium priority task due tomorrow',
                'next_steps' => [
                    'Define a small deliverable for tomorrow',
                    'Prep resources and outline first steps',
                    'Choose a start time and set a timer',
                ],
            ],
        ],
    ];

    $result = $processor->processResponse(flow: 'task_list', data: $data, snapshot: []);

    expect($result['valid'])->toBeTrue();
    expect($result['formatted_content'])->toContain('Math homework');
    expect(strtolower($result['formatted_content']))->toContain('due today');
    expect($result['formatted_content'])->toContain('due tomorrow');
    expect($result['formatted_content'])->toContain('I picked this because');
    expect($result['formatted_content'])->toContain('Next,');
});

it('rejects invalid task_list responses with empty items', function (): void {
    $processor = new TaskAssistantResponseProcessor(
        mock(TaskAssistantPromptData::class),
        mock(TaskAssistantSnapshotService::class)
    );

    $result = $processor->processResponse(
        flow: 'task_list',
        data: [
            'items' => [],
        ],
        snapshot: []
    );

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->not->toBeEmpty();
});

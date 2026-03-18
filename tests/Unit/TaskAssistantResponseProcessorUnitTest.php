<?php

use App\Services\TaskAssistantResponseProcessor;

it('validates advisory data structure correctly', function () {
    $processor = new TaskAssistantResponseProcessor(
        mock(\App\Services\TaskAssistantPromptData::class),
        mock(\App\Services\TaskAssistantSnapshotService::class)
    );

    // Test valid data
    $validData = [
        'summary' => 'This is a valid summary that meets the minimum requirements.',
        'bullets' => [
            'This is a valid bullet point that meets minimum length.',
            'Another valid bullet point with sufficient content.',
        ],
        'follow_ups' => [
            'Would you like help with specific tasks?',
        ],
    ];

    $result = $processor->processResponse('advisory', $validData);

    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
    expect($result['formatted_content'])->toContain('This is a valid summary');
    expect($result['formatted_content'])->toContain('Key points to remember:');
});

it('rejects advisory data with insufficient summary', function () {
    $processor = new TaskAssistantResponseProcessor(
        mock(\App\Services\TaskAssistantPromptData::class),
        mock(\App\Services\TaskAssistantSnapshotService::class)
    );

    $invalidData = [
        'summary' => 'Too short', // Less than 5 words
        'bullets' => [
            'This bullet point is long enough to pass validation.',
        ],
    ];

    $result = $processor->processResponse('advisory', $invalidData);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('0');
    expect($result['errors'][0])->toContain('at least 3 words');
});

it('rejects advisory data with insufficient bullet points', function () {
    $processor = new TaskAssistantResponseProcessor(
        mock(\App\Services\TaskAssistantPromptData::class),
        mock(\App\Services\TaskAssistantSnapshotService::class)
    );

    $invalidData = [
        'summary' => 'This summary is long enough to pass validation.',
        'bullets' => [
            'Short', // Too short
            'Another short', // Too short
        ],
    ];

    $result = $processor->processResponse('advisory', $invalidData);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('0');
    // Laravel validator error message format
    expect($result['errors'][0])->toContain('10 characters');
});

it('formats advisory data into student-friendly text', function () {
    $processor = new TaskAssistantResponseProcessor(
        mock(\App\Services\TaskAssistantPromptData::class),
        mock(\App\Services\TaskAssistantSnapshotService::class)
    );

    $data = [
        'summary' => 'Focus on your most important tasks first to stay productive.',
        'bullets' => [
            'Complete the math assignment due tomorrow',
            'Review science notes for upcoming test',
            'Schedule study time for complex topics',
        ],
        'follow_ups' => [
            'Need help breaking down large tasks?',
            'Want assistance with time management?',
        ],
    ];

    $result = $processor->processResponse('advisory', $data);

    expect($result['formatted_content'])->toBe(
        "Focus on your most important tasks first to stay productive.\n\n" .
        "Key points to remember:\n" .
        "• Complete the math assignment due tomorrow\n" .
        "• Review science notes for upcoming test\n" .
        "• Schedule study time for complex topics\n\n" .
        "Would you like help with:\n" .
        "– Need help breaking down large tasks?\n" .
        "– Want assistance with time management?"
    );
});

it('validates daily schedule time format', function () {
    $processor = new TaskAssistantResponseProcessor(
        mock(\App\Services\TaskAssistantPromptData::class),
        mock(\App\Services\TaskAssistantSnapshotService::class)
    );

    $validData = [
        'blocks' => [
            [
                'start_time' => '09:00',
                'end_time' => '10:30',
                'task_id' => null,
                'event_id' => null,
                'label' => 'Study Time',
                'reason' => 'Focused morning study session.',
            ],
        ],
    ];

    $result = $processor->processResponse('daily_schedule', $validData);

    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
});

it('rejects invalid time format in daily schedule', function () {
    $processor = new TaskAssistantResponseProcessor(
        mock(\App\Services\TaskAssistantPromptData::class),
        mock(\App\Services\TaskAssistantSnapshotService::class)
    );

    $invalidData = [
        'blocks' => [
            [
                'start_time' => '25:00', // Invalid 24-hour format
                'end_time' => '26:00',
                'task_id' => null,
                'event_id' => null,
                'label' => 'Invalid Time',
                'reason' => 'This should fail validation.',
            ],
        ],
    ];

    $result = $processor->processResponse('daily_schedule', $invalidData);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->not->toBeEmpty();
});

it('validates study plan data structure', function () {
    $processor = new TaskAssistantResponseProcessor(
        mock(\App\Services\TaskAssistantPromptData::class),
        mock(\App\Services\TaskAssistantSnapshotService::class)
    );

    $validData = [
        'items' => [
            [
                'label' => 'Study algebra concepts thoroughly',
                'task_id' => null,
                'estimated_minutes' => 45,
                'reason' => 'Foundation for advanced problems.',
            ],
        ],
        'summary' => 'Comprehensive study plan focusing on fundamentals.',
    ];

    $result = $processor->processResponse('study_plan', $validData);

    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
});

it('formats study plan with time estimates', function () {
    $processor = new TaskAssistantResponseProcessor(
        mock(\App\Services\TaskAssistantPromptData::class),
        mock(\App\Services\TaskAssistantSnapshotService::class)
    );

    $data = [
        'items' => [
            [
                'label' => 'Review algebra concepts',
                'task_id' => null,
                'estimated_minutes' => 30,
                'reason' => 'Foundation for advanced problems.',
            ],
            [
                'label' => 'Practice problem sets',
                'task_id' => null,
                'estimated_minutes' => 45,
                'reason' => 'Apply concepts practically.',
            ],
        ],
        'summary' => 'Balanced approach to theory and practice.',
    ];

    $result = $processor->processResponse('study_plan', $data);

    expect($result['formatted_content'])->toContain('Your study plan:');
    expect($result['formatted_content'])->toContain('1. Review algebra concepts (30 min)');
    expect($result['formatted_content'])->toContain('2. Practice problem sets (45 min)');
    expect($result['formatted_content'])->toContain('Focus: Foundation for advanced');
});

it('handles empty data gracefully', function () {
    $processor = new TaskAssistantResponseProcessor(
        mock(\App\Services\TaskAssistantPromptData::class),
        mock(\App\Services\TaskAssistantSnapshotService::class)
    );

    $result = $processor->processResponse('advisory', []);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->not->toBeEmpty();
    // When no retry context, it should format the empty data which results in minimal content
    expect($result['formatted_content'])->toBe('');
});

it('provides fallback formatting for unknown flows', function () {
    $processor = new TaskAssistantResponseProcessor(
        mock(\App\Services\TaskAssistantPromptData::class),
        mock(\App\Services\TaskAssistantSnapshotService::class)
    );

    $data = [
        'message' => 'Custom message for unknown flow.',
    ];

    $result = $processor->processResponse('unknown_flow', $data);

    expect($result['valid'])->toBeTrue();
    expect($result['formatted_content'])->toBe('Custom message for unknown flow.');
});

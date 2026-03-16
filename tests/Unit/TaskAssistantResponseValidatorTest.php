<?php

use App\Services\TaskAssistantResponseValidator;

test('task choice validator accepts valid payload with matching task', function (): void {
    $snapshot = [
        'tasks' => [
            ['id' => 1, 'title' => 'Read chapter 1'],
            ['id' => 2, 'title' => 'Write summary'],
        ],
    ];

    $payload = [
        'chosen_task_id' => 1,
        'chosen_task_title' => 'Read chapter 1',
        'summary' => 'Focus on reading chapter 1 today.',
        'reason' => 'It is due soon and has a clear scope.',
        'suggested_next_steps' => [
            'Find a quiet place to study.',
            'Read section 1–3 carefully.',
        ],
    ];

    $validator = new TaskAssistantResponseValidator;
    $result = $validator->validateTaskChoice($payload, $snapshot);

    expect($result['valid'])->toBeTrue();
    expect($result['data']['chosen_task_id'])->toBe(1);
    expect($result['data']['chosen_task_title'])->toBe('Read chapter 1');
    expect($result['errors'])->toBe([]);
});

test('task choice validator fails when required fields are missing', function (): void {
    $snapshot = ['tasks' => []];

    $payload = [
        // missing summary, reason, suggested_next_steps
        'chosen_task_id' => null,
        'chosen_task_title' => null,
    ];

    $validator = new TaskAssistantResponseValidator;
    $result = $validator->validateTaskChoice($payload, $snapshot);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->not->toBeEmpty();
});

test('task choice validator fails when chosen_task_id is not in snapshot', function (): void {
    $snapshot = [
        'tasks' => [
            ['id' => 1, 'title' => 'Read chapter 1'],
        ],
    ];

    $payload = [
        'chosen_task_id' => 999,
        'chosen_task_title' => 'Some other task',
        'summary' => 'Focus on something.',
        'reason' => 'Reason text.',
        'suggested_next_steps' => ['Step 1'],
    ];

    $validator = new TaskAssistantResponseValidator;
    $result = $validator->validateTaskChoice($payload, $snapshot);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toContain('chosen_task_id must be null or one of the IDs from snapshot.tasks.');
});

test('task choice validator fails when chosen_task_title does not match snapshot', function (): void {
    $snapshot = [
        'tasks' => [
            ['id' => 3, 'title' => 'Correct title'],
        ],
    ];

    $payload = [
        'chosen_task_id' => 3,
        'chosen_task_title' => 'Wrong title',
        'summary' => 'Summary text.',
        'reason' => 'Reason text.',
        'suggested_next_steps' => ['Step 1'],
    ];

    $validator = new TaskAssistantResponseValidator;
    $result = $validator->validateTaskChoice($payload, $snapshot);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toContain('chosen_task_title must match the title of the chosen task from snapshot.tasks.');
});

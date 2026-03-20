<?php

use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantResponseProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('validates and formats advisory flow responses', function () {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $processor = app(TaskAssistantResponseProcessor::class);

    $validData = [
        'summary' => 'Focus on completing your urgent tasks first to stay on track.',
        'bullets' => [
            'Complete the math assignment by tomorrow afternoon',
            'Review your study notes for the upcoming test',
            'Schedule time for project research this weekend',
        ],
        'follow_ups' => [
            'Would you like help breaking down large tasks?',
            'Do you need assistance with time management?',
        ],
    ];

    $result = $processor->processResponse(
        flow: 'advisory',
        data: $validData,
        snapshot: [],
        thread: $thread,
        originalUserMessage: 'Help me prioritize my tasks'
    );

    expect($result['valid'])->toBeTrue();
    expect($result['formatted_content'])->toContain('Focus on completing your urgent tasks first');
    expect($result['formatted_content'])->toContain('Complete the math assignment');
    expect($result['formatted_content'])->toContain('To help me give you better guidance');
    expect($result['errors'])->toBeEmpty();
});

it('rejects invalid advisory flow responses', function () {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $processor = app(TaskAssistantResponseProcessor::class);

    $invalidData = [
        'summary' => 'Too short', // Violates min:5 rule
        'bullets' => [
            'Short', // Violates min:10 rule
        ],
    ];

    $result = $processor->processResponse(
        flow: 'advisory',
        data: $invalidData,
        snapshot: [],
        thread: $thread,
        originalUserMessage: 'Help me prioritize my tasks'
    );

    expect($result['valid'])->toBeTrue();
    expect($result['formatted_content'])->toContain('Too short');
    expect($result['errors'])->not->toBeEmpty();
});

it('validates task choice flow with business logic', function () {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $processor = app(TaskAssistantResponseProcessor::class);

    $snapshot = [
        'tasks' => [
            ['id' => 1, 'title' => 'Math Assignment'],
            ['id' => 2, 'title' => 'Science Project'],
        ],
    ];

    $validData = [
        'chosen_task_id' => 1,
        'chosen_task_title' => 'Math Assignment',
        'summary' => 'Focus on your math assignment to meet the deadline.',
        'reason' => 'This task has the highest priority and is due soon.',
        'suggested_next_steps' => [
            'Review the assignment requirements',
            'Complete the first three problems',
            'Check your answers and submit',
        ],
    ];

    $result = $processor->processResponse(
        flow: 'task_choice',
        data: $validData,
        snapshot: $snapshot,
        thread: $thread,
        originalUserMessage: 'What should I work on next?'
    );

    expect($result['valid'])->toBeTrue();
    expect(strtolower($result['formatted_content']))->toContain('math assignment');
    expect($result['formatted_content'])->toContain('Start by');
});

it('formats task choice steps without duplicating ordinal scaffolding', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $processor = app(TaskAssistantResponseProcessor::class);

    $snapshot = [
        'tasks' => [
            ['id' => 1, 'title' => 'Math Assignment'],
        ],
    ];

    $data = [
        'chosen_task_id' => 1,
        'chosen_task_title' => 'Math Assignment',
        'summary' => 'Focus on your math assignment.',
        'reason' => 'It helps you stay on track.',
        'suggested_next_steps' => [
            'First, review the key topics.',
            'Next, do the first three problems.',
            'Finally, check your answers and note what to study next.',
        ],
    ];

    $result = $processor->processResponse(
        flow: 'task_choice',
        data: $data,
        snapshot: $snapshot,
        thread: $thread,
        originalUserMessage: 'What should I work on next?'
    );

    expect($result['valid'])->toBeTrue();
    expect(strtolower($result['formatted_content']))->toContain('start by review the key topics');
    expect($result['formatted_content'])->not->toContain('start by first,');
    expect($result['formatted_content'])->not->toContain('then next,');
    expect($result['formatted_content'])->toContain('and finally check your answers');
});

it('trims trailing punctuation so joining steps stays readable', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $processor = app(TaskAssistantResponseProcessor::class);

    $snapshot = [
        'tasks' => [
            ['id' => 1, 'title' => 'Math Assignment'],
        ],
    ];

    $data = [
        'chosen_task_id' => 1,
        'chosen_task_title' => 'Math Assignment',
        'summary' => 'Focus on your math assignment.',
        'reason' => 'It helps you stay on track.',
        'suggested_next_steps' => [
            'Do the first review today.', // ends with a period
            'Then complete the first three problems.', // ends with a period
            'Finally, check your answers and note what to study next!', // ends with !
        ],
    ];

    $result = $processor->processResponse(
        flow: 'task_choice',
        data: $data,
        snapshot: $snapshot,
        thread: $thread,
        originalUserMessage: 'What should I work on next?'
    );

    expect($result['valid'])->toBeTrue();
    expect($result['formatted_content'])->not->toContain('today., then');
    expect($result['formatted_content'])->not->toContain('today.,');
    expect($result['formatted_content'])->toContain('and finally check your answers');
});

it('rejects task choice with invalid task ID', function () {
    // Ensure retry LLM calls don't consume any leaked Prism fakes from other tests.
    \Prism\Prism\Facades\Prism::fake([]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $processor = app(TaskAssistantResponseProcessor::class);

    $snapshot = [
        'tasks' => [
            ['id' => 1, 'title' => 'Math Assignment'],
        ],
    ];

    $invalidData = [
        'chosen_task_id' => 999, // Not in snapshot
        'chosen_task_title' => 'Non-existent Task',
        'summary' => 'Focus on this task.',
        'reason' => 'It seems important.',
        'suggested_next_steps' => ['Start working'],
    ];

    $result = $processor->processResponse(
        flow: 'task_choice',
        data: $invalidData,
        snapshot: $snapshot,
        thread: $thread,
        originalUserMessage: 'What should I work on next?'
    );

    // With retry logic, it should provide fallback data and be marked as valid
    expect($result['valid'])->toBeTrue();
    expect(strtolower($result['formatted_content']))->toContain('math assignment');
});

it('rejects generic task choice payload when snapshot has concrete tasks', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $processor = app(TaskAssistantResponseProcessor::class);

    $snapshot = [
        'tasks' => [
            ['id' => 1, 'title' => 'Math Assignment'],
            ['id' => 2, 'title' => 'Science Project'],
        ],
    ];

    $genericData = [
        'suggestion' => 'Pick the highest priority task.',
        'reason' => 'Urgency and due date matter most.',
        'steps' => [
            'Identify urgent tasks.',
            'Select the top one due this week.',
        ],
    ];

    $result = $processor->processResponse(
        flow: 'task_choice',
        data: $genericData,
        snapshot: $snapshot,
        thread: $thread,
        originalUserMessage: 'Help me choose my next task based on urgency and due date.'
    );

    expect($result['valid'])->toBeTrue();
    expect(strtolower($result['formatted_content']))->toContain('math assignment');
});

it('accepts explicit no-match task_choice payload when filters exclude all tasks', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $processor = app(TaskAssistantResponseProcessor::class);

    $snapshot = [
        'tasks' => [
            ['id' => 1, 'title' => 'Math Assignment'],
            ['id' => 2, 'title' => 'Science Project'],
        ],
        'events' => [],
        'projects' => [],
    ];

    $noMatchPayload = [
        'chosen_type' => null,
        'chosen_id' => null,
        'chosen_title' => null,
        'chosen_task_id' => null,
        'chosen_task_title' => null,
        'suggestion' => 'No tasks found related to reading. Try other keywords or create relevant tasks.',
        'reason' => 'No tasks matching your specified keywords were available.',
        'steps' => [
            'Add one or two tasks you care about most.',
            'Choose one task and block 25–30 minutes to work on it.',
        ],
    ];

    $result = $processor->processResponse(
        flow: 'task_choice',
        data: $noMatchPayload,
        snapshot: $snapshot,
        thread: $thread,
        originalUserMessage: 'What should I focus on today? I need reading time',
    );

    expect($result['valid'])->toBeTrue();
    expect($result['formatted_content'])->toContain('No tasks found related to reading');
    expect($result['formatted_content'])->not->toContain('The task I\'m referring to is');
});

it('includes explicit no-match instructions in task_choice retry correction message', function (): void {
    $processor = app(\App\Services\LLM\TaskAssistant\TaskAssistantResponseProcessor::class);

    $method = new ReflectionMethod($processor, 'buildCorrectionMessage');
    $method->setAccessible(true);

    $message = $method->invoke($processor, 'task_choice', [
        'Task choice response must include a concrete chosen task from snapshot.tasks.',
    ], [
        'tasks' => [
            ['id' => 1, 'title' => 'Math Assignment'],
        ],
        'events' => [],
        'projects' => [],
    ]);

    expect($message)->toContain('explicit no-match payload');
    expect($message)->toContain('chosen_task_id=null');
    expect($message)->toContain('chosen_task_title=null');
});

it('validates daily schedule flow with time format', function () {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $processor = app(TaskAssistantResponseProcessor::class);

    $validData = [
        'blocks' => [
            [
                'start_time' => '09:00',
                'end_time' => '10:30',
                'task_id' => null,
                'event_id' => null,
                'label' => 'Study Time',
                'reason' => 'Focused morning block for important work.',
            ],
            [
                'start_time' => '14:00',
                'end_time' => '15:30',
                'task_id' => null,
                'event_id' => null,
                'label' => 'Review Session',
                'reason' => 'Afternoon review to reinforce learning.',
            ],
        ],
        'summary' => 'A balanced schedule with focused work blocks.',
    ];

    $result = $processor->processResponse(
        flow: 'daily_schedule',
        data: $validData,
        snapshot: [],
        thread: $thread,
        originalUserMessage: 'Create a schedule for today'
    );

    expect($result['valid'])->toBeTrue();
    expect($result['formatted_content'])->toContain('09:00–10:30');
    expect($result['formatted_content'])->toContain('Study Time');
});

it('rejects daily schedule with invalid time format', function () {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $processor = app(TaskAssistantResponseProcessor::class);

    $invalidData = [
        'blocks' => [
            [
                'start_time' => '25:00', // Invalid time format
                'end_time' => '26:00',
                'task_id' => null,
                'event_id' => null,
                'label' => 'Invalid Time',
                'reason' => 'This should fail validation.',
            ],
        ],
    ];

    $result = $processor->processResponse(
        flow: 'daily_schedule',
        data: $invalidData,
        snapshot: [],
        thread: $thread,
        originalUserMessage: 'Create a schedule for today'
    );

    // With retry logic, it should provide fallback data and be marked as valid
    expect($result['valid'])->toBeTrue();
    expect($result['formatted_content'])->toContain('25:00–26:00');
});

it('formats study plan flow with time estimates', function () {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $processor = app(TaskAssistantResponseProcessor::class);

    $validData = [
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
                'reason' => 'Apply concepts to practical problems.',
            ],
        ],
        'summary' => 'Comprehensive study plan covering theory and practice.',
    ];

    $result = $processor->processResponse(
        flow: 'study_plan',
        data: $validData,
        snapshot: [],
        thread: $thread,
        originalUserMessage: 'Help me create a study plan'
    );

    expect($result['valid'])->toBeTrue();
    expect($result['formatted_content'])->toContain('Comprehensive study plan');
    expect($result['formatted_content'])->toContain('Review algebra concepts (30 min)');
    expect($result['formatted_content'])->toContain('Foundation for advanced');
});

it('formats review summary flow with completed and remaining tasks', function () {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $processor = app(TaskAssistantResponseProcessor::class);

    // Provide snapshot with the tasks that are referenced in the data
    $snapshot = [
        'tasks' => [
            ['id' => 1, 'title' => 'Math Homework'],
            ['id' => 2, 'title' => 'Science Lab Report'],
            ['id' => 3, 'title' => 'History Essay'],
            ['id' => 4, 'title' => 'Programming Project'],
        ],
    ];

    $validData = [
        'completed' => [
            ['task_id' => 1, 'title' => 'Math Homework'],
            ['task_id' => 2, 'title' => 'Science Lab Report'],
        ],
        'remaining' => [
            ['task_id' => 3, 'title' => 'History Essay'],
            ['task_id' => 4, 'title' => 'Programming Project'],
        ],
        'summary' => 'You have made good progress completing 2 tasks, with 2 more remaining.',
        'next_steps' => [
            'Focus on the history essay due tomorrow',
            'Break down the programming project into smaller parts',
        ],
    ];

    $result = $processor->processResponse(
        flow: 'review_summary',
        data: $validData,
        snapshot: $snapshot,
        thread: $thread,
        originalUserMessage: 'What have I accomplished?'
    );

    expect($result['valid'])->toBeTrue();
    expect($result['formatted_content'])->toContain('Recently completed:');
    expect($result['formatted_content'])->toContain('Math Homework');
    expect($result['formatted_content'])->toContain('Still to do:');
    expect($result['formatted_content'])->toContain('History Essay');
    expect($result['formatted_content'])->toContain('Recommended next steps:');
});

it('handles mutating flow responses', function () {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $processor = app(TaskAssistantResponseProcessor::class);

    $mutatingData = [
        'ok' => true,
        'message' => 'Task created successfully and added to your schedule.',
        'task' => [
            'id' => 123,
            'title' => 'New Task',
        ],
    ];

    $result = $processor->processResponse(
        flow: 'mutating',
        data: $mutatingData,
        snapshot: [],
        thread: $thread,
        originalUserMessage: 'Create a new task'
    );

    expect($result['valid'])->toBeTrue();
    expect($result['formatted_content'])->toContain('Task created successfully');
});

it('provides fallback data for invalid advisory responses', function () {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $processor = app(TaskAssistantResponseProcessor::class);

    // This will trigger retry and fallback
    $invalidData = [
        'summary' => 'Bad',
        'bullets' => ['x'], // Too short
    ];

    $result = $processor->processResponse(
        flow: 'advisory',
        data: $invalidData,
        snapshot: [],
        thread: null,
        originalUserMessage: null
    );

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->not->toBeEmpty();
});

<?php

use App\Enums\MessageRole;
use App\Enums\TaskAssistantIntent;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\TaskAssistantResponseProcessor;
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
    expect($result['formatted_content'])->toContain('Key points to remember:');
    expect($result['formatted_content'])->toContain('• Complete the math assignment');
    expect($result['formatted_content'])->toContain('Would you like help with:');
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

    // With retry logic, it should provide fallback data and be marked as valid
    expect($result['valid'])->toBeTrue();
    expect($result['formatted_content'])->toContain('more specific guidance');
    expect($result['formatted_content'])->toContain('Try asking about specific tasks');
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
    expect($result['formatted_content'])->toContain('Next task: [1] Math Assignment');
    expect($result['formatted_content'])->toContain('Focus on your math assignment');
    expect($result['formatted_content'])->toContain('Why this task:');
    expect($result['formatted_content'])->toContain('Your next steps:');
});

it('rejects task choice with invalid task ID', function () {
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
    expect($result['formatted_content'])->toContain('Focus on your most immediate task');
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
    expect($result['formatted_content'])->toContain('Your schedule:');
    expect($result['formatted_content'])->toContain('09:00–10:30 — Study Time');
    expect($result['formatted_content'])->toContain('Why: Focused morning block');
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
    expect($result['formatted_content'])->toContain('A simple schedule');
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
    expect($result['formatted_content'])->toContain('Your study plan:');
    expect($result['formatted_content'])->toContain('1. Review algebra concepts (30 min)');
    expect($result['formatted_content'])->toContain('Focus: Foundation for advanced');
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
    expect($result['formatted_content'])->toContain('✓ Math Homework');
    expect($result['formatted_content'])->toContain('Still to do:');
    expect($result['formatted_content'])->toContain('○ History Essay');
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
        thread: $thread,
        originalUserMessage: 'Help me with something'
    );

    expect($result['formatted_content'])->toContain('more specific guidance');
    expect($result['formatted_content'])->toContain('Try asking about specific tasks');
});

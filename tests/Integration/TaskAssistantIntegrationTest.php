<?php

use App\Models\TaskAssistantThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('processes responses through ResponseProcessor with formatted output', function () {
    // Create user and thread without using factories to avoid faker issues
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'workos_id' => 'test-workos-id',
        'avatar' => null,
    ]);
    
    $thread = TaskAssistantThread::create([
        'user_id' => $user->id,
        'title' => 'Test Thread',
        'metadata' => [],
    ]);
    
    // Create a simple test to verify ResponseProcessor integration
    $processor = app(\App\Services\TaskAssistantResponseProcessor::class);
    
    $testData = [
        'summary' => 'Focus on your most important tasks first to stay productive.',
        'bullets' => [
            'Complete the math assignment due tomorrow',
            'Review science notes for upcoming test',
        ],
        'follow_ups' => [
            'Need help breaking down large tasks?',
        ],
    ];

    $result = $processor->processResponse(
        flow: 'advisory',
        data: $testData,
        snapshot: [],
        thread: $thread,
        originalUserMessage: 'Help me organize my tasks'
    );

    expect($result['valid'])->toBeTrue();
    expect($result['formatted_content'])->toContain('Focus on your most important tasks');
    expect($result['formatted_content'])->toContain('Key points to remember:');
    expect($result['formatted_content'])->toContain('• Complete the math assignment');
    expect($result['formatted_content'])->not->toContain('{"type":'); // Should not be raw JSON
});

it('maintains structured data in metadata while showing formatted content', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test2@example.com',
        'workos_id' => 'test-workos-id-2',
        'avatar' => null,
    ]);
    
    $thread = TaskAssistantThread::create([
        'user_id' => $user->id,
        'title' => 'Test Thread 2',
        'metadata' => [],
    ]);
    
    $processor = app(\App\Services\TaskAssistantResponseProcessor::class);
    
    $testData = [
        'chosen_task_id' => 1,
        'chosen_task_title' => 'Math Assignment',
        'summary' => 'Focus on your math assignment to meet the deadline.',
        'reason' => 'This task has the highest priority.',
        'suggested_next_steps' => [
            'Review the assignment requirements',
            'Complete the first three problems',
        ],
    ];

    $result = $processor->processResponse(
        flow: 'task_choice',
        data: $testData,
        snapshot: ['tasks' => [['id' => 1, 'title' => 'Math Assignment']]],
        thread: $thread,
        originalUserMessage: 'What should I work on next?'
    );

    expect($result['valid'])->toBeTrue();
    expect($result['formatted_content'])->toContain('Next task: [1] Math Assignment');
    expect($result['structured_data'])->toBe($testData); // Original data preserved
});

it('provides consistent formatting across different flows', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test3@example.com',
        'workos_id' => 'test-workos-id-3',
        'avatar' => null,
    ]);
    
    $thread = TaskAssistantThread::create([
        'user_id' => $user->id,
        'title' => 'Test Thread 3',
        'metadata' => [],
    ]);
    
    $processor = app(\App\Services\TaskAssistantResponseProcessor::class);
    
    // Test advisory flow
    $advisoryData = [
        'summary' => 'Stay organized with your study schedule.',
        'bullets' => ['Review notes daily', 'Practice problems regularly'],
        'follow_ups' => ['Need study tips?'],
    ];
    
    $advisoryResult = $processor->processResponse(
        flow: 'advisory',
        data: $advisoryData,
        snapshot: [],
        thread: $thread,
        originalUserMessage: 'Help me study'
    );
    
    expect($advisoryResult['valid'])->toBeTrue();
    expect($advisoryResult['formatted_content'])->toContain('Key points to remember:');
    
    // Test study plan flow
    $studyPlanData = [
        'items' => [
            ['label' => 'Review algebra concepts', 'estimated_minutes' => 30],
            ['label' => 'Practice problems', 'estimated_minutes' => 45],
        ],
        'summary' => 'Balanced study approach.',
    ];
    
    $studyPlanResult = $processor->processResponse(
        flow: 'study_plan',
        data: $studyPlanData,
        snapshot: [],
        thread: $thread,
        originalUserMessage: 'Create study plan'
    );
    
    expect($studyPlanResult['valid'])->toBeTrue();
    expect($studyPlanResult['formatted_content'])->toContain('Your study plan:');
    expect($studyPlanResult['formatted_content'])->toContain('(30 min)');
});

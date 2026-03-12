<?php

use App\DataTransferObjects\Llm\LlmInferenceResult;
use App\DataTransferObjects\Llm\LlmSystemPromptResult;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Models\User;
use App\Services\Llm\LlmPostProcessor;

it('fills ranked_tasks from context when prioritize_tasks structured list is empty', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    $context = [
        'tasks' => [
            [
                'title' => 'CS 220 – Lab 5: Linked Lists',
                'end_datetime' => now()->addDays(1)->toIso8601String(),
                'status' => 'to_do',
            ],
            [
                'title' => 'MATH 201 – Quiz 3: Graph Theory',
                'end_datetime' => now()->addDays(2)->toIso8601String(),
                'status' => 'to_do',
            ],
        ],
    ];

    $raw = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'First, focus on your CS 220 and MATH 201 work.',
            'reasoning' => 'These are your most time-sensitive items.',
            'ranked_tasks' => [],
        ],
        promptVersion: 'v1.8',
        promptTokens: 10,
        completionTokens: 20,
        usedFallback: false
    );

    $prompt = new LlmSystemPromptResult(
        systemPrompt: 'You are TaskLyst Assistant.',
        version: 'v1.8',
    );

    /** @var LlmPostProcessor $postProcessor */
    $postProcessor = app(LlmPostProcessor::class);

    $structured = $postProcessor->process(
        user: $user,
        intent: LlmIntent::PrioritizeTasks,
        entityType: LlmEntityType::Task,
        context: $context,
        userMessage: 'I’m overwhelmed. Looking only at my CS 220 and MATH 201 work for the next three days, which tasks should I tackle first and why?',
        userPrompt: 'user + context',
        promptResult: $prompt,
        result: $raw,
        traceId: null,
    );

    expect($structured['ranked_tasks'] ?? null)
        ->toBeArray()
        ->and($structured['ranked_tasks'])->toHaveCount(2)
        ->and($structured['ranked_tasks'][0]['title'] ?? null)->toBe('CS 220 – Lab 5: Linked Lists')
        ->and($structured['entity_type'] ?? null)->toBe('task');
});

it('overrides llm ranked_tasks with deterministic ordering for prioritize_tasks', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    $context = [
        'requested_top_n' => 2,
        'tasks' => [
            [
                'title' => 'MATH 201 – Quiz 3: Graph Theory',
                'end_datetime' => now()->addDays(2)->toIso8601String(),
                'priority' => 'medium',
                'status' => 'to_do',
            ],
            [
                'title' => 'CS 220 – Lab 5: Linked Lists',
                'end_datetime' => now()->addDay()->toIso8601String(),
                'priority' => 'high',
                'status' => 'to_do',
            ],
            [
                'title' => 'ENG 105 – Reading Response #3',
                'end_datetime' => now()->addDays(3)->toIso8601String(),
                'priority' => 'low',
                'status' => 'to_do',
            ],
        ],
    ];

    $raw = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'Start with the MATH quiz first.',
            'reasoning' => 'It sounds urgent.',
            'ranked_tasks' => [
                ['rank' => 1, 'title' => 'MATH 201 – Quiz 3: Graph Theory'],
                ['rank' => 2, 'title' => 'CS 220 – Lab 5: Linked Lists'],
                ['rank' => 3, 'title' => 'ENG 105 – Reading Response #3'],
            ],
        ],
        promptVersion: 'v1.8',
        promptTokens: 10,
        completionTokens: 20,
        usedFallback: false
    );

    $prompt = new LlmSystemPromptResult(
        systemPrompt: 'You are TaskLyst Assistant.',
        version: 'v1.8',
    );

    /** @var LlmPostProcessor $postProcessor */
    $postProcessor = app(LlmPostProcessor::class);

    $structured = $postProcessor->process(
        user: $user,
        intent: LlmIntent::PrioritizeTasks,
        entityType: LlmEntityType::Task,
        context: $context,
        userMessage: 'Rank my tasks',
        userPrompt: 'user + context',
        promptResult: $prompt,
        result: $raw,
        traceId: null,
    );

    expect($structured['ranked_tasks'] ?? null)
        ->toBeArray()
        ->and($structured['ranked_tasks'])->toHaveCount(2)
        ->and($structured['ranked_tasks'][0]['title'] ?? null)->toBe('CS 220 – Lab 5: Linked Lists')
        ->and($structured['ranked_tasks'][1]['title'] ?? null)->toBe('MATH 201 – Quiz 3: Graph Theory');
});

it('overrides llm ranked_events with deterministic ordering for prioritize_events', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    $context = [
        'events' => [
            [
                'title' => 'Math exam review session',
                'start_datetime' => now()->addDay()->setTime(9, 0)->toIso8601String(),
                'end_datetime' => now()->addDay()->setTime(11, 0)->toIso8601String(),
            ],
            [
                'title' => 'CS 220 consultation',
                'start_datetime' => now()->addHours(6)->toIso8601String(),
                'end_datetime' => now()->addHours(7)->toIso8601String(),
            ],
        ],
    ];

    $raw = new LlmInferenceResult(
        structured: [
            'entity_type' => 'event',
            'recommended_action' => 'Start with the review session first.',
            'reasoning' => 'It feels more important.',
            'ranked_events' => [
                ['rank' => 1, 'title' => 'Math exam review session'],
                ['rank' => 2, 'title' => 'CS 220 consultation'],
            ],
        ],
        promptVersion: 'v1.8',
        promptTokens: 10,
        completionTokens: 20,
        usedFallback: false
    );

    $prompt = new LlmSystemPromptResult(
        systemPrompt: 'You are TaskLyst Assistant.',
        version: 'v1.8',
    );

    /** @var LlmPostProcessor $postProcessor */
    $postProcessor = app(LlmPostProcessor::class);

    $structured = $postProcessor->process(
        user: $user,
        intent: LlmIntent::PrioritizeEvents,
        entityType: LlmEntityType::Event,
        context: $context,
        userMessage: 'Rank my events',
        userPrompt: 'user + context',
        promptResult: $prompt,
        result: $raw,
        traceId: null,
    );

    expect($structured['ranked_events'] ?? null)
        ->toBeArray()
        ->and($structured['ranked_events'][0]['title'] ?? null)->toBe('CS 220 consultation')
        ->and($structured['ranked_events'][1]['title'] ?? null)->toBe('Math exam review session');
});

it('overrides llm ranked arrays with deterministic ordering for prioritize_all', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    $context = [
        'tasks' => [
            [
                'title' => 'CS 220 – Lab 5: Linked Lists',
                'end_datetime' => now()->addDay()->toIso8601String(),
                'priority' => 'high',
                'status' => 'to_do',
            ],
        ],
        'events' => [
            [
                'title' => 'Math exam review session',
                'start_datetime' => now()->addHours(5)->toIso8601String(),
                'end_datetime' => now()->addHours(7)->toIso8601String(),
            ],
        ],
        'projects' => [
            [
                'name' => 'CS 220 Final Project',
                'end_datetime' => now()->addDays(4)->toIso8601String(),
            ],
        ],
    ];

    $raw = new LlmInferenceResult(
        structured: [
            'entity_type' => 'all',
            'recommended_action' => 'Start with project work.',
            'reasoning' => 'It is strategically best.',
            'ranked_tasks' => [],
            'ranked_events' => [],
            'ranked_projects' => [],
        ],
        promptVersion: 'v1.8',
        promptTokens: 10,
        completionTokens: 20,
        usedFallback: false
    );

    $prompt = new LlmSystemPromptResult(
        systemPrompt: 'You are TaskLyst Assistant.',
        version: 'v1.8',
    );

    /** @var LlmPostProcessor $postProcessor */
    $postProcessor = app(LlmPostProcessor::class);

    $structured = $postProcessor->process(
        user: $user,
        intent: LlmIntent::PrioritizeAll,
        entityType: LlmEntityType::Multiple,
        context: $context,
        userMessage: 'Prioritize everything',
        userPrompt: 'user + context',
        promptResult: $prompt,
        result: $raw,
        traceId: null,
    );

    expect($structured['ranked_tasks'] ?? null)->toBeArray()->and($structured['ranked_tasks'])->toHaveCount(1)
        ->and($structured['ranked_events'] ?? null)->toBeArray()->and($structured['ranked_events'])->toHaveCount(1)
        ->and($structured['ranked_projects'] ?? null)->toBeArray()->and($structured['ranked_projects'])->toHaveCount(1);
});

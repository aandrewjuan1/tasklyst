<?php

use App\Enums\LlmIntent;
use App\Services\Llm\StructuredOutputSanitizer;

beforeEach(function (): void {
    $this->sanitizer = new StructuredOutputSanitizer;
});

test('sanitize prioritize_events with empty context strips ranked_events and overrides message', function (): void {
    $structured = [
        'entity_type' => 'event',
        'recommended_action' => 'Focus on the exam.',
        'reasoning' => 'It has high priority.',
        'ranked_events' => [
            ['rank' => 1, 'title' => 'Fake event from conversation'],
        ],
    ];
    $context = ['current_time' => now()->toIso8601String(), 'events' => [], 'conversation_history' => []];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::PrioritizeEvents);

    expect($out['ranked_events'])->toBeArray()->toBeEmpty()
        ->and($out['recommended_action'])->toContain('no events')
        ->and($out['confidence'])->toBeLessThanOrEqual(0.3);
});

test('sanitize prioritize_events keeps only events that exist in context', function (): void {
    $structured = [
        'entity_type' => 'event',
        'recommended_action' => 'Prioritize these.',
        'reasoning' => 'Order by time.',
        'ranked_events' => [
            ['rank' => 1, 'title' => 'Real Event A'],
            ['rank' => 2, 'title' => 'Fake from history'],
            ['rank' => 3, 'title' => 'Real Event B'],
        ],
    ];
    $context = [
        'events' => [
            ['id' => 1, 'title' => 'Real Event A'],
            ['id' => 2, 'title' => 'Real Event B'],
        ],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::PrioritizeEvents);

    expect($out['ranked_events'])->toHaveCount(2)
        ->and($out['ranked_events'][0]['title'])->toBe('Real Event A')
        ->and($out['ranked_events'][1]['title'])->toBe('Real Event B')
        ->and($out['ranked_events'][0]['rank'])->toBe(1)
        ->and($out['ranked_events'][1]['rank'])->toBe(2);
});

test('sanitize prioritize_tasks with empty context strips ranked_tasks', function (): void {
    $structured = [
        'entity_type' => 'task',
        'ranked_tasks' => [['rank' => 1, 'title' => 'Fake task']],
    ];
    $context = ['tasks' => []];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::PrioritizeTasks);

    expect($out['ranked_tasks'])->toBeArray()->toBeEmpty();
});

test('sanitize non prioritize intent returns structured unchanged', function (): void {
    $structured = ['entity_type' => 'task', 'recommended_action' => 'Do X', 'reasoning' => 'Because'];
    $context = ['tasks' => []];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::ScheduleTask);

    expect($out)->toEqual($structured);
});

test('sanitize prioritize_tasks accepts fuzzy title match and normalizes to context title', function (): void {
    $structured = [
        'entity_type' => 'task',
        'ranked_tasks' => [
            ['rank' => 1, 'title' => 'Ortograpia/ Barayti ng wika - Due', 'end_datetime' => '2026-02-22T15:59:59+08:00'],
        ],
    ];
    $context = [
        'tasks' => [
            ['id' => 1, 'title' => 'Ortograpiya/ Barayti ng wika - Due'],
        ],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::PrioritizeTasks);

    expect($out['ranked_tasks'])->toHaveCount(1)
        ->and($out['ranked_tasks'][0]['title'])->toBe('Ortograpiya/ Barayti ng wika - Due')
        ->and($out['ranked_tasks'][0]['rank'])->toBe(1);
});

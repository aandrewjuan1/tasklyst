<?php

use App\Support\LLM\PrioritizeNarrativeConnectionFallback;

test('connection fallback framing uses rank-style copy for multiple items', function (): void {
    $items = [
        ['entity_type' => 'task', 'entity_id' => 1, 'title' => 'A'],
        ['entity_type' => 'task', 'entity_id' => 2, 'title' => 'B'],
    ];

    $out = PrioritizeNarrativeConnectionFallback::framing($items, 'show me stuff');
    expect($out)->toContain('next steps');
});

test('connection fallback reasoning ends with urgency-order tail for multiple items', function (): void {
    $items = [
        [
            'entity_type' => 'task',
            'entity_id' => 1,
            'title' => 'T1',
            'due_phrase' => 'due today',
            'priority' => 'high',
        ],
        ['entity_type' => 'task', 'entity_id' => 2, 'title' => 'T2'],
    ];

    $out = PrioritizeNarrativeConnectionFallback::reasoning($items);
    expect($out)->toContain('T1');
    expect($out)->toContain('urgency order');
});

test('connection fallback framing uses heuristic lead for what-should-i-do-first prompts', function (): void {
    $items = [
        ['entity_type' => 'task', 'entity_id' => 1, 'title' => 'A'],
    ];

    $out = PrioritizeNarrativeConnectionFallback::framing($items, 'what should I do first?');
    expect($out)->toContain('urgency');
});

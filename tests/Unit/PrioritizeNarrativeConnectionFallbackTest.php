<?php

use App\Enums\TaskAssistantPrioritizeVariant;
use App\Support\LLM\PrioritizeNarrativeConnectionFallback;

test('connection fallback framing covers rank browse and followup variants for multiple items', function (): void {
    $items = [
        ['entity_type' => 'task', 'title' => 'A', 'due_phrase' => 'overdue', 'priority' => 'high'],
        ['entity_type' => 'task', 'title' => 'B', 'due_phrase' => 'due today', 'priority' => 'medium'],
    ];

    $fRank = PrioritizeNarrativeConnectionFallback::framing($items, 'show me stuff', TaskAssistantPrioritizeVariant::Rank);
    $fBrowse = PrioritizeNarrativeConnectionFallback::framing($items, 'show me stuff', TaskAssistantPrioritizeVariant::Browse);
    $fFollow = PrioritizeNarrativeConnectionFallback::framing($items, 'show me stuff', TaskAssistantPrioritizeVariant::FollowupSlice);

    expect(mb_strlen($fRank))->toBeGreaterThan(20);
    expect(mb_strlen($fBrowse))->toBeGreaterThan(20);
    expect(mb_strlen($fFollow))->toBeGreaterThan(20);
    expect($fRank)->not->toBe($fBrowse);
    expect($fFollow)->toContain('next items');
});

test('connection fallback reasoning adds multi-item tail for rank variant', function (): void {
    $items = [
        ['entity_type' => 'task', 'title' => 'Alpha', 'due_phrase' => 'overdue', 'priority' => 'urgent'],
        ['entity_type' => 'task', 'title' => 'Beta', 'due_phrase' => 'due today', 'priority' => 'medium'],
    ];

    $out = PrioritizeNarrativeConnectionFallback::reasoning($items, TaskAssistantPrioritizeVariant::Rank);

    expect($out)->toContain('Alpha');
    expect($out)->toContain('underneath');
});

test('connection fallback reasoning uses browse tail for browse variant', function (): void {
    $items = [
        ['entity_type' => 'task', 'title' => 'Alpha', 'due_phrase' => 'overdue', 'priority' => 'urgent'],
        ['entity_type' => 'task', 'title' => 'Beta', 'due_phrase' => 'due today', 'priority' => 'medium'],
    ];

    $out = PrioritizeNarrativeConnectionFallback::reasoning($items, TaskAssistantPrioritizeVariant::Browse);

    expect($out)->toContain('Alpha');
    expect($out)->toContain('filtered view');
});

test('connection fallback user message lead matches what to do first phrasing', function (): void {
    $items = [['entity_type' => 'task', 'title' => 'Only', 'due_phrase' => 'overdue', 'priority' => 'urgent']];

    $out = PrioritizeNarrativeConnectionFallback::framing($items, 'what should I do first?', TaskAssistantPrioritizeVariant::Rank);

    expect($out)->toMatch('/what to do first/i');
});

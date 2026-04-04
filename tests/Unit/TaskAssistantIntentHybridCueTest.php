<?php

use App\Services\LLM\Intent\TaskAssistantIntentHybridCue;

test('matches combined prioritize schedule for when should i plus most important tasks and plan', function (): void {
    $msg = 'when should i do my most important tasks? can you please plan them';
    $normalized = TaskAssistantIntentHybridCue::normalizeForSignals(mb_strtolower(trim($msg)));

    expect(TaskAssistantIntentHybridCue::matchesCombinedPrioritizeSchedulePrompt($normalized))->toBeTrue();
});

test('matches combined for plan my most important tasks follow up', function (): void {
    $msg = 'i said plan my most important tasks';
    $normalized = TaskAssistantIntentHybridCue::normalizeForSignals(mb_strtolower(trim($msg)));

    expect(TaskAssistantIntentHybridCue::matchesCombinedPrioritizeSchedulePrompt($normalized))->toBeTrue();
});

test('does not match combined when only asking for important tasks without time or plan cue', function (): void {
    $msg = 'what are my three most important tasks';
    $normalized = TaskAssistantIntentHybridCue::normalizeForSignals(mb_strtolower(trim($msg)));

    expect(TaskAssistantIntentHybridCue::matchesCombinedPrioritizeSchedulePrompt($normalized))->toBeFalse();
});

test('matches combined for rank top tasks and schedule tomorrow', function (): void {
    $msg = 'rank my top 3 tasks and schedule them tomorrow afternoon';
    $normalized = TaskAssistantIntentHybridCue::normalizeForSignals(mb_strtolower(trim($msg)));

    expect(TaskAssistantIntentHybridCue::matchesCombinedPrioritizeSchedulePrompt($normalized))->toBeTrue();
});

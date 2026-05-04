<?php

use App\Services\LLM\Intent\TaskAssistantIntentHybridCue;

test('matches combined prioritize schedule for when should i plus most important tasks and plan', function (): void {
    $msg = 'when should i do my most important tasks? can you please plan them';
    $normalized = TaskAssistantIntentHybridCue::normalizeForSignals(mb_strtolower(trim($msg)));

    expect(TaskAssistantIntentHybridCue::matchesCombinedPrioritizeSchedulePrompt($normalized))->toBeTrue();
});

test('matches combined when plan explicitly ties to calendar day', function (): void {
    $msg = 'please plan my day with my most important tasks first';
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

test('does not match combined for tackle first with only a day word', function (): void {
    $msg = 'What should I tackle first today?';
    $normalized = TaskAssistantIntentHybridCue::normalizeForSignals(mb_strtolower(trim($msg)));

    expect(TaskAssistantIntentHybridCue::matchesCombinedPrioritizeSchedulePrompt($normalized))->toBeFalse();
});

test('does not match combined for tackle first for today phrasing', function (): void {
    $msg = 'what should i tackle first for today?';
    $normalized = TaskAssistantIntentHybridCue::normalizeForSignals(mb_strtolower(trim($msg)));

    expect(TaskAssistantIntentHybridCue::matchesCombinedPrioritizeSchedulePrompt($normalized))->toBeFalse();
});

test('matches combined when user explicitly asks to schedule ranked tasks', function (): void {
    $msg = 'schedule my top 3 tasks for later today';
    $normalized = TaskAssistantIntentHybridCue::normalizeForSignals(mb_strtolower(trim($msg)));

    expect(TaskAssistantIntentHybridCue::matchesCombinedPrioritizeSchedulePrompt($normalized))->toBeTrue();
});

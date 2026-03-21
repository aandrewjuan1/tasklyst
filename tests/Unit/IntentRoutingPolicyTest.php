<?php

use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\ExecutionPlan;
use App\Services\LLM\TaskAssistant\IntentRoutingPolicy;

test('legacy routing keeps deterministic route selection when policy is disabled', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'Schedule my day', false);

    expect($decision->flow)->toBe('schedule');
    expect($decision->confidence)->toBe(1.0);
    expect($decision->clarificationNeeded)->toBeFalse();
    expect($decision->reasonCodes)->toContain('legacy_routing');
});

test('policy routing can request clarification based on thresholds', function (): void {
    config()->set('task-assistant.routing.execute_threshold', 0.9);
    config()->set('task-assistant.routing.clarify_threshold', 0.45);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'Schedule my day', true);

    expect($decision->flow)->toBe('schedule');
    expect($decision->clarificationNeeded)->toBeTrue();
    expect($decision->clarificationQuestion)->not->toBeNull();
});

test('policy routing resolves multi-turn target entities and constraints', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    app(\App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService::class)->rememberPrioritizedItems($thread, [[
        'entity_type' => 'task',
        'entity_id' => 1001,
        'title' => 'Deep work block',
    ]], 1);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'Schedule those 2 in the afternoon', true);

    expect($decision->constraints['count_limit'])->toBe(2);
    expect($decision->constraints['time_window_hint'])->toBe('later_afternoon');
    expect($decision->constraints['target_entities'])->toHaveCount(1);
});

test('execution plan holds normalized orchestration fields', function (): void {
    $plan = new ExecutionPlan(
        flow: 'schedule',
        confidence: 0.82,
        clarificationNeeded: false,
        clarificationQuestion: null,
        reasonCodes: ['schedule_signal_detected'],
        constraints: ['count_limit' => 2],
        targetEntities: [],
        timeWindowHint: 'morning',
        countLimit: 2,
        generationProfile: 'schedule',
    );

    expect($plan->flow)->toBe('schedule');
    expect($plan->countLimit)->toBe(2);
    expect($plan->generationProfile)->toBe('schedule');
});

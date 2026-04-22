<?php

use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\ExecutionPlan;
use App\Services\LLM\TaskAssistant\TaskAssistantService;

test('bare schedule with explicit tomorrow horizon promotes to prioritize_schedule', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $plan = new ExecutionPlan(
        flow: 'schedule',
        confidence: 0.885,
        clarificationNeeded: false,
        clarificationQuestion: null,
        reasonCodes: ['llm_intent_scheduling'],
        constraints: [],
        targetEntities: [],
        timeWindowHint: null,
        countLimit: 3,
        generationProfile: 'schedule',
    );

    $method = new \ReflectionMethod(TaskAssistantService::class, 'maybeRemapScheduleToPrioritize');
    $method->setAccessible(true);
    $out = $method->invoke(
        app(TaskAssistantService::class),
        $thread,
        $plan,
        'schedule tasks for tomorrow'
    );

    expect($out->flow)->toBe('prioritize_schedule')
        ->and($out->reasonCodes)->toContain('schedule_promoted_prioritize_schedule_explicit_horizon');
});

test('bare schedule without explicit horizon still remaps to prioritize', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $plan = new ExecutionPlan(
        flow: 'schedule',
        confidence: 0.8,
        clarificationNeeded: false,
        clarificationQuestion: null,
        reasonCodes: ['llm_intent_scheduling'],
        constraints: [],
        targetEntities: [],
        timeWindowHint: null,
        countLimit: 3,
        generationProfile: 'schedule',
    );

    $method = new \ReflectionMethod(TaskAssistantService::class, 'maybeRemapScheduleToPrioritize');
    $method->setAccessible(true);
    $out = $method->invoke(
        app(TaskAssistantService::class),
        $thread,
        $plan,
        'help me schedule things'
    );

    expect($out->flow)->toBe('prioritize')
        ->and($out->reasonCodes)->toContain('schedule_rerouted_no_listing_context');
});

test('whole-day planning prompt promotes bare schedule to prioritize_schedule', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $plan = new ExecutionPlan(
        flow: 'schedule',
        confidence: 0.83,
        clarificationNeeded: false,
        clarificationQuestion: null,
        reasonCodes: ['llm_intent_scheduling'],
        constraints: [],
        targetEntities: [],
        timeWindowHint: 'later',
        countLimit: 3,
        generationProfile: 'schedule',
    );

    $method = new \ReflectionMethod(TaskAssistantService::class, 'maybeRemapScheduleToPrioritize');
    $method->setAccessible(true);
    $out = $method->invoke(
        app(TaskAssistantService::class),
        $thread,
        $plan,
        'plan my whole day later'
    );

    expect($out->flow)->toBe('prioritize_schedule')
        ->and($out->reasonCodes)->toContain('schedule_promoted_prioritize_schedule_day_planning');
});

test('schedule edit phrasing with afternoon does not remap to prioritize', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $plan = new ExecutionPlan(
        flow: 'schedule',
        confidence: 0.81,
        clarificationNeeded: false,
        clarificationQuestion: null,
        reasonCodes: ['llm_intent_scheduling'],
        constraints: [],
        targetEntities: [],
        timeWindowHint: 'later_afternoon',
        countLimit: 3,
        generationProfile: 'schedule',
    );

    $method = new \ReflectionMethod(TaskAssistantService::class, 'maybeRemapScheduleToPrioritize');
    $method->setAccessible(true);
    $out = $method->invoke(
        app(TaskAssistantService::class),
        $thread,
        $plan,
        'move at afternoon'
    );

    expect($out->flow)->toBe('schedule')
        ->and($out->reasonCodes)->not->toContain('schedule_rerouted_no_listing_context');
});

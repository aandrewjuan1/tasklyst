<?php

use App\Enums\TaskAssistantUserIntent;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\Intent\TaskAssistantIntentInferenceResult;
use App\Services\LLM\Intent\TaskAssistantIntentResolutionService;

test('strong prioritize signal overrides llm general guidance intent', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $inference = new TaskAssistantIntentInferenceResult(
        intent: TaskAssistantUserIntent::GeneralGuidance,
        confidence: 0.9,
        failed: false,
        rationale: 'Model misclassification.',
    );

    $decision = app(TaskAssistantIntentResolutionService::class)->resolve(
        $thread,
        'prioritize my tasks',
        $inference,
        ['prioritization' => 0.8, 'scheduling' => 0.2],
    );

    expect($decision->flow)->toBe('prioritize');
    expect($decision->reasonCodes)->toContain('intent_general_guidance_overridden_by_signal_prioritize');
});

test('when intent inference is unavailable, strong prioritize signal routes to prioritize', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(TaskAssistantIntentResolutionService::class)->resolve(
        $thread,
        'what are the top tasks that i need to do as soon as possible?',
        null,
        ['prioritization' => 0.87, 'scheduling' => 0.0],
    );

    expect($decision->flow)->toBe('prioritize');
    expect($decision->reasonCodes)->toContain('intent_llm_unavailable_signal_fallback');
});

test('when intent llm returns an invalid label, signal fallback is not used', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $inference = new TaskAssistantIntentInferenceResult(
        intent: null,
        confidence: 0.5,
        failed: true,
        rationale: 'bad label',
        connectionFailed: false,
    );

    $decision = app(TaskAssistantIntentResolutionService::class)->resolve(
        $thread,
        'hello there friend',
        $inference,
        ['prioritization' => 0.87, 'scheduling' => 0.0],
    );

    expect($decision->flow)->toBe('general_guidance');
    expect($decision->reasonCodes)->toContain('intent_llm_failed_fallback_general_guidance');
});

test('when intent llm connection fails, strong prioritize signal routes to prioritize', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $inference = new TaskAssistantIntentInferenceResult(
        intent: null,
        confidence: 0.0,
        failed: true,
        rationale: null,
        connectionFailed: true,
    );

    $decision = app(TaskAssistantIntentResolutionService::class)->resolve(
        $thread,
        'what are the top tasks that i need to do as soon as possible?',
        $inference,
        ['prioritization' => 0.87, 'scheduling' => 0.0],
    );

    expect($decision->flow)->toBe('prioritize');
    expect($decision->reasonCodes)->toContain('intent_llm_unavailable_signal_fallback');
});

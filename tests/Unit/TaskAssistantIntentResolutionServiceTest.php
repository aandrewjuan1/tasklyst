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

test('when intent llm returns an invalid label, strong signal fallback is used', function (): void {
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
        'what are my top tasks today?',
        $inference,
        ['prioritization' => 0.87, 'scheduling' => 0.0],
    );

    expect($decision->flow)->toBe('prioritize');
    expect($decision->reasonCodes)->toContain('intent_llm_failed_signal_fallback');
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

test('signal-only mode routes to prioritize_schedule when hybrid signal wins', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(TaskAssistantIntentResolutionService::class)->resolve(
        $thread,
        'ignored',
        null,
        ['prioritization' => 0.4, 'scheduling' => 0.42, 'hybrid' => 0.85],
    );

    expect($decision->flow)->toBe('prioritize_schedule');
    expect($decision->reasonCodes)->toContain('signal_only_prioritize_schedule');
});

test('merge resolves prioritize vs schedule composite tie to prioritize_schedule when hybrid clears threshold', function (): void {
    config()->set('task-assistant.intent.use_llm', true);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $inference = new TaskAssistantIntentInferenceResult(
        intent: TaskAssistantUserIntent::Scheduling,
        confidence: 0.2,
        failed: false,
        rationale: 'Ambiguous.',
    );

    $decision = app(TaskAssistantIntentResolutionService::class)->resolve(
        $thread,
        'x',
        $inference,
        ['prioritization' => 0.55, 'scheduling' => 0.54, 'hybrid' => 0.95],
    );

    expect($decision->flow)->toBe('prioritize_schedule');
    expect($decision->reasonCodes)->toContain('hybrid_resolves_prioritize_schedule_ambiguity');
});

test('llm prioritization is overridden to prioritize schedule when message matches combined hybrid cue', function (): void {
    config()->set('task-assistant.intent.use_llm', true);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $inference = new TaskAssistantIntentInferenceResult(
        intent: TaskAssistantUserIntent::Prioritization,
        confidence: 0.85,
        failed: false,
        rationale: 'Model mislabeled combined intent.',
    );

    $decision = app(TaskAssistantIntentResolutionService::class)->resolve(
        $thread,
        'when should i do my most important tasks? can you please plan them',
        $inference,
        ['prioritization' => 1.0, 'scheduling' => 0.45, 'hybrid' => 0.52],
    );

    expect($decision->flow)->toBe('prioritize_schedule');
    expect($decision->reasonCodes)->toContain('intent_llm_prioritization_combined_prompt_override');
});

test('llm scheduling is overridden to prioritize schedule when message matches combined hybrid cue', function (): void {
    config()->set('task-assistant.intent.use_llm', true);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $inference = new TaskAssistantIntentInferenceResult(
        intent: TaskAssistantUserIntent::Scheduling,
        confidence: 0.85,
        failed: false,
        rationale: 'Model picked time-only label for a rank+time message.',
    );

    $decision = app(TaskAssistantIntentResolutionService::class)->resolve(
        $thread,
        'when should i do my most important tasks? can you please plan them',
        $inference,
        ['prioritization' => 1.0, 'scheduling' => 0.45, 'hybrid' => 0.52],
    );

    expect($decision->flow)->toBe('prioritize_schedule');
    expect($decision->reasonCodes)->toContain('intent_llm_scheduling_combined_prompt_override');
});

test('llm listing_followup intent routes to listing_followup flow', function (): void {
    config()->set('task-assistant.intent.use_llm', true);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $inference = new TaskAssistantIntentInferenceResult(
        intent: TaskAssistantUserIntent::ListingFollowup,
        confidence: 0.82,
        failed: false,
        rationale: 'User is verifying a recent suggestion.',
    );

    $decision = app(TaskAssistantIntentResolutionService::class)->resolve(
        $thread,
        'is that ordering right?',
        $inference,
        ['prioritization' => 0.2, 'scheduling' => 0.05, 'hybrid' => 0.0],
    );

    expect($decision->flow)->toBe('listing_followup');
    expect($decision->reasonCodes)->toContain('intent_llm_listing_followup');
});

test('llm prioritize_schedule demotes to prioritize when scheduling signal is weak and no combined cue', function (): void {
    config()->set('task-assistant.intent.use_llm', true);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $inference = new TaskAssistantIntentInferenceResult(
        intent: TaskAssistantUserIntent::PrioritizeSchedule,
        confidence: 0.85,
        failed: false,
        rationale: 'Mislabeled verification question.',
    );

    $decision = app(TaskAssistantIntentResolutionService::class)->resolve(
        $thread,
        'are those two the most urgent?',
        $inference,
        ['prioritization' => 0.42, 'scheduling' => 0.1, 'hybrid' => 0.0],
    );

    expect($decision->flow)->toBe('prioritize');
    expect($decision->reasonCodes)->toContain('prioritize_schedule_demoted_weak_schedule_signal');
});

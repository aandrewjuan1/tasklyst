<?php

use App\Services\LLM\Scheduling\ScheduleFallbackPolicy;
use App\Services\LLM\TaskAssistant\ExecutionPlan;

it('requires confirmation for top n shortfall', function (): void {
    $policy = app(ScheduleFallbackPolicy::class);

    $plan = new ExecutionPlan(
        flow: 'schedule',
        confidence: 1.0,
        clarificationNeeded: false,
        clarificationQuestion: null,
        reasonCodes: [],
        constraints: [],
        targetEntities: [],
        timeWindowHint: null,
        countLimit: 3,
        generationProfile: 'schedule',
    );

    $data = [
        'placement_digest' => [
            'top_n_shortfall' => true,
        ],
    ];

    expect($policy->shouldRequireConfirmation($plan, $data))->toBeTrue();
});

it('requires confirmation for later window relaxed fallback mode', function (): void {
    $policy = app(ScheduleFallbackPolicy::class);

    $plan = new ExecutionPlan(
        flow: 'schedule',
        confidence: 1.0,
        clarificationNeeded: false,
        clarificationQuestion: null,
        reasonCodes: [],
        constraints: [],
        targetEntities: [],
        timeWindowHint: 'later',
        countLimit: 3,
        generationProfile: 'schedule',
    );

    $data = [
        'placement_digest' => [
            'fallback_mode' => 'auto_relaxed_today_or_tomorrow',
            'top_n_shortfall' => false,
        ],
    ];

    expect($policy->shouldRequireConfirmation($plan, $data))->toBeTrue();
});

it('classifies confirm decline and unknown decisions', function (): void {
    $policy = app(ScheduleFallbackPolicy::class);

    expect($policy->classifyPendingDecision('yes, continue'))->toBe('confirm');
    expect($policy->classifyPendingDecision('no cancel this'))->toBe('decline');
    expect($policy->classifyPendingDecision('maybe later'))->toBe('unknown');
});

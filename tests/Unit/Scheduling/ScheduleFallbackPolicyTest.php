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

it('requires confirmation when confirmation_signals contains an allowed trigger', function (): void {
    $policy = app(ScheduleFallbackPolicy::class);

    $plan = new ExecutionPlan(
        flow: 'schedule',
        confidence: 1.0,
        clarificationNeeded: false,
        clarificationQuestion: null,
        reasonCodes: [],
        constraints: [],
        targetEntities: [],
        timeWindowHint: 'later_afternoon',
        countLimit: 1,
        generationProfile: 'schedule',
    );

    $data = [
        'placement_digest' => [
            'confirmation_signals' => [
                'triggers' => ['requested_window_unsatisfied'],
            ],
            'top_n_shortfall' => false,
        ],
    ];

    expect($policy->shouldRequireConfirmation($plan, $data))->toBeTrue();
});

it('requires confirmation for unplaced_units trigger from strict contract', function (): void {
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
            'confirmation_signals' => [
                'triggers' => ['unplaced_units'],
            ],
            'top_n_shortfall' => false,
        ],
    ];

    expect($policy->shouldRequireConfirmation($plan, $data))->toBeTrue();
});

it('does not require confirmation when trigger is not in config allow list', function (): void {
    config(['task-assistant.schedule.confirmation_triggers' => ['empty_placement']]);

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
        countLimit: 1,
        generationProfile: 'schedule',
    );

    $data = [
        'placement_digest' => [
            'confirmation_signals' => [
                'triggers' => ['requested_window_unsatisfied'],
            ],
        ],
    ];

    expect($policy->shouldRequireConfirmation($plan, $data))->toBeFalse();

    config([
        'task-assistant.schedule.confirmation_triggers' => [
            'empty_placement',
            'unplaced_units',
            'adaptive_relaxed_placement',
            'strict_window_no_fit',
            'requested_window_unsatisfied',
            'hinted_window_unsatisfied',
            'placement_outside_horizon',
            'top_n_shortfall',
        ],
    ]);
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

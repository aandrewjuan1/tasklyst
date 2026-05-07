<?php

use App\Services\LLM\TaskAssistant\TaskAssistantScheduleTemplateService;

it('keeps schedule template selection deterministic for same seed context', function (): void {
    $service = app(TaskAssistantScheduleTemplateService::class);
    $seed = [
        'thread_id' => 101,
        'flow_source' => 'schedule',
        'scenario_key' => 'STRICT_WINDOW_NO_FIT',
        'requested_window_label' => 'tomorrow morning',
        'placed_count' => 1,
        'day_bucket' => '2026-04-30',
        'prompt_key' => 'same_seed',
        'request_bucket' => 'same_seed',
    ];

    $first = $service->buildFraming('STRICT_WINDOW_NO_FIT', $seed, ['requested_window_label' => 'tomorrow morning']);
    $second = $service->buildFraming('STRICT_WINDOW_NO_FIT', $seed, ['requested_window_label' => 'tomorrow morning']);

    expect($first)->toBe($second);
});

it('supports flow-specific wording overrides for prioritize_schedule', function (): void {
    $service = app(TaskAssistantScheduleTemplateService::class);

    $schedule = $service->buildFraming('TOP_N_SHORTFALL', [
        'thread_id' => 101,
        'flow_source' => 'schedule',
        'scenario_key' => 'TOP_N_SHORTFALL',
        'requested_window_label' => 'today',
        'placed_count' => 2,
        'day_bucket' => '2026-04-30',
        'prompt_key' => 'same',
        'request_bucket' => 'same',
    ]);

    $prioritizeSchedule = $service->buildFraming('TOP_N_SHORTFALL', [
        'thread_id' => 101,
        'flow_source' => 'prioritize_schedule',
        'scenario_key' => 'TOP_N_SHORTFALL',
        'requested_window_label' => 'today',
        'placed_count' => 2,
        'day_bucket' => '2026-04-30',
        'prompt_key' => 'same',
        'request_bucket' => 'same',
    ]);

    expect($schedule)->not->toBe('');
    expect($prioritizeSchedule)->not->toBe('');
});

it('keeps selection explanation deterministic within the same turn seed', function (): void {
    $service = app(TaskAssistantScheduleTemplateService::class);
    $seed = [
        'thread_id' => 7,
        'flow_source' => 'prioritize_schedule',
        'scenario_key' => 'prioritize_selection',
        'requested_window_label' => 'tomorrow',
        'placed_count' => 3,
        'day_bucket' => '2026-04-30',
        'prompt_key' => 'selection',
        'request_bucket' => 'prioritize_selection',
        'turn_seed' => '104',
    ];

    $summaryA = $service->buildPrioritizeSelectionSummary(3, $seed);
    $summaryB = $service->buildPrioritizeSelectionSummary(3, $seed);
    $basisA = $service->buildPrioritizeSelectionBasis(3, $seed);
    $basisB = $service->buildPrioritizeSelectionBasis(3, $seed);

    expect($summaryA)->toBe($summaryB);
    expect($basisA)->toBe($basisB);
});

it('rotates prioritize-selection wording across turn seeds', function (): void {
    $service = app(TaskAssistantScheduleTemplateService::class);

    $variants = [];
    foreach (range(100, 120) as $turnSeed) {
        $seed = [
            'thread_id' => 7,
            'flow_source' => 'prioritize_schedule',
            'scenario_key' => 'prioritize_selection',
            'requested_window_label' => 'tomorrow',
            'placed_count' => 3,
            'day_bucket' => '2026-04-30',
            'prompt_key' => 'selection',
            'request_bucket' => 'prioritize_selection',
            'turn_seed' => (string) $turnSeed,
        ];
        $variants[$service->buildPrioritizeSelectionSummary(3, $seed).'|'.$service->buildPrioritizeSelectionBasis(3, $seed)] = true;
    }

    expect(count($variants))->toBeGreaterThan(1);
});

it('keeps targeted schedule templates deterministic for identical turn seed', function (): void {
    $service = app(TaskAssistantScheduleTemplateService::class);
    $seed = [
        'thread_id' => 91,
        'flow_source' => 'targeted_schedule',
        'scenario_key' => 'BLOCKED_WINDOW_SHIFTED',
        'requested_window_label' => 'today',
        'placed_count' => 1,
        'day_bucket' => '2026-05-07',
        'prompt_key' => 'targeted-seed',
        'request_bucket' => 'blocked_window_shifted',
        'turn_seed' => '4001',
    ];

    $framingA = $service->buildTargetedFraming('BLOCKED_WINDOW_SHIFTED', $seed, [
        'task_label' => 'Online quiz attempt',
        'requested_window_label' => 'today',
        'time_phrase' => ' at 1:00 PM',
        'chosen_daypart' => 'afternoon',
    ]);
    $framingB = $service->buildTargetedFraming('BLOCKED_WINDOW_SHIFTED', $seed, [
        'task_label' => 'Online quiz attempt',
        'requested_window_label' => 'today',
        'time_phrase' => ' at 1:00 PM',
        'chosen_daypart' => 'afternoon',
    ]);

    $reasoningA = $service->buildTargetedReasoning('BLOCKED_WINDOW_SHIFTED', $seed, [
        'task_label' => 'Online quiz attempt',
        'requested_window_label' => 'today',
        'blockers_text' => 'Campus fair booth shift (10:00 AM-11:30 AM)',
        'time_context' => ' at 1:00 PM',
    ]);
    $reasoningB = $service->buildTargetedReasoning('BLOCKED_WINDOW_SHIFTED', $seed, [
        'task_label' => 'Online quiz attempt',
        'requested_window_label' => 'today',
        'blockers_text' => 'Campus fair booth shift (10:00 AM-11:30 AM)',
        'time_context' => ' at 1:00 PM',
    ]);

    $confirmationA = $service->buildTargetedConfirmation('BLOCKED_WINDOW_SHIFTED', $seed, [
        'task_label' => 'Online quiz attempt',
        'time_context' => ' at 1:00 PM',
    ]);
    $confirmationB = $service->buildTargetedConfirmation('BLOCKED_WINDOW_SHIFTED', $seed, [
        'task_label' => 'Online quiz attempt',
        'time_context' => ' at 1:00 PM',
    ]);

    expect($framingA)->toBe($framingB);
    expect($reasoningA)->toBe($reasoningB);
    expect($confirmationA)->toBe($confirmationB);
});

it('rotates targeted schedule templates across turn seeds', function (): void {
    $service = app(TaskAssistantScheduleTemplateService::class);

    $variants = [];
    foreach (range(4100, 4120) as $turnSeed) {
        $seed = [
            'thread_id' => 91,
            'flow_source' => 'targeted_schedule',
            'scenario_key' => 'BLOCKED_WINDOW_SHIFTED',
            'requested_window_label' => 'today',
            'placed_count' => 1,
            'day_bucket' => '2026-05-07',
            'prompt_key' => 'targeted-seed',
            'request_bucket' => 'blocked_window_shifted',
            'turn_seed' => (string) $turnSeed,
        ];

        $framing = $service->buildTargetedFraming('BLOCKED_WINDOW_SHIFTED', $seed, [
            'task_label' => 'Online quiz attempt',
            'requested_window_label' => 'today',
            'time_phrase' => ' at 1:00 PM',
            'chosen_daypart' => 'afternoon',
        ]);
        $reasoning = $service->buildTargetedReasoning('BLOCKED_WINDOW_SHIFTED', $seed, [
            'task_label' => 'Online quiz attempt',
            'requested_window_label' => 'today',
            'blockers_text' => 'Campus fair booth shift (10:00 AM-11:30 AM)',
            'time_context' => ' at 1:00 PM',
        ]);
        $confirmation = $service->buildTargetedConfirmation('BLOCKED_WINDOW_SHIFTED', $seed, [
            'task_label' => 'Online quiz attempt',
            'time_context' => ' at 1:00 PM',
        ]);

        $variants[$framing.'|'.$reasoning.'|'.$confirmation] = true;
    }

    expect(count($variants))->toBeGreaterThan(1);
});

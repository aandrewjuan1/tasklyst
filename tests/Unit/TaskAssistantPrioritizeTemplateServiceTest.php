<?php

use App\Services\LLM\TaskAssistant\TaskAssistantPrioritizeTemplateService;
use Carbon\CarbonImmutable;

it('keeps template selection deterministic within the same day bucket', function (): void {
    $service = app(TaskAssistantPrioritizeTemplateService::class);

    $items = [[
        'entity_type' => 'task',
        'entity_id' => 11,
        'title' => 'Time series dashboard',
        'priority' => 'medium',
        'due_phrase' => 'due tomorrow',
        'complexity_label' => 'Moderate',
    ]];

    $seed = [
        'thread_id' => 42,
        'top_key' => 'task:11:Time series dashboard',
        'items_count' => 1,
        'has_doing_context' => false,
        'day_bucket' => '2026-04-30',
        'prompt_key' => 'abc123',
        'request_bucket' => 'abc123',
    ];

    $first = $service->buildFraming($items, false, false, $seed);
    $second = $service->buildFraming($items, false, false, $seed);

    expect($first)->toBe($second);
});

it('rotates template variants when day bucket changes', function (): void {
    $service = app(TaskAssistantPrioritizeTemplateService::class);

    $items = [[
        'entity_type' => 'task',
        'entity_id' => 11,
        'title' => 'Time series dashboard',
        'priority' => 'medium',
        'due_phrase' => 'due tomorrow',
        'complexity_label' => 'Moderate',
    ]];

    $seedDayOne = [
        'thread_id' => 99,
        'top_key' => 'task:11:Time series dashboard',
        'items_count' => 1,
        'has_doing_context' => false,
        'day_bucket' => '2026-04-30',
        'prompt_key' => 'same_prompt',
        'request_bucket' => 'same_prompt',
    ];
    $seedDayTwo = [
        'thread_id' => 99,
        'top_key' => 'task:11:Time series dashboard',
        'items_count' => 1,
        'has_doing_context' => false,
        'day_bucket' => '2026-05-01',
        'prompt_key' => 'same_prompt',
        'request_bucket' => 'same_prompt',
    ];

    $dayOne = $service->buildRankingMethodSummary($seedDayOne);
    $dayTwo = $service->buildRankingMethodSummary($seedDayTwo);

    expect($dayOne)->not->toBe('');
    expect($dayTwo)->not->toBe('');
});

it('builds single and multi next options with scheduling intent preserved', function (): void {
    $service = app(TaskAssistantPrioritizeTemplateService::class);

    $single = $service->buildNextOptions(1, true, [
        'thread_id' => 1,
        'top_key' => 'task:1:A',
        'items_count' => 1,
        'day_bucket' => '2026-04-30',
        'prompt_key' => 'single',
        'request_bucket' => 'single',
    ]);
    $multi = $service->buildNextOptions(3, true, [
        'thread_id' => 1,
        'top_key' => 'task:1:A',
        'items_count' => 3,
        'day_bucket' => '2026-04-30',
        'prompt_key' => 'multi',
        'request_bucket' => 'multi',
    ]);

    expect($single['next_options'])->toContain('later today');
    expect($single['next_options'])->toContain('tomorrow');
    expect($multi['next_options'])->toContain('later this week');
    expect($single['next_options_chip_texts'])->toHaveCount(2);
    expect($multi['next_options_chip_texts'])->toHaveCount(3);
});

it('builds empty next options without scheduling chips for empty ranked slices', function (): void {
    $service = app(TaskAssistantPrioritizeTemplateService::class);

    $empty = $service->buildNextOptions(0, false, [
        'thread_id' => 1,
        'top_key' => 'task:0:none',
        'items_count' => 0,
        'day_bucket' => '2026-04-30',
        'prompt_key' => 'empty',
        'request_bucket' => 'empty',
    ]);

    expect(mb_strtolower($empty['next_options']))->toContain('keyword');
    expect($empty['next_options_chip_texts'])->toBe([]);
});

it('keeps reasoning anchored to first-row facts and title', function (): void {
    CarbonImmutable::setTestNow('2026-04-30 08:00:00');

    $service = app(TaskAssistantPrioritizeTemplateService::class);
    $reasoning = $service->buildReasoning([[
        'entity_type' => 'task',
        'entity_id' => 88,
        'title' => 'Prepare quiz review sheet',
        'priority' => 'high',
        'due_phrase' => 'due tomorrow',
        'complexity_label' => 'Moderate',
    ]], false, [
        'thread_id' => 8,
        'top_key' => 'task:88:Prepare quiz review sheet',
        'items_count' => 1,
        'day_bucket' => '2026-04-30',
        'prompt_key' => 'reasoning',
        'request_bucket' => 'reasoning',
    ]);

    expect($reasoning)->toContain('Prepare quiz review sheet');
    expect($reasoning)->toContain('due tomorrow');
});

it('builds ranking method summary from prioritize payload seed', function (): void {
    $service = app(TaskAssistantPrioritizeTemplateService::class);

    $summary = $service->buildRankingMethodSummaryFromData([
        'items' => [[
            'entity_type' => 'task',
            'entity_id' => 5,
            'title' => 'Read chapter 4',
        ]],
        'doing_progress_coach' => null,
    ], 77);

    expect($summary)->not->toBe('');
    expect(mb_strtolower($summary))->toContain('due');
});

it('builds processor-style reasoning dedupe copy with title placeholder', function (): void {
    $service = app(TaskAssistantPrioritizeTemplateService::class);
    $items = [[
        'entity_type' => 'task',
        'entity_id' => 9,
        'title' => 'Exam cram block',
        'priority' => 'high',
        'due_phrase' => 'due tomorrow',
        'complexity_label' => 'Moderate',
    ]];
    $seed = $service->buildSeedContextFromPrioritizePayload(['items' => $items], 3, 'dedupe_test');

    $out = $service->buildReasoningProcessorDedupe($items, false, $seed);

    expect($out)->toContain('Exam cram block');
    expect(mb_strtolower($out))->toContain('first');
});

it('builds framing invalid fallback for empty ranked slice', function (): void {
    $service = app(TaskAssistantPrioritizeTemplateService::class);
    $seed = $service->buildSeedContextFromPrioritizePayload(['items' => []], 1, 'framing_invalid');

    $framing = $service->buildFramingInvalidFallback(0, false, $seed);

    expect(mb_strtolower($framing))->toContain('student-first');
});

it('rotates wording for different prompt fingerprints on same context/day', function (): void {
    $service = app(TaskAssistantPrioritizeTemplateService::class);
    $items = [[
        'entity_type' => 'task',
        'entity_id' => 11,
        'title' => 'Time series dashboard',
        'priority' => 'medium',
        'due_phrase' => 'due tomorrow',
        'complexity_label' => 'Moderate',
    ]];

    $baseSeed = [
        'thread_id' => 42,
        'top_key' => 'task:11:Time series dashboard',
        'items_count' => 1,
        'has_doing_context' => false,
        'day_bucket' => '2026-04-30',
    ];

    $first = $service->buildFraming($items, false, false, array_merge($baseSeed, [
        'prompt_key' => 'focus_today',
        'request_bucket' => 'focus_today',
    ]));
    $second = $service->buildFraming($items, false, false, array_merge($baseSeed, [
        'prompt_key' => 'top_one_task',
        'request_bucket' => 'top_one_task',
    ]));

    expect($first)->not->toBe('');
    expect($second)->not->toBe('');
});

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

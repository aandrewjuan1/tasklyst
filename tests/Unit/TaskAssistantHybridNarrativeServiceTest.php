<?php

use App\Services\LLM\TaskAssistant\TaskAssistantHybridNarrativeService;
use Illuminate\Support\Collection;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

test('daily_schedule narrative summary/reasoning stay consistent with blocks times', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'summary' => 'For best results, set aside two hours in the later evening (18:00 to 20:00) for Practice coding interview problems.',
                'assistant_note' => 'Wrong time explanation.',
                'reasoning' => 'This works because it runs from 18:00 to 20:00.',
                'strategy_points' => ['a'],
                'suggested_next_steps' => ['b'],
                'assumptions' => ['c'],
            ])
            ->withUsage(new Usage(1, 1)),
    ]);

    $service = new TaskAssistantHybridNarrativeService;

    $blocksJson = json_encode([
        [
            'start_time' => '18:00',
            'end_time' => '19:30',
            'label' => 'Practice coding interview problems',
            'note' => 'Planned by strict scheduler.',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $promptData = [
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-03-23',
            'end_date' => '2026-03-23',
            'label' => 'default_today',
        ],
    ];

    $result = $service->refineDailySchedule(
        historyMessages: new Collection,
        promptData: $promptData,
        userMessageContent: 'schedule top 1 for later evening',
        blocksJson: (string) $blocksJson,
        deterministicSummary: 'A focused schedule with clear blocks to structure your time',
        threadId: 1,
        userId: 1,
    );

    expect($result['summary'])->toContain('6:00 PM–7:30 PM');
    expect($result['summary'])->not->toContain('20:00');
    expect($result['reasoning'])->toContain('6:00 PM–7:30 PM');
    expect($result['reasoning'])->not->toContain('20:00');
    // We override assistant_note to avoid time drift.
    expect($result['assistant_note'])->not->toContain('Wrong time');
});

test('refinePrioritizeListing derives focus from items order and uses suggested_next_actions', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'acknowledgment' => 'You\'ve got this.',
                'framing' => 'Start with the most urgent items and move down the list.',
                'insight' => 'This order lines up with what tends to help first.',
                'reasoning' => 'Alpha is due soon, then Beta keeps momentum.',
                'suggested_next_actions' => [
                    'Start with Alpha and complete one small step.',
                    'Then open Beta and do a short focused session.',
                ],
                'next_actions_intro' => 'I recommend you take these next steps.',
                'next_options' => 'If you want, I can schedule these steps for later.',
                'next_options_chip_texts' => ['Schedule these for later'],
            ])
            ->withUsage(new Usage(1, 1)),
    ]);

    $service = app(TaskAssistantHybridNarrativeService::class);
    $items = [
        [
            'entity_type' => 'task',
            'entity_id' => 1,
            'title' => 'Alpha',
            'priority' => 'high',
            'due_phrase' => 'due today',
            'due_on' => 'Mar 1, 2026',
            'complexity_label' => 'Simple',
        ],
        [
            'entity_type' => 'task',
            'entity_id' => 2,
            'title' => 'Beta',
            'priority' => 'low',
            'due_phrase' => '',
            'due_on' => '—',
            'complexity_label' => 'Simple',
        ],
    ];

    $promptData = [
        'userContext' => ['id' => 1, 'name' => 'Tester', 'timezone' => 'UTC', 'date_format' => 'Y-m-d H:i'],
        'toolManifest' => [],
        'snapshot' => [
            'today' => '2026-03-01',
            'timezone' => 'UTC',
            'tasks' => [],
            'events' => [],
            'projects' => [],
        ],
        'route_context' => '',
    ];

    $result = $service->refinePrioritizeListing(
        promptData: $promptData,
        userMessage: 'prioritize my tasks',
        items: $items,
        deterministicSummary: 'Two tasks.',
        filterContextForPrompt: 'time: today',
        ambiguous: false,
        threadId: 1,
        userId: 1,
    );

    expect($result['focus']['main_task'])->toBe('Alpha');
    expect($result['focus']['secondary_tasks'])->toBe(['Beta']);
    expect($result['acknowledgment'])->toBeNull();
    expect($result['framing'])->toContain('most urgent items');
    expect($result['suggested_next_actions'])->toBe([
        'Start with Alpha and complete one small step.',
        'Then open Beta and do a short focused session.',
    ]);
    expect($result['items'][0])->not->toHaveKey('placement_blurb');
});

test('refinePrioritizeListing falls back suggested_next_actions and strips placement_blurb', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Focus on what you can finish first.',
                'acknowledgment' => null,
                'insight' => null,
                'reasoning' => 'This ordering helps you act quickly.',
                'suggested_next_actions' => [],
                'next_actions_intro' => 'I recommend you take these next steps.',
                'next_options' => 'If you want, I can schedule these steps for later.',
                'next_options_chip_texts' => ['Schedule these for later'],
            ])
            ->withUsage(new Usage(1, 1)),
    ]);

    $service = app(TaskAssistantHybridNarrativeService::class);
    $items = [
        [
            'entity_type' => 'task',
            'entity_id' => 1,
            'title' => 'Alpha',
            'priority' => 'high',
            'due_phrase' => 'due today',
            'due_on' => 'Mar 1, 2026',
            'complexity_label' => 'Simple',
            'placement_blurb' => 'Should be removed.',
        ],
        [
            'entity_type' => 'task',
            'entity_id' => 2,
            'title' => 'Beta',
            'priority' => 'low',
            'due_phrase' => '',
            'due_on' => '—',
            'complexity_label' => 'Simple',
        ],
    ];

    $promptData = [
        'userContext' => ['id' => 1, 'name' => 'Tester', 'timezone' => 'UTC', 'date_format' => 'Y-m-d H:i'],
        'toolManifest' => [],
        'snapshot' => [
            'today' => '2026-03-01',
            'timezone' => 'UTC',
            'tasks' => [],
            'events' => [],
            'projects' => [],
        ],
        'route_context' => '',
    ];

    $result = $service->refinePrioritizeListing(
        promptData: $promptData,
        userMessage: 'prioritize my tasks',
        items: $items,
        deterministicSummary: 'Two tasks.',
        filterContextForPrompt: 'time: today',
        ambiguous: false,
        threadId: 1,
        userId: 1,
    );

    expect($result['items'][0])->not->toHaveKey('placement_blurb');
    expect($result['suggested_next_actions'][0])->toContain('Alpha');
});

test('refinePrioritizeListing suppresses optional fields when UX include flags are false', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'acknowledgment' => 'You got this.',
                'framing' => 'Start with the most urgent items and move down the list.',
                'insight' => 'This is the order that makes sense.',
                'reasoning' => 'Because the two items match the same urgency.',
                'suggested_next_actions' => [
                    'Start with Alpha and complete one small step.',
                    'Then open Beta and do a short focused session.',
                ],
                'next_actions_intro' => 'I recommend you take these next steps.',
                'next_options' => 'If you want, I can schedule these steps for later.',
                'next_options_chip_texts' => ['Schedule these for later'],
            ])
            ->withUsage(new Usage(1, 1)),
    ]);

    $service = app(TaskAssistantHybridNarrativeService::class);
    $items = [
        [
            'entity_type' => 'task',
            'entity_id' => 1,
            'title' => 'Alpha',
            'priority' => 'high',
            'due_phrase' => 'due today',
            'due_on' => 'Mar 1, 2026',
            'complexity_label' => 'Simple',
        ],
        [
            'entity_type' => 'task',
            'entity_id' => 2,
            'title' => 'Beta',
            'priority' => 'high',
            'due_phrase' => 'due today',
            'due_on' => 'Mar 1, 2026',
            'complexity_label' => 'Simple',
        ],
    ];

    $promptData = [
        'userContext' => ['id' => 1, 'name' => 'Tester', 'timezone' => 'UTC', 'date_format' => 'Y-m-d H:i'],
        'toolManifest' => [],
        'snapshot' => [
            'today' => '2026-03-01',
            'timezone' => 'UTC',
            'tasks' => [],
            'events' => [],
            'projects' => [],
        ],
        'route_context' => '',
    ];

    $result = $service->refinePrioritizeListing(
        promptData: $promptData,
        userMessage: 'prioritize my tasks',
        items: $items,
        deterministicSummary: 'Two tasks.',
        filterContextForPrompt: 'time: today',
        ambiguous: false,
        threadId: 1,
        userId: 1,
    );

    expect($result['acknowledgment'])->toBeNull();
    expect($result['insight'])->toBeNull();
    expect($result['reasoning'])->toBe('Because the two items match the same urgency.');
});

test('refinePrioritizeListing includes optional fields when UX include flags are true', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'acknowledgment' => 'You got this.',
                'framing' => 'Use the earliest due work first.',
                'insight' => 'Due timing and priority don\'t line up, so this order helps.',
                'reasoning' => 'The due date makes this the best first move.',
                'suggested_next_actions' => [
                    'Start with Alpha and complete one small step.',
                    'Then open Beta and do a short focused session.',
                ],
                'next_actions_intro' => 'I recommend you take these next steps.',
                'next_options' => 'If you want, I can schedule these steps for later.',
                'next_options_chip_texts' => ['Schedule these for later'],
            ])
            ->withUsage(new Usage(1, 1)),
    ]);

    $service = app(TaskAssistantHybridNarrativeService::class);
    $items = [
        [
            'entity_type' => 'task',
            'entity_id' => 1,
            'title' => 'Alpha',
            'priority' => 'high',
            'due_phrase' => 'due tomorrow',
            'due_on' => 'Mar 2, 2026',
            'complexity_label' => 'Simple',
        ],
        [
            'entity_type' => 'task',
            'entity_id' => 2,
            'title' => 'Beta',
            'priority' => 'low',
            'due_phrase' => 'due today',
            'due_on' => 'Mar 1, 2026',
            'complexity_label' => 'Simple',
        ],
    ];

    $promptData = [
        'userContext' => ['id' => 1, 'name' => 'Tester', 'timezone' => 'UTC', 'date_format' => 'Y-m-d H:i'],
        'toolManifest' => [],
        'snapshot' => [
            'today' => '2026-03-01',
            'timezone' => 'UTC',
            'tasks' => [],
            'events' => [],
            'projects' => [],
        ],
        'route_context' => '',
    ];

    $result = $service->refinePrioritizeListing(
        promptData: $promptData,
        userMessage: 'I\'m overwhelmed, prioritize my tasks',
        items: $items,
        deterministicSummary: 'Two tasks.',
        filterContextForPrompt: 'time: today',
        ambiguous: false,
        threadId: 1,
        userId: 1,
    );

    expect($result['acknowledgment'])->toBe('You got this.');
    expect($result['insight'])->toBe('Due timing and priority don\'t line up, so this order helps.');
    expect($result['reasoning'])->toBe('The due date makes this the best first move.');
});

test('refinePrioritizeListing removes conflicting due timing from framing/actions', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'I recommend focusing on your high-priority tasks due tomorrow.',
                'acknowledgment' => null,
                'insight' => null,
                'reasoning' => 'Because tomorrow conflicts with the due phrases in your items.',
                'suggested_next_actions' => [
                    'Start with completing Alpha due tomorrow.',
                    'Then move to Beta due tomorrow.',
                ],
                'next_actions_intro' => 'I recommend you take these next steps.',
                'next_options' => 'If you want, I can schedule these steps for later.',
                'next_options_chip_texts' => ['Schedule these for later'],
            ])
            ->withUsage(new Usage(1, 1)),
    ]);

    $service = app(TaskAssistantHybridNarrativeService::class);
    $items = [
        [
            'entity_type' => 'task',
            'entity_id' => 1,
            'title' => 'Alpha',
            'priority' => 'high',
            'due_phrase' => 'due today',
            'due_on' => 'Mar 1, 2026',
            'complexity_label' => 'Simple',
        ],
        [
            'entity_type' => 'task',
            'entity_id' => 2,
            'title' => 'Beta',
            'priority' => 'low',
            'due_phrase' => 'due today',
            'due_on' => 'Mar 1, 2026',
            'complexity_label' => 'Simple',
        ],
    ];

    $promptData = [
        'userContext' => ['id' => 1, 'name' => 'Tester', 'timezone' => 'UTC', 'date_format' => 'Y-m-d H:i'],
        'toolManifest' => [],
        'snapshot' => [
            'today' => '2026-03-01',
            'timezone' => 'UTC',
            'tasks' => [],
            'events' => [],
            'projects' => [],
        ],
        'route_context' => '',
    ];

    $result = $service->refinePrioritizeListing(
        promptData: $promptData,
        userMessage: 'prioritize my tasks',
        items: $items,
        deterministicSummary: 'Two tasks.',
        filterContextForPrompt: 'time: today',
        ambiguous: false,
        threadId: 1,
        userId: 1,
    );

    expect($result['framing'])->not->toContain('tomorrow');
    expect(implode(' ', $result['suggested_next_actions']))->not->toContain('tomorrow');
    expect($result['suggested_next_actions'])->toBe([
        'Start with Alpha.',
        'Next, Beta.',
    ]);
});

test('refinePrioritizeListing ignores \"tomorrow\\u2019s\" in titles for due drift detection', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'You can handle this with a quick plan.',
                'acknowledgment' => null,
                'insight' => null,
                'reasoning' => 'This ordering matches your request.',
                'suggested_next_actions' => [
                    'Start with Prepare tomorrow\'s school bag.',
                    'Then move to Alpha.',
                ],
                'next_actions_intro' => 'I recommend you take these next steps.',
                'next_options' => 'If you want, I can schedule these steps for later.',
                'next_options_chip_texts' => ['Schedule these for later'],
            ])
            ->withUsage(new Usage(1, 1)),
    ]);

    $service = app(TaskAssistantHybridNarrativeService::class);
    $items = [
        [
            'entity_type' => 'task',
            'entity_id' => 1,
            'title' => 'Prepare tomorrow\'s school bag',
            'priority' => 'high',
            'due_phrase' => 'due today',
            'due_on' => 'Mar 1, 2026',
            'complexity_label' => 'Simple',
        ],
        [
            'entity_type' => 'task',
            'entity_id' => 2,
            'title' => 'Alpha',
            'priority' => 'high',
            'due_phrase' => 'due today',
            'due_on' => 'Mar 1, 2026',
            'complexity_label' => 'Simple',
        ],
    ];

    $promptData = [
        'userContext' => ['id' => 1, 'name' => 'Tester', 'timezone' => 'UTC', 'date_format' => 'Y-m-d H:i'],
        'toolManifest' => [],
        'snapshot' => [
            'today' => '2026-03-01',
            'timezone' => 'UTC',
            'tasks' => [],
            'events' => [],
            'projects' => [],
        ],
        'route_context' => '',
    ];

    $result = $service->refinePrioritizeListing(
        promptData: $promptData,
        userMessage: 'prioritize my tasks',
        items: $items,
        deterministicSummary: 'Two tasks.',
        filterContextForPrompt: 'time: today',
        ambiguous: false,
        threadId: 1,
        userId: 1,
    );

    expect($result['framing'])->toBe('You can handle this with a quick plan.');
    expect(implode(' ', $result['suggested_next_actions']))->toContain('tomorrow');
});

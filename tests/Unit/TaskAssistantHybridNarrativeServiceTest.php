<?php

use App\Services\LLM\TaskAssistant\TaskAssistantHybridNarrativeService;
use Illuminate\Support\Collection;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

test('daily_schedule narrative keeps deterministic reasoning times when model returns empty reasoning', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Here is a focused plan for your requested window.',
                'reasoning' => '',
                'confirmation' => 'Does this evening block feel doable, or should we slide it earlier?',
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
        'userContext' => ['id' => 1, 'name' => 'Tester', 'timezone' => 'UTC', 'date_format' => 'Y-m-d H:i'],
        'toolManifest' => [],
        'snapshot' => [
            'today' => '2026-03-23',
            'timezone' => 'UTC',
            'tasks' => [],
            'events' => [],
            'projects' => [],
        ],
        'route_context' => '',
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
        isEmptyPlacement: false,
        schedulableProposalCount: 1,
    );

    expect($result['reasoning'])->toContain('6:00 PM–7:30 PM');
    expect($result['reasoning'])->not->toContain('20:00');
    expect($result['framing'])->not->toBe('');
    expect($result['confirmation'])->not->toBe('');
    expect($result['confirmation'])->toContain('earlier');
});

test('daily_schedule narrative sanitizes mismatched explicit confirmation time and duration claims', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Here is a focused plan.',
                'reasoning' => 'This order keeps momentum.',
                'confirmation' => "Does this afternoon schedule feel workable? I've planned a 1-hour study block starting at 3 PM.",
            ])
            ->withUsage(new Usage(1, 1)),
    ]);

    $service = new TaskAssistantHybridNarrativeService;

    $blocksJson = json_encode([
        [
            'start_time' => '18:00',
            'end_time' => '21:00',
            'label' => 'Long focus block',
            'note' => 'Planned by strict scheduler.',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $promptData = [
        'userContext' => ['id' => 1, 'name' => 'Tester', 'timezone' => 'UTC', 'date_format' => 'Y-m-d H:i'],
        'toolManifest' => [],
        'snapshot' => [
            'today' => '2026-03-23',
            'timezone' => 'UTC',
            'tasks' => [],
            'events' => [],
            'projects' => [],
        ],
        'route_context' => '',
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
        userMessageContent: 'schedule this for later evening',
        blocksJson: (string) $blocksJson,
        deterministicSummary: 'A focused schedule with clear blocks',
        threadId: 1,
        userId: 1,
        isEmptyPlacement: false,
        schedulableProposalCount: 1,
    );

    expect($result['confirmation'])->toContain('block lengths feel workable');
    expect($result['confirmation'])->not->toContain('1-hour');
    expect($result['confirmation'])->not->toContain('3 PM');
});

test('daily_schedule narrative sanitizes contradictory relative date wording against explicit date', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'I suggest we start now.',
                'reasoning' => "It's urgent due tomorrow (Mar 30, 2026), so let's begin.",
                'confirmation' => 'Do these times and block lengths feel workable?',
            ])
            ->withUsage(new Usage(1, 1)),
    ]);

    $service = new TaskAssistantHybridNarrativeService;

    $blocksJson = json_encode([
        [
            'start_time' => '13:00',
            'end_time' => '14:00',
            'label' => 'Impossible 5h study block before quiz',
            'note' => 'Planned by strict scheduler.',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $promptData = [
        'userContext' => ['id' => 1, 'name' => 'Tester', 'timezone' => 'UTC', 'date_format' => 'Y-m-d H:i'],
        'toolManifest' => [],
        'snapshot' => [
            'today' => '2026-03-31',
            'timezone' => 'UTC',
            'tasks' => [],
            'events' => [],
            'projects' => [],
        ],
        'route_context' => '',
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-03-31',
            'end_date' => '2026-03-31',
            'label' => 'default_today',
        ],
    ];

    $result = $service->refineDailySchedule(
        historyMessages: new Collection,
        promptData: $promptData,
        userMessageContent: 'schedule them for later',
        blocksJson: (string) $blocksJson,
        deterministicSummary: 'A focused schedule with clear blocks',
        threadId: 1,
        userId: 1,
        isEmptyPlacement: false,
        schedulableProposalCount: 1,
    );

    expect($result['reasoning'])->toContain('due on Mar 30, 2026');
    expect($result['reasoning'])->not->toContain('due tomorrow (Mar 30, 2026)');
});

test('daily_schedule narrative strips internal placement jargon from model output', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Here is your plan.',
                'reasoning' => 'This order works well. It also keeps us within our default placement window.',
                'confirmation' => 'Do these times feel workable?',
            ])
            ->withUsage(new Usage(1, 1)),
    ]);

    $service = new TaskAssistantHybridNarrativeService;

    $blocksJson = json_encode([
        [
            'start_time' => '13:00',
            'end_time' => '14:00',
            'label' => 'Study block',
            'note' => 'Planned by strict scheduler.',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $promptData = [
        'userContext' => ['id' => 1, 'name' => 'Tester', 'timezone' => 'UTC', 'date_format' => 'Y-m-d H:i'],
        'toolManifest' => [],
        'snapshot' => [
            'today' => '2026-03-31',
            'timezone' => 'UTC',
            'tasks' => [],
            'events' => [],
            'projects' => [],
        ],
        'route_context' => '',
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-03-31',
            'end_date' => '2026-03-31',
            'label' => 'default_today',
        ],
    ];

    $result = $service->refineDailySchedule(
        historyMessages: new Collection,
        promptData: $promptData,
        userMessageContent: 'schedule for later',
        blocksJson: (string) $blocksJson,
        deterministicSummary: 'A focused schedule',
        threadId: 1,
        userId: 1,
        isEmptyPlacement: false,
        schedulableProposalCount: 1,
    );

    expect(mb_strtolower((string) $result['reasoning']))->not->toContain('placement window');
    expect((string) $result['reasoning'])->not->toContain('default_today');
});

test('refinePrioritizeListing derives focus from items order and uses suggested_next_actions', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'acknowledgment' => 'You\'ve got this.',
                'framing' => 'Start with the most urgent items and move down the list.',
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
    expect($result['doing_progress_coach'])->toBeNull();
    expect($result['framing'])->toContain('most urgent items');
    expect($result)->not->toHaveKey('suggested_next_actions');
    expect($result)->not->toHaveKey('next_actions_intro');
    expect($result['reasoning'])->toContain('Alpha');
    expect($result['items'][0])->not->toHaveKey('placement_blurb');
});

test('refinePrioritizeListing falls back suggested_next_actions and strips placement_blurb', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Focus on what you can finish first.',
                'acknowledgment' => null,
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
    expect($result)->not->toHaveKey('suggested_next_actions');
    expect($result['reasoning'])->toContain('Alpha');
});

test('refinePrioritizeListing suppresses optional fields when UX include flags are false', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'acknowledgment' => 'You got this.',
                'framing' => 'Start with the most urgent items and move down the list.',
                'reasoning' => 'Because Alpha and Beta share the same due today urgency, I kept a consistent order so you can start without debating which comes first.',
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
    expect($result['reasoning'])->toContain('Alpha');
    expect($result['reasoning'])->toContain('due today');
});

test('refinePrioritizeListing includes acknowledgment when UX include flags are true', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'acknowledgment' => 'You got this.',
                'framing' => 'Use the earliest due work first.',
                'reasoning' => 'The due dates make Alpha the best first move since it is due tomorrow, while Beta is still due today and can follow right after.',
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

    expect($result['acknowledgment'])->not->toBeNull();
    expect(mb_strtolower((string) $result['acknowledgment']))->toContain('i get it');
    expect($result['reasoning'])->toContain('Alpha');
    expect($result['reasoning'])->toContain('due tomorrow');
});

test('refinePrioritizeListing provides a single-item start guidance in reasoning', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'acknowledgment' => null,
                'framing' => 'I recommend focusing on your top task first.',
                'reasoning' => 'Because I think this is the best order.',
                'suggested_next_actions' => [
                    'First do Alpha.',
                    'Then do Beta.',
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
        deterministicSummary: 'One task.',
        filterContextForPrompt: 'time: today',
        ambiguous: false,
        threadId: 1,
        userId: 1,
    );

    expect($result['items'])->toHaveCount(1);
    expect($result)->not->toHaveKey('suggested_next_actions');
    expect($result)->not->toHaveKey('next_actions_intro');
    expect($result['reasoning'])->toContain('Alpha');
    expect($result['reasoning'])->not->toContain('first on this ordered list');
});

test('refinePrioritizeListing removes conflicting due timing from framing', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'I recommend focusing on your high-priority tasks due tomorrow.',
                'acknowledgment' => null,
                'reasoning' => 'Because tomorrow conflicts with the due phrases in your items.',
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
});

test('refinePrioritizeListing removes conflicting due timing from reasoning', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Here is your slice.',
                'acknowledgment' => null,
                'reasoning' => 'The notes are due later, so you can take your time.',
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
            'title' => 'Reading',
            'priority' => 'medium',
            'due_phrase' => 'due on Apr 10, 2026',
            'due_on' => 'Apr 10, 2026',
            'complexity_label' => 'Simple',
        ],
    ];

    $promptData = [
        'userContext' => ['id' => 1, 'name' => 'Tester', 'timezone' => 'UTC', 'date_format' => 'Y-m-d H:i'],
        'toolManifest' => [],
        'snapshot' => [
            'today' => '2026-03-30',
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
        deterministicSummary: 'One task.',
        filterContextForPrompt: 'no strong filters',
        ambiguous: false,
        threadId: 1,
        userId: 1,
    );

    expect($result['reasoning'])->not->toContain('due later');
    expect($result['reasoning'])->toContain('due on Apr 10, 2026');
});

test('refinePrioritizeListing replaces due soon framing with singular overdue opener when one task is overdue', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'I suggest handling what is due soon first.',
                'acknowledgment' => null,
                'reasoning' => 'These \'Alpha\' tasks are overdue, so start there.',
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
            'due_phrase' => 'overdue',
            'due_on' => 'Mar 27, 2026',
            'complexity_label' => 'Simple',
        ],
        [
            'entity_type' => 'task',
            'entity_id' => 2,
            'title' => 'Beta',
            'priority' => 'low',
            'due_phrase' => 'due later',
            'due_on' => 'Apr 10, 2026',
            'complexity_label' => 'Simple',
        ],
    ];

    $promptData = [
        'userContext' => ['id' => 1, 'name' => 'Tester', 'timezone' => 'UTC', 'date_format' => 'Y-m-d H:i'],
        'toolManifest' => [],
        'snapshot' => [
            'today' => '2026-03-28',
            'timezone' => 'UTC',
            'tasks' => [],
            'events' => [],
            'projects' => [],
        ],
        'route_context' => '',
    ];

    $result = $service->refinePrioritizeListing(
        promptData: $promptData,
        userMessage: 'what should I focus on today',
        items: $items,
        deterministicSummary: 'Two tasks.',
        filterContextForPrompt: 'time: today (includes overdue and anything due today)',
        ambiguous: false,
        threadId: 1,
        userId: 1,
    );

    expect($result['framing'])->toContain('overdue item first');
    expect($result['framing'])->not->toContain('overdue items first');
    expect($result['reasoning'])->toContain("This 'Alpha' task is");
    expect($result['reasoning'])->not->toContain("These 'Alpha' tasks are");
});

test('refinePrioritizeListing strips overdue duration units in reasoning', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Here is your slice.',
                'acknowledgment' => null,
                'reasoning' => 'This complex task has been overdue for over a month, so tackle it first.',
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
            'due_phrase' => 'overdue',
            'due_on' => 'Mar 30, 2026',
            'complexity_label' => 'Simple',
        ],
    ];

    $promptData = [
        'userContext' => ['id' => 1, 'name' => 'Tester', 'timezone' => 'UTC', 'date_format' => 'Y-m-d H:i'],
        'toolManifest' => [],
        'snapshot' => [
            'today' => '2026-03-31',
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
        deterministicSummary: 'One task.',
        filterContextForPrompt: 'time: today (includes overdue and anything due today)',
        ambiguous: false,
        threadId: 1,
        userId: 1,
    );

    expect($result['reasoning'])->toContain('overdue');
    expect(mb_strtolower((string) $result['reasoning']))->not->toContain('month');
    expect(mb_strtolower((string) $result['reasoning']))->toMatch('/task\s+(is|has\s+been)\s+overdue/iu');
});

test('refinePrioritizeListing ignores \"tomorrow\\u2019s\" in titles for due drift detection', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'You can handle this with a quick plan.',
                'acknowledgment' => null,
                'reasoning' => 'This ordering matches your request.',
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
    expect($result)->not->toHaveKey('suggested_next_actions');
});

test('refinePrioritizeListing ensures stressed prompts yield an empathetic acknowledgment (not generic momentum framing)', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                // Model output is generic and duplicates framing; post-processing should replace acknowledgment.
                'acknowledgment' => 'Here is a focused starting point to help you get momentum.',
                'framing' => 'Here is a focused starting point to help you get momentum.',
                'reasoning' => 'This ordering matches what you asked for.',
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
            'priority' => 'medium',
            'due_phrase' => 'due today',
            'due_on' => 'Mar 1, 2026',
            'complexity_label' => 'Not set',
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
        userMessage: 'im so stressed what should i do first for today?',
        items: $items,
        deterministicSummary: 'One task.',
        filterContextForPrompt: 'time: today',
        ambiguous: false,
        threadId: 1,
        userId: 1,
    );

    expect($result['acknowledgment'])->not->toBeNull();
    expect(mb_strtolower((string) $result['acknowledgment']))->toContain('i hear you');
    expect($result['acknowledgment'])->not->toBe('Here is a focused starting point to help you get momentum.');
    expect($result['framing'])->toBe('Here is a focused starting point to help you get momentum.');
});

test('refinePrioritizeListing strips bracketed artifacts from next_options', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'acknowledgment' => 'You got this.',
                'framing' => 'Start with the most urgent item.',
                'reasoning' => 'This ordering matches what you asked for.',
                'next_options' => '[Continue with other tasks after this one or, if needed, consider setting aside some time later to work on these items]',
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
            'priority' => 'medium',
            'due_phrase' => 'overdue',
            'due_on' => 'Mar 22, 2026',
            'complexity_label' => 'Not set',
        ],
    ];

    $promptData = [
        'userContext' => ['id' => 1, 'name' => 'Tester', 'timezone' => 'UTC', 'date_format' => 'Y-m-d H:i'],
        'toolManifest' => [],
        'snapshot' => [
            'today' => '2026-03-22',
            'timezone' => 'UTC',
            'tasks' => [],
            'events' => [],
            'projects' => [],
        ],
        'route_context' => '',
    ];

    $result = $service->refinePrioritizeListing(
        promptData: $promptData,
        userMessage: 'i am overwhelmed, prioritize my tasks',
        items: $items,
        deterministicSummary: 'One task.',
        filterContextForPrompt: 'time: today',
        ambiguous: false,
        threadId: 1,
        userId: 1,
    );

    expect($result['next_options'])->not->toStartWith('[');
    expect($result['next_options'])->not->toEndWith(']');
});

test('refinePrioritizeListing sanitizes visibility overclaims and avoids bundled steps', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'I\'ve reviewed your tasks and here is what to do first.',
                'acknowledgment' => null,
                'reasoning' => 'This ordering matches what you asked for.',
                'suggested_next_actions' => [
                    'Start with the first two overdue tasks.',
                    'Then do the last one.',
                ],
                'next_actions_intro' => 'I recommend you take these next steps.',
                'next_options' => 'Once those tasks are completed, consider rescheduling them for later.',
                'next_options_chip_texts' => ['Schedule for later'],
            ])
            ->withUsage(new Usage(1, 1)),
    ]);

    $service = app(TaskAssistantHybridNarrativeService::class);
    $items = [
        [
            'entity_type' => 'task',
            'entity_id' => 1,
            'title' => 'Alpha',
            'priority' => 'medium',
            'due_phrase' => 'overdue',
            'due_on' => 'Mar 22, 2026',
            'complexity_label' => 'Not set',
        ],
        [
            'entity_type' => 'task',
            'entity_id' => 2,
            'title' => 'Beta',
            'priority' => 'medium',
            'due_phrase' => 'overdue',
            'due_on' => 'Mar 22, 2026',
            'complexity_label' => 'Not set',
        ],
        [
            'entity_type' => 'task',
            'entity_id' => 3,
            'title' => 'Gamma',
            'priority' => 'medium',
            'due_phrase' => 'overdue',
            'due_on' => 'Mar 22, 2026',
            'complexity_label' => 'Not set',
        ],
    ];

    $promptData = [
        'userContext' => ['id' => 1, 'name' => 'Tester', 'timezone' => 'UTC', 'date_format' => 'Y-m-d H:i'],
        'toolManifest' => [],
        'snapshot' => [
            'today' => '2026-03-22',
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
        deterministicSummary: 'Three tasks.',
        filterContextForPrompt: 'time: today',
        ambiguous: false,
        threadId: 1,
        userId: 1,
    );

    expect(mb_strtolower($result['framing']))->not->toContain('reviewed');
    expect($result)->not->toHaveKey('suggested_next_actions');
    expect($result['reasoning'])->toContain('Alpha');
    expect(mb_strtolower($result['next_options']))->toContain('remaining');
});

test('refinePrioritizeListing replaces over-claimy neutral framing with grounded default', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Based on your current priorities, start with the first item.',
                'acknowledgment' => null,
                'reasoning' => 'This ordering matches what you asked for.',
                'next_options' => 'If you want, I can schedule these steps for later.',
                'next_options_chip_texts' => ['Schedule these for later'],
            ])
            ->withUsage(new Usage(1, 1)),
    ]);

    $service = app(TaskAssistantHybridNarrativeService::class);
    $items = [[
        'entity_type' => 'task',
        'entity_id' => 1,
        'title' => 'Alpha',
        'priority' => 'medium',
        'due_phrase' => 'overdue',
        'due_on' => 'Mar 22, 2026',
        'complexity_label' => 'Not set',
    ]];

    $promptData = [
        'userContext' => ['id' => 1, 'name' => 'Tester', 'timezone' => 'UTC', 'date_format' => 'Y-m-d H:i'],
        'toolManifest' => [],
        'snapshot' => [
            'today' => '2026-03-22',
            'timezone' => 'UTC',
            'tasks' => [],
            'events' => [],
            'projects' => [],
        ],
        'route_context' => '',
    ];

    $result = $service->refinePrioritizeListing(
        promptData: $promptData,
        userMessage: 'what should i do first?',
        items: $items,
        deterministicSummary: 'One task.',
        filterContextForPrompt: 'time: today',
        ambiguous: false,
        threadId: 1,
        userId: 1,
    );

    expect($result['framing'])->toBe("I'd suggest you start with the first item.");
});

test('refinePrioritizeListing handles mixed entity types without insight field', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Here is a focused list you can act on right away.',
                'acknowledgment' => null,
                'reasoning' => 'This ordering matches what you asked for.',
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
            'priority' => 'medium',
            'due_phrase' => 'due today',
            'due_on' => 'Mar 1, 2026',
            'complexity_label' => 'Not set',
        ],
        [
            'entity_type' => 'project',
            'entity_id' => 2,
            'title' => 'Project Beta',
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
        userMessage: 'what are my top priorities today?',
        items: $items,
        deterministicSummary: 'Two items.',
        filterContextForPrompt: 'time: today',
        ambiguous: false,
        threadId: 1,
        userId: 1,
    );

    expect($result)->not->toHaveKey('insight');
});

test('refinePrioritizeListing does not append ordered-list boilerplate when reasoning is long but omits the top title', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Here are your top priorities.',
                'acknowledgment' => null,
                // Bad: talks about item #2 only, not item #1 (event).
                'reasoning' => 'You have an important essay to research, so start there.',
                'next_options' => 'If you want, I can schedule these steps for later.',
                'next_options_chip_texts' => ['Schedule these', 'Show next 3'],
            ])
            ->withUsage(new Usage(1, 1)),
    ]);

    $service = app(TaskAssistantHybridNarrativeService::class);
    $items = [
        [
            'entity_type' => 'event',
            'entity_id' => 2,
            'title' => 'CS group project meetup',
        ],
        [
            'entity_type' => 'task',
            'entity_id' => 26,
            'title' => 'Library research for history essay',
            'priority' => 'high',
            'due_phrase' => 'due later',
            'due_on' => 'Apr 12, 2026',
            'complexity_label' => 'Moderate',
        ],
    ];

    $promptData = [
        'userContext' => ['id' => 1, 'name' => 'Tester', 'timezone' => 'UTC', 'date_format' => 'Y-m-d H:i'],
        'toolManifest' => [],
        'snapshot' => [
            'today' => '2026-03-22',
            'timezone' => 'UTC',
            'tasks' => [],
            'events' => [],
            'projects' => [],
        ],
        'route_context' => '',
    ];

    $result = $service->refinePrioritizeListing(
        promptData: $promptData,
        userMessage: 'show next 3',
        items: $items,
        deterministicSummary: 'Two items.',
        filterContextForPrompt: 'no strong filters',
        ambiguous: false,
        threadId: 1,
        userId: 1,
    );

    expect($result['reasoning'])->toContain('CS group project meetup');
    expect($result['reasoning'])->not->toContain('Library research for history essay');
    expect($result['reasoning'])->not->toContain('important essay');
    expect($result['reasoning'])->not->toContain('first on this ordered list');
    expect($result['reasoning'])->not->toContain("when you're ready");
});

test('refinePrioritizeListing does not use connection fallbacks when the model returns an empty payload but the request succeeded', function (): void {
    Prism::fake([]);

    $service = app(TaskAssistantHybridNarrativeService::class);
    $items = [
        [
            'entity_type' => 'task',
            'entity_id' => 31,
            'title' => 'Impossible 5h study block before quiz',
            'priority' => 'urgent',
            'due_phrase' => 'overdue',
            'due_on' => 'Mar 27, 2026',
            'complexity_label' => 'Complex',
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
        userMessage: 'hi bro what should i do first?',
        items: $items,
        deterministicSummary: 'Found 1 task(s).',
        filterContextForPrompt: 'none',
        ambiguous: false,
        threadId: 1,
        userId: 1,
    );

    expect($result['framing'])->toContain('Found 1 task');
    expect($result['reasoning'])->toContain('same urgency rules');
    expect($result['reasoning'])->not->toContain('Impossible 5h study block');
});

test('refinePrioritizeListing returns doing_progress_coach when doing_context is present', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'acknowledgment' => null,
                'framing' => 'Here is a clear next step from your ranked slice.',
                'reasoning' => 'Alpha is first because it is due today.',
                'doing_progress_coach' => 'Lean on what you have already started before you add new commitments—that keeps switching costs lower.',
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
        'doing_context' => [
            'has_doing_tasks' => true,
            'doing_titles' => ['Other in progress'],
            'doing_count' => 1,
        ],
    ];

    $result = $service->refinePrioritizeListing(
        promptData: $promptData,
        userMessage: 'prioritize my tasks',
        items: $items,
        deterministicSummary: 'One task.',
        filterContextForPrompt: 'time: today',
        ambiguous: false,
        threadId: 1,
        userId: 1,
    );

    expect($result['doing_progress_coach'])->toContain('switching');
});

test('refinePrioritizeListing replaces doing_progress_coach when it quotes ITEMS_JSON titles', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'acknowledgment' => null,
                'framing' => 'Start with Alpha first.',
                'reasoning' => 'Alpha is first because it is due today.',
                'doing_progress_coach' => 'You already made progress on Alpha and Beta—keep going.',
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
        'doing_context' => [
            'has_doing_tasks' => true,
            'doing_titles' => ['Doing task only'],
            'doing_count' => 1,
        ],
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

    expect($result['doing_progress_coach'])->not->toContain('Alpha');
    expect($result['doing_progress_coach'])->not->toContain('Beta');
    expect($result['framing'])->toBeNull();
});

test('refinePrioritizeListing grounds generic reasoning with first row due_phrase', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Here is your slice.',
                'acknowledgment' => 'You can handle this.',
                'reasoning' => 'This complex task will help you prepare for your upcoming quiz on Mar 30th.',
                'next_options' => 'If you want, I can schedule these steps for later.',
                'next_options_chip_texts' => ['Schedule these for later'],
                'doing_progress_coach' => 'Wrap up what you started before adding more.',
            ])
            ->withUsage(new Usage(1, 1)),
    ]);

    $service = app(TaskAssistantHybridNarrativeService::class);
    $items = [
        [
            'entity_type' => 'task',
            'entity_id' => 1,
            'title' => 'Alpha',
            'priority' => 'urgent',
            'due_phrase' => 'overdue',
            'due_on' => 'Mar 30, 2026',
            'complexity_label' => 'Complex',
        ],
        [
            'entity_type' => 'task',
            'entity_id' => 2,
            'title' => 'Beta',
            'priority' => 'medium',
            'due_phrase' => 'due later',
            'due_on' => 'Apr 10, 2026',
            'complexity_label' => 'Simple',
        ],
    ];

    $promptData = [
        'userContext' => ['id' => 1, 'name' => 'Tester', 'timezone' => 'UTC', 'date_format' => 'Y-m-d H:i'],
        'toolManifest' => [],
        'snapshot' => [
            'today' => '2026-03-31',
            'timezone' => 'UTC',
            'tasks' => [],
            'events' => [],
            'projects' => [],
        ],
        'route_context' => '',
        'doing_context' => [
            'has_doing_tasks' => true,
            'doing_titles' => ['Doing task only'],
            'doing_count' => 1,
        ],
    ];

    $result = $service->refinePrioritizeListing(
        promptData: $promptData,
        userMessage: 'prioritize my tasks',
        items: $items,
        deterministicSummary: 'Two tasks.',
        filterContextForPrompt: 'time: today (includes overdue and anything due today)',
        ambiguous: false,
        threadId: 1,
        userId: 1,
    );

    expect($result['reasoning'])->toContain('Alpha');
    expect($result['reasoning'])->toContain('overdue');
    expect(mb_strtolower((string) $result['reasoning']))->not->toContain('upcoming quiz');
    expect((string) $result['reasoning'])->toMatch('/\btackle\b/iu');
});

test('refinePrioritizeListing appends coaching tone tail for single-item generic reasoning', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Here is your slice.',
                'acknowledgment' => 'You can handle this.',
                'reasoning' => 'This complex task will help you prepare for your upcoming quiz on Mar 30th.',
                'next_options' => 'If you want, I can schedule these steps for later.',
                'next_options_chip_texts' => ['Schedule these for later'],
                'doing_progress_coach' => 'Wrap up what you started before adding more.',
            ])
            ->withUsage(new Usage(1, 1)),
    ]);

    $service = app(TaskAssistantHybridNarrativeService::class);
    $items = [
        [
            'entity_type' => 'task',
            'entity_id' => 1,
            'title' => 'Alpha',
            'priority' => 'urgent',
            'due_phrase' => 'overdue',
            'due_on' => 'Mar 30, 2026',
            'complexity_label' => 'Complex',
        ],
    ];

    $promptData = [
        'userContext' => ['id' => 1, 'name' => 'Tester', 'timezone' => 'UTC', 'date_format' => 'Y-m-d H:i'],
        'toolManifest' => [],
        'snapshot' => [
            'today' => '2026-03-31',
            'timezone' => 'UTC',
            'tasks' => [],
            'events' => [],
            'projects' => [],
        ],
        'route_context' => '',
        'doing_context' => [
            'has_doing_tasks' => true,
            'doing_titles' => ['Doing task only'],
            'doing_count' => 1,
        ],
    ];

    $result = $service->refinePrioritizeListing(
        promptData: $promptData,
        userMessage: 'prioritize my tasks',
        items: $items,
        deterministicSummary: 'One task.',
        filterContextForPrompt: 'time: today (includes overdue and anything due today)',
        ambiguous: false,
        threadId: 1,
        userId: 1,
    );

    expect($result['reasoning'])->toContain('overdue');
    expect((string) $result['reasoning'])->toMatch('/\btackle\b/iu');
});

test('refinePrioritizeListing does not append coaching tone tail when reasoning already has motivation', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Here is your slice.',
                'acknowledgment' => null,
                'reasoning' => 'I’d tackle Alpha first because it is overdue. This urgent priority helps you build momentum and feel caught up, so you can focus your study time without rushing or spiraling.',
                'next_options' => 'If you want, I can schedule these steps for later.',
                'next_options_chip_texts' => ['Schedule these for later'],
                'doing_progress_coach' => null,
            ])
            ->withUsage(new Usage(1, 1)),
    ]);

    $service = app(TaskAssistantHybridNarrativeService::class);
    $items = [
        [
            'entity_type' => 'task',
            'entity_id' => 1,
            'title' => 'Alpha',
            'priority' => 'urgent',
            'due_phrase' => 'overdue',
            'due_on' => 'Mar 30, 2026',
            'complexity_label' => 'Complex',
        ],
    ];

    $promptData = [
        'userContext' => ['id' => 1, 'name' => 'Tester', 'timezone' => 'UTC', 'date_format' => 'Y-m-d H:i'],
        'toolManifest' => [],
        'snapshot' => [
            'today' => '2026-03-31',
            'timezone' => 'UTC',
            'tasks' => [],
            'events' => [],
            'projects' => [],
        ],
        'route_context' => '',
        'doing_context' => [
            'has_doing_tasks' => false,
            'doing_titles' => [],
            'doing_count' => 0,
        ],
    ];

    $result = $service->refinePrioritizeListing(
        promptData: $promptData,
        userMessage: 'prioritize my tasks',
        items: $items,
        deterministicSummary: 'One task.',
        filterContextForPrompt: 'time: today (includes overdue and anything due today)',
        ambiguous: false,
        threadId: 1,
        userId: 1,
    );

    expect($result['reasoning'])->toContain('momentum');
    expect((string) $result['reasoning'])->not->toContain('overdue work first');
});

<?php

use App\Enums\TaskAssistantPrioritizeVariant;
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
        variant: TaskAssistantPrioritizeVariant::Rank,
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
        variant: TaskAssistantPrioritizeVariant::Rank,
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
        variant: TaskAssistantPrioritizeVariant::Rank,
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
        variant: TaskAssistantPrioritizeVariant::Rank,
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
        variant: TaskAssistantPrioritizeVariant::Rank,
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
        variant: TaskAssistantPrioritizeVariant::Rank,
        items: $items,
        deterministicSummary: 'Two tasks.',
        filterContextForPrompt: 'time: today',
        ambiguous: false,
        threadId: 1,
        userId: 1,
    );

    expect($result['framing'])->not->toContain('tomorrow');
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
        variant: TaskAssistantPrioritizeVariant::Rank,
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
        variant: TaskAssistantPrioritizeVariant::Rank,
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
        variant: TaskAssistantPrioritizeVariant::Rank,
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
        variant: TaskAssistantPrioritizeVariant::Rank,
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
        variant: TaskAssistantPrioritizeVariant::Rank,
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
        variant: TaskAssistantPrioritizeVariant::Rank,
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
        variant: TaskAssistantPrioritizeVariant::Rank,
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
        variant: TaskAssistantPrioritizeVariant::FollowupSlice,
        items: $items,
        deterministicSummary: 'Two items.',
        filterContextForPrompt: 'no strong filters',
        ambiguous: false,
        threadId: 1,
        userId: 1,
    );

    expect($result['reasoning'])->toBe('You have an important essay to research, so start there.');
    expect($result['reasoning'])->not->toContain('first on this ordered list');
    expect($result['reasoning'])->not->toContain("when you're ready");
});

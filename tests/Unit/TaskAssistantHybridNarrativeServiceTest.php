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

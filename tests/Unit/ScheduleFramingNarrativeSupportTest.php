<?php

use App\Services\LLM\TaskAssistant\ScheduleFramingNarrativeSupport;

test('buildFallback rotates templates across seeds for the same inputs', function (): void {
    $blocks = [
        ['start_time' => '18:00', 'end_time' => '19:00', 'label' => 'Read chapter 4'],
    ];
    $promptData = [
        'user_context' => [
            'schedule_intent_flags' => [
                'has_evening' => true,
            ],
        ],
    ];

    $seen = [];
    for ($i = 0; $i < 64; $i++) {
        $out = ScheduleFramingNarrativeSupport::buildFallback(
            $blocks,
            $promptData,
            1,
            'same user text',
            'rotate|'.$i
        );
        $seen[$out] = true;
    }

    expect(count($seen))->toBeGreaterThan(1);
});

test('sanitizeModelFraming clears prioritize list boilerplate', function (): void {
    expect(ScheduleFramingNarrativeSupport::sanitizeModelFraming(
        'Here is the order below for your day.'
    ))->toBe('');

    expect(ScheduleFramingNarrativeSupport::sanitizeModelFraming(
        'Take it one step at a time using the ranked list.'
    ))->toBe('');
});

test('sanitizeModelFraming clears visibility overclaims', function (): void {
    expect(ScheduleFramingNarrativeSupport::sanitizeModelFraming(
        'I have reviewed your tasks and here is the plan.'
    ))->toBe('');
});

test('single-block fallback uses schedule voice and avoids prioritize boilerplate', function (): void {
    $blocks = [
        ['start_time' => '09:00', 'end_time' => '10:00', 'label' => 'Math homework'],
    ];
    $promptData = [
        'user_context' => [
            'schedule_intent_flags' => [
                'has_morning' => true,
            ],
        ],
    ];

    $framing = ScheduleFramingNarrativeSupport::buildFallback(
        $blocks,
        $promptData,
        1,
        'put math first',
        'unit|single'
    );

    $lower = mb_strtolower($framing);
    expect($lower)->not->toContain('order below');
    expect($lower)->not->toContain('ranked list');
    expect($lower)->not->toContain('numbered list');
    expect($lower)->not->toContain('one step at a time');
    expect(
        str_contains($lower, 'math homework')
        || str_contains($lower, 'block')
        || str_contains($lower, 'blocked')
        || str_contains($lower, 'slot')
    )->toBeTrue();
});

test('multi-block fallback varies with seed and mentions blocks or rows', function (): void {
    $blocks = [
        ['start_time' => '09:00', 'end_time' => '10:00', 'label' => 'A'],
        ['start_time' => '10:15', 'end_time' => '11:00', 'label' => 'B'],
    ];
    $promptData = [
        'user_context' => [
            'schedule_intent_flags' => [
                'has_afternoon' => true,
            ],
        ],
    ];

    $a = ScheduleFramingNarrativeSupport::buildFallback($blocks, $promptData, 2, '', 'm0');
    $b = ScheduleFramingNarrativeSupport::buildFallback($blocks, $promptData, 2, '', 'm1');
    expect($a)->not->toBe($b);

    $lower = mb_strtolower($a);
    expect($lower)->not->toContain('order below');
    expect(
        str_contains($lower, 'block')
        || str_contains($lower, 'row')
        || str_contains($lower, 'line')
    )->toBeTrue();
});

<?php

use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Services\Llm\StructuredOutputSanitizer;
use Carbon\CarbonImmutable;

it('builds listed_items from context for upcoming week queries', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-04 12:00:00', config('app.timezone')));

    /** @var StructuredOutputSanitizer $sanitizer */
    $sanitizer = app(StructuredOutputSanitizer::class);

    $context = [
        'tasks' => [
            [
                'id' => 1,
                'title' => 'Due soon',
                'priority' => 'high',
                'start_datetime' => null,
                'end_datetime' => '2026-03-08T07:19:39+08:00',
            ],
            [
                'id' => 2,
                'title' => 'Due later',
                'priority' => 'low',
                'start_datetime' => null,
                'end_datetime' => '2026-03-19T11:33:13+08:00',
            ],
            [
                'id' => 3,
                'title' => 'No due date',
                'priority' => 'medium',
                'start_datetime' => null,
                'end_datetime' => null,
            ],
        ],
    ];

    $structured = [
        // Even if the model returns something else, for filter queries we rebuild from context.
        'listed_items' => [
            ['title' => 'Due later'],
            ['title' => 'No due date'],
        ],
        'recommended_action' => 'stub',
        'reasoning' => 'stub',
    ];

    $out = $sanitizer->sanitize($structured, $context, LlmIntent::ListFilterSearch, LlmEntityType::Task, 'how many tasks for the upcoming week?');

    expect($out)->toHaveKey('listed_items')
        ->and($out['listed_items'])->toHaveCount(1)
        ->and($out['listed_items'][0]['title'])->toBe('Due soon');
});

it('uses start datetime fallback for events in next 7 days queries', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-04 12:00:00', config('app.timezone')));

    /** @var StructuredOutputSanitizer $sanitizer */
    $sanitizer = app(StructuredOutputSanitizer::class);

    $context = [
        'events' => [
            [
                'id' => 1,
                'title' => 'CS group project meetup',
                'start_datetime' => '2026-03-10T10:00:00+08:00',
                'end_datetime' => null,
            ],
            [
                'id' => 2,
                'title' => 'Late event',
                'start_datetime' => '2026-03-13T10:00:00+08:00',
                'end_datetime' => null,
            ],
        ],
    ];

    $structured = [
        'listed_items' => [
            ['title' => 'Late event'],
        ],
        'recommended_action' => 'stub',
        'reasoning' => 'stub',
    ];

    $out = $sanitizer->sanitize(
        $structured,
        $context,
        LlmIntent::ListFilterSearch,
        LlmEntityType::Event,
        'Filter to events only and show what is coming up in the next 7 days.'
    );

    expect($out['listed_items'])->toHaveCount(1)
        ->and($out['listed_items'][0]['title'])->toBe('CS group project meetup');
});

it('uses this-week wording when prompt explicitly says this week', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-04 12:00:00', config('app.timezone')));

    /** @var StructuredOutputSanitizer $sanitizer */
    $sanitizer = app(StructuredOutputSanitizer::class);

    $context = [
        'tasks' => [
            [
                'id' => 1,
                'title' => 'MATH 201 – Quiz 3: Graph Theory',
                'priority' => 'high',
                'start_datetime' => '2026-03-06T09:30:00+08:00',
                'end_datetime' => '2026-03-06T10:00:00+08:00',
            ],
        ],
    ];

    $structured = [
        'listed_items' => [],
        'recommended_action' => 'stub',
        'reasoning' => 'stub',
    ];

    $out = $sanitizer->sanitize(
        $structured,
        $context,
        LlmIntent::ListFilterSearch,
        LlmEntityType::Task,
        'Show only my exam-related tasks and events for this week.'
    );

    expect($out['reasoning'])->toContain('due this week');
});

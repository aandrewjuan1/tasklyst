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

    $out = $sanitizer->sanitize($structured, $context, LlmIntent::GeneralQuery, LlmEntityType::Task, 'how many tasks for the upcoming week?');

    expect($out)->toHaveKey('listed_items')
        ->and($out['listed_items'])->toHaveCount(1)
        ->and($out['listed_items'][0]['title'])->toBe('Due soon');
});

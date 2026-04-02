<?php

use App\Services\LLM\Prioritization\TaskAssistantTaskChoiceConstraintsExtractor;
use App\Services\LLM\Prioritization\TaskPrioritizationService;
use App\Services\LLM\Scheduling\SchedulingIntentInterpreter;
use App\Services\LLM\Scheduling\TaskAssistantScheduleContextBuilder;
use App\Services\LLM\Scheduling\TaskAssistantScheduleDbContextBuilder;
use App\Services\LLM\Scheduling\TaskAssistantScheduleHorizonResolver;
use App\Services\LLM\Scheduling\TaskAssistantStructuredFlowGenerator;
use App\Services\LLM\TaskAssistant\TaskAssistantHybridNarrativeService;
use App\Services\LLM\TaskAssistant\TaskAssistantPromptData;

test('scheduler enforces monotonic placement order to avoid priority inversion', function (): void {
    $scheduleContextBuilder = new TaskAssistantScheduleContextBuilder(
        constraintsExtractor: new TaskAssistantTaskChoiceConstraintsExtractor,
        horizonResolver: new TaskAssistantScheduleHorizonResolver,
        intentInterpreter: new SchedulingIntentInterpreter,
    );

    $gen = new TaskAssistantStructuredFlowGenerator(
        promptData: new TaskAssistantPromptData,
        dbContextBuilder: new TaskAssistantScheduleDbContextBuilder($scheduleContextBuilder),
        scheduleContextBuilder: $scheduleContextBuilder,
        prioritizationService: new TaskPrioritizationService,
        hybridNarrative: new TaskAssistantHybridNarrativeService,
    );

    $method = new ReflectionMethod(TaskAssistantStructuredFlowGenerator::class, 'generateProposalsChunkedSpillCore');
    $method->setAccessible(true);

    $snapshot = [
        'timezone' => 'UTC',
        'today' => '2026-04-02',
        'time_window' => ['start' => '14:00', 'end' => '22:00'],
        'schedule_horizon' => [
            'mode' => 'single_day',
            'start_date' => '2026-04-02',
            'end_date' => '2026-04-02',
            'label' => 'default_today',
        ],
        'events_for_busy' => [
            [
                'starts_at' => '2026-04-02T14:25:00+00:00',
                'ends_at' => '2026-04-02T16:00:00+00:00',
            ],
        ],
        'schedule_target_skips' => [],
        'tasks' => [],
        'events' => [],
        'projects' => [],
    ];

    $context = [
        '_refinement_skip_morning_shortcut' => true,
        '_refinement_disable_partial_fit' => true,
    ];

    $unitsOverride = [
        [
            'entity_type' => 'task',
            'entity_id' => 1,
            'title' => 'Impossible 5h study block before quiz',
            'minutes' => 240,
            'score' => 1000,
            'candidate_order' => 0,
            'priority_rank' => 1,
        ],
        [
            'entity_type' => 'task',
            'entity_id' => 2,
            'title' => 'ITEL 210 – Online Quiz',
            'minutes' => 25,
            'score' => 10,
            'candidate_order' => 1,
            'priority_rank' => 2,
        ],
    ];

    $result = $method->invokeArgs($gen, [$snapshot, $context, 2, $unitsOverride]);
    [$proposals, $digest] = is_array($result) ? $result : [[], []];

    expect(count($proposals))->toBe(2);

    $p1Start = new DateTimeImmutable((string) ($proposals[0]['start_datetime'] ?? ''));
    $p2Start = new DateTimeImmutable((string) ($proposals[1]['start_datetime'] ?? ''));

    expect($p1Start->format('H:i'))->toBe('16:00');
    // Study block keeps a reserved gap; next unit can't be scheduled into the earlier 14:00 window.
    expect($p2Start->format('H:i'))->toBe('20:30');
});

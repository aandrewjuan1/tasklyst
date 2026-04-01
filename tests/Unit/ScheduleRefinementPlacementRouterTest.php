<?php

use App\Services\LLM\Scheduling\ScheduleEditLexicon;
use App\Services\LLM\Scheduling\ScheduleEditTargetResolver;
use App\Services\LLM\Scheduling\ScheduleEditTemporalParser;
use App\Services\LLM\Scheduling\ScheduleEditUnderstandingPipeline;
use App\Services\LLM\Scheduling\ScheduleRefinementPlacementRouter;
use App\Services\LLM\Scheduling\SchedulingIntentInterpreter;

it('does not use spill for minute shift phrasing', function (): void {
    $router = new ScheduleRefinementPlacementRouter(
        new SchedulingIntentInterpreter,
        new ScheduleEditTemporalParser,
        new ScheduleEditUnderstandingPipeline(
            new ScheduleEditLexicon,
            new ScheduleEditTargetResolver(new ScheduleEditLexicon),
            new ScheduleEditTemporalParser,
        ),
    );

    $proposals = [['proposal_uuid' => 'a', 'title' => 'Task A']];

    expect($router->shouldUseSpillForRefinement('move first 15 minutes later', $proposals, 0, 'UTC'))->toBeFalse();
});

it('does not use spill when user gives explicit clock time', function (): void {
    $router = new ScheduleRefinementPlacementRouter(
        new SchedulingIntentInterpreter,
        new ScheduleEditTemporalParser,
        new ScheduleEditUnderstandingPipeline(
            new ScheduleEditLexicon,
            new ScheduleEditTargetResolver(new ScheduleEditLexicon),
            new ScheduleEditTemporalParser,
        ),
    );

    $proposals = [
        ['proposal_uuid' => 'a', 'title' => 'Task A'],
    ];

    expect($router->shouldUseSpillForRefinement('move first to 8 pm', $proposals, 0, 'UTC'))->toBeFalse();
});

it('uses spill for vague temporal prompts without clock time', function (): void {
    $router = new ScheduleRefinementPlacementRouter(
        new SchedulingIntentInterpreter,
        new ScheduleEditTemporalParser,
        new ScheduleEditUnderstandingPipeline(
            new ScheduleEditLexicon,
            new ScheduleEditTargetResolver(new ScheduleEditLexicon),
            new ScheduleEditTemporalParser,
        ),
    );

    $proposals = [
        ['proposal_uuid' => 'a', 'title' => 'Task A'],
    ];

    expect($router->shouldUseSpillForRefinement('move the third tomorrow evening', $proposals, 2, 'UTC'))->toBeTrue();
    expect($router->shouldUseSpillForRefinement('move first later afternoon', $proposals, 0, 'UTC'))->toBeTrue();
});

it('does not use spill when target index is null', function (): void {
    $router = new ScheduleRefinementPlacementRouter(
        new SchedulingIntentInterpreter,
        new ScheduleEditTemporalParser,
        new ScheduleEditUnderstandingPipeline(
            new ScheduleEditLexicon,
            new ScheduleEditTargetResolver(new ScheduleEditLexicon),
            new ScheduleEditTemporalParser,
        ),
    );

    expect($router->shouldUseSpillForRefinement('move it tomorrow evening', [], null, 'UTC'))->toBeFalse();
});

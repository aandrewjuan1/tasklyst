<?php

use App\Services\LLM\Scheduling\ScheduleEditLexicon;
use App\Services\LLM\Scheduling\ScheduleEditTargetResolver;
use App\Services\LLM\Scheduling\ScheduleEditTemporalParser;
use App\Services\LLM\Scheduling\ScheduleEditUnderstandingPipeline;
use App\Services\LLM\Scheduling\ScheduleRefinementClauseSplitter;

it('maps part-of-day phrases to concrete set_local_time_hhmm for ordinal targets', function (string $message, string $expectedHhmm, int $expectedIndex): void {
    $pipeline = new ScheduleEditUnderstandingPipeline(
        new ScheduleEditLexicon,
        new ScheduleEditTargetResolver(new ScheduleEditLexicon),
        new ScheduleEditTemporalParser,
    );

    $proposals = [
        ['proposal_uuid' => 'a', 'title' => 'Impossible 5h study block before quiz'],
        ['proposal_uuid' => 'b', 'title' => 'Task B'],
        ['proposal_uuid' => 'c', 'title' => 'Task C'],
    ];

    $result = $pipeline->resolve($message, $proposals, 'UTC');

    expect($result['clarification_required'])->toBeFalse();
    $timeOps = array_values(array_filter(
        $result['operations'],
        static fn (mixed $op): bool => is_array($op) && (($op['op'] ?? '') === 'set_local_time_hhmm')
    ));
    expect($timeOps)->toHaveCount(1);
    expect($timeOps[0]['local_time_hhmm'])->toBe($expectedHhmm);
    expect($timeOps[0]['proposal_index'])->toBe($expectedIndex);
})->with([
    'third to evening' => ['put the third task on evening', '18:00', 2],
    'move third evening' => ['move third at evening', '18:00', 2],
    'first or title afternoon' => ['move the first one or impossible study block at afternoon', '15:00', 0],
]);

it('prefers explicit clock time over part of day when both could apply', function (): void {
    $pipeline = new ScheduleEditUnderstandingPipeline(
        new ScheduleEditLexicon,
        new ScheduleEditTargetResolver(new ScheduleEditLexicon),
        new ScheduleEditTemporalParser,
    );

    $proposals = [
        ['proposal_uuid' => 'a', 'title' => 'A'],
        ['proposal_uuid' => 'b', 'title' => 'B'],
    ];

    $result = $pipeline->resolve('move second to 8 pm evening', $proposals, 'UTC');

    expect($result['clarification_required'])->toBeFalse();
    $timeOps = array_values(array_filter(
        $result['operations'],
        static fn (mixed $op): bool => is_array($op) && (($op['op'] ?? '') === 'set_local_time_hhmm')
    ));
    expect($timeOps)->toHaveCount(1);
    expect($timeOps[0]['local_time_hhmm'])->toBe('20:00');
    expect($timeOps[0]['proposal_index'])->toBe(1);
});

it('parses common shorthand and typos for day and daypart', function (): void {
    $pipeline = new ScheduleEditUnderstandingPipeline(
        new ScheduleEditLexicon,
        new ScheduleEditTargetResolver(new ScheduleEditLexicon),
        new ScheduleEditTemporalParser,
    );

    $proposals = [
        ['proposal_uuid' => 'a', 'title' => 'Task A'],
        ['proposal_uuid' => 'b', 'title' => 'Task B'],
    ];

    $result = $pipeline->resolve('drag 2nd to evning tmrw', $proposals, 'UTC');

    expect($result['clarification_required'])->toBeFalse();

    $timeOp = collect($result['operations'])->first(
        fn (mixed $op): bool => is_array($op) && (($op['op'] ?? '') === 'set_local_time_hhmm')
    );
    expect($timeOp)->toBeArray();
    expect($timeOp['proposal_index'] ?? null)->toBe(1);
    expect($timeOp['local_time_hhmm'] ?? null)->toBe('18:00');

    $dateOp = collect($result['operations'])->first(
        fn (mixed $op): bool => is_array($op) && (($op['op'] ?? '') === 'set_local_date_ymd')
    );
    expect($dateOp)->toBeArray();
});

it('accumulates per-clause time edits for multi-part then-delimited messages', function (): void {
    $pipeline = new ScheduleEditUnderstandingPipeline(
        new ScheduleEditLexicon,
        new ScheduleEditTargetResolver(new ScheduleEditLexicon),
        new ScheduleEditTemporalParser,
    );
    $splitter = new ScheduleRefinementClauseSplitter;

    $proposals = [
        ['proposal_uuid' => 'a', 'title' => 'Task A'],
        ['proposal_uuid' => 'b', 'title' => 'Task B'],
    ];

    $segments = $splitter->split('move second to 8pm then move the first one at 8 am');
    expect($segments)->toHaveCount(2);

    $timeOps = [];
    foreach ($segments as $segment) {
        $result = $pipeline->resolve($segment, $proposals, 'UTC');
        expect($result['clarification_required'])->toBeFalse();
        foreach ($result['operations'] as $op) {
            if (is_array($op) && ($op['op'] ?? '') === 'set_local_time_hhmm') {
                $timeOps[] = $op;
            }
        }
    }

    expect($timeOps)->toHaveCount(2);
    expect($timeOps[0]['proposal_index'])->toBe(1);
    expect($timeOps[0]['local_time_hhmm'])->toBe('20:00');
    expect($timeOps[1]['proposal_index'])->toBe(0);
    expect($timeOps[1]['local_time_hhmm'])->toBe('08:00');
});

it('enrichOperationsWithProposalUuids fills missing proposal_uuid from index', function (): void {
    $pipeline = new ScheduleEditUnderstandingPipeline(
        new ScheduleEditLexicon,
        new ScheduleEditTargetResolver(new ScheduleEditLexicon),
        new ScheduleEditTemporalParser,
    );

    $proposals = [
        ['proposal_uuid' => 'row-a', 'title' => 'A'],
        ['proposal_uuid' => 'row-b', 'title' => 'B'],
    ];

    $enriched = $pipeline->enrichOperationsWithProposalUuids([
        ['op' => 'set_local_time_hhmm', 'proposal_index' => 1, 'local_time_hhmm' => '15:00'],
    ], $proposals);

    expect($enriched[0]['proposal_uuid'] ?? null)->toBe('row-b');
});

it('returns clarification context with parsed time and date signals', function (): void {
    $pipeline = new ScheduleEditUnderstandingPipeline(
        new ScheduleEditLexicon,
        new ScheduleEditTargetResolver(new ScheduleEditLexicon),
        new ScheduleEditTemporalParser,
    );

    $proposals = [
        ['proposal_uuid' => 'a', 'title' => 'Task A'],
        ['proposal_uuid' => 'b', 'title' => 'Task B'],
    ];

    $result = $pipeline->resolve('move this to 8 pm tomorrow', $proposals, 'Asia/Manila');

    expect($result['clarification_required'])->toBeTrue();
    expect($result['clarification_context'] ?? null)->toBeArray();
    expect($result['clarification_context']['parsed_time_hhmm'] ?? null)->toBe('20:00');
    expect($result['clarification_context']['parsed_date_ymd'] ?? null)->not->toBeNull();
    expect($result['clarification_context']['target_summary'] ?? null)->toBe('unresolved target');
});

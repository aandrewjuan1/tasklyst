<?php

use App\Services\LLM\Scheduling\ScheduleEditLexicon;
use App\Services\LLM\Scheduling\ScheduleEditTargetResolver;
use App\Services\LLM\Scheduling\ScheduleEditTemporalParser;
use App\Services\LLM\Scheduling\ScheduleEditUnderstandingPipeline;

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

<?php

use App\Services\LLM\Scheduling\ScheduleEditLexicon;
use App\Services\LLM\Scheduling\ScheduleEditTargetResolver;

it('returns low confidence ambiguity for pronoun-only refinements', function (): void {
    $resolver = new ScheduleEditTargetResolver(new ScheduleEditLexicon);

    $result = $resolver->resolvePrimaryTarget('move it to 8 pm', [
        ['proposal_id' => 'a', 'proposal_uuid' => 'uuid-a', 'title' => 'Write report'],
        ['proposal_id' => 'b', 'proposal_uuid' => 'uuid-b', 'title' => 'Review backlog'],
    ]);

    expect($result['ambiguous'])->toBeTrue();
    expect($result['confidence'])->toBe('low');
    expect($result['candidate_titles'])->toHaveCount(2);
});

it('resolves pronoun when last referenced proposal uuid matches one draft row', function (): void {
    $resolver = new ScheduleEditTargetResolver(new ScheduleEditLexicon);

    $result = $resolver->resolvePrimaryTarget(
        'move it to evening instead',
        [
            ['proposal_uuid' => 'keep-a', 'title' => 'Write report'],
            ['proposal_uuid' => 'keep-b', 'title' => 'Review backlog'],
        ],
        ['keep-b'],
    );

    expect($result['ambiguous'])->toBeFalse();
    expect($result['index'])->toBe(1);
    expect($result['confidence'])->toBe('high');
});

it('resolves pronoun for single scheduled item without last referenced uuids', function (): void {
    $resolver = new ScheduleEditTargetResolver(new ScheduleEditLexicon);

    $result = $resolver->resolvePrimaryTarget('move it to 8 pm', [
        ['proposal_uuid' => 'uuid-a', 'title' => 'Write report'],
    ]);

    expect($result['ambiguous'])->toBeFalse();
    expect($result['index'])->toBe(0);
    expect($result['confidence'])->toBe('high');
});

it('picks leftmost positional cue when message contains multiple ordinals', function (): void {
    $resolver = new ScheduleEditTargetResolver(new ScheduleEditLexicon);

    $result = $resolver->resolvePrimaryTarget(
        'move second to 8pm, then move the first one at 8 am',
        [
            ['proposal_uuid' => 'a', 'title' => 'Task A'],
            ['proposal_uuid' => 'b', 'title' => 'Task B'],
        ],
    );

    expect($result['ambiguous'])->toBeFalse();
    expect($result['index'])->toBe(1);
    expect($result['proposal_uuid'])->toBe('b');
});

it('item number beats later ordinal by position when item number is leftmost', function (): void {
    $resolver = new ScheduleEditTargetResolver(new ScheduleEditLexicon);

    $result = $resolver->resolvePrimaryTarget('move item #2 to 3pm and mention first task later', [
        ['proposal_uuid' => 'a', 'title' => 'Task A'],
        ['proposal_uuid' => 'b', 'title' => 'Task B'],
    ]);

    expect($result['ambiguous'])->toBeFalse();
    expect($result['index'])->toBe(1);
    expect($result['confidence'])->toBe('high');
});

it('resolves top N as 1-based row index', function (): void {
    $resolver = new ScheduleEditTargetResolver(new ScheduleEditLexicon);

    $result = $resolver->resolvePrimaryTarget('set the top 1 at 3 pm, second at evening', [
        ['proposal_uuid' => 'a', 'title' => 'Task A'],
        ['proposal_uuid' => 'b', 'title' => 'Task B'],
    ]);

    expect($result['ambiguous'])->toBeFalse();
    expect($result['index'])->toBe(0);
    expect($result['proposal_uuid'])->toBe('a');
    expect($result['confidence'])->toBe('high');
});

it('resolves ranked and line N phrases', function (): void {
    $resolver = new ScheduleEditTargetResolver(new ScheduleEditLexicon);

    $ranked = $resolver->resolvePrimaryTarget('ranked #2 to afternoon', [
        ['proposal_uuid' => 'a', 'title' => 'Task A'],
        ['proposal_uuid' => 'b', 'title' => 'Task B'],
    ]);
    expect($ranked['index'])->toBe(1);
    expect($ranked['confidence'])->toBe('high');

    $line = $resolver->resolvePrimaryTarget('move line 1 earlier', [
        ['proposal_uuid' => 'a', 'title' => 'Task A'],
        ['proposal_uuid' => 'b', 'title' => 'Task B'],
    ]);
    expect($line['index'])->toBe(0);
});

it('resolves move top 1 to last using leftmost target cue', function (): void {
    $resolver = new ScheduleEditTargetResolver(new ScheduleEditLexicon);

    $result = $resolver->resolvePrimaryTarget('move top 1 to last', [
        ['proposal_uuid' => 'a', 'title' => 'Task A'],
        ['proposal_uuid' => 'b', 'title' => 'Task B'],
        ['proposal_uuid' => 'c', 'title' => 'Task C'],
    ]);

    expect($result['ambiguous'])->toBeFalse();
    expect($result['index'])->toBe(0);
});

it('resolves bare list index after comma or start', function (): void {
    $resolver = new ScheduleEditTargetResolver(new ScheduleEditLexicon);

    $lead = $resolver->resolvePrimaryTarget('#1 at 9am', [
        ['proposal_uuid' => 'a', 'title' => 'Task A'],
        ['proposal_uuid' => 'b', 'title' => 'Task B'],
    ]);
    expect($lead['index'])->toBe(0);

    $afterComma = $resolver->resolvePrimaryTarget('keep that, #2 to 4pm', [
        ['proposal_uuid' => 'a', 'title' => 'Task A'],
        ['proposal_uuid' => 'b', 'title' => 'Task B'],
    ]);
    expect($afterComma['index'])->toBe(1);
});

it('resolves top plus word ordinals', function (): void {
    $resolver = new ScheduleEditTargetResolver(new ScheduleEditLexicon);

    $result = $resolver->resolvePrimaryTarget('set top second to evening', [
        ['proposal_uuid' => 'a', 'title' => 'Task A'],
        ['proposal_uuid' => 'b', 'title' => 'Task B'],
    ]);

    expect($result['index'])->toBe(1);
    expect($result['confidence'])->toBe('high');
});

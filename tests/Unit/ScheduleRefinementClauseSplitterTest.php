<?php

use App\Services\LLM\Scheduling\ScheduleRefinementClauseSplitter;

it('returns single segment when no delimiter', function (): void {
    $s = new ScheduleRefinementClauseSplitter;
    expect($s->split('move second to 8 pm'))->toBe(['move second to 8 pm']);
});

it('splits on then', function (): void {
    $s = new ScheduleRefinementClauseSplitter;
    expect($s->split('move second to 8pm then move the first one at 8 am'))->toBe([
        'move second to 8pm',
        'move the first one at 8 am',
    ]);
});

it('splits on and then', function (): void {
    $s = new ScheduleRefinementClauseSplitter;
    expect($s->split('move first to morning and then move second to afternoon'))->toBe([
        'move first to morning',
        'move second to afternoon',
    ]);
});

it('splits on semicolon', function (): void {
    $s = new ScheduleRefinementClauseSplitter;
    expect($s->split('move first to 9 am; move second to 3 pm'))->toBe([
        'move first to 9 am',
        'move second to 3 pm',
    ]);
});

it('splits comma only when followed by edit verb', function (): void {
    $s = new ScheduleRefinementClauseSplitter;
    expect($s->split('move first to 8 am, move second to 8 pm'))->toBe([
        'move first to 8 am',
        'move second to 8 pm',
    ]);
});

it('does not split comma before non-edit continuation', function (): void {
    $s = new ScheduleRefinementClauseSplitter;
    $one = 'move first to morning, i prefer morning';
    expect($s->split($one))->toBe([$one]);
});

it('splits on next when followed by edit verb', function (): void {
    $s = new ScheduleRefinementClauseSplitter;
    expect($s->split('move first to 9 am next move second to 2 pm'))->toBe([
        'move first to 9 am',
        'move second to 2 pm',
    ]);
});

it('normalizes empty to empty list', function (): void {
    $s = new ScheduleRefinementClauseSplitter;
    expect($s->split('   '))->toBe([]);
});

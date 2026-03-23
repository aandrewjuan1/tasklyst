<?php

use App\Services\LLM\Scheduling\TaskAssistantScheduleHorizonResolver;
use Carbon\CarbonImmutable;

test('defaults to snapshot day when no date phrase matches', function (): void {
    $tz = 'UTC';
    $now = CarbonImmutable::parse('2025-06-10 12:00:00', $tz);
    $resolver = new TaskAssistantScheduleHorizonResolver;

    $r = $resolver->resolve('schedule my tasks please', $tz, $now);

    expect($r['mode'])->toBe('single_day');
    expect($r['start_date'])->toBe('2025-06-10');
    expect($r['end_date'])->toBe('2025-06-10');
    expect($r['label'])->toBe('default_today');
});

test('tomorrow shifts anchor by one day', function (): void {
    $tz = 'UTC';
    $now = CarbonImmutable::parse('2025-06-10 12:00:00', $tz);
    $resolver = new TaskAssistantScheduleHorizonResolver;

    $r = $resolver->resolve('Put that on tomorrow afternoon', $tz, $now);

    expect($r['start_date'])->toBe('2025-06-11');
    expect($r['end_date'])->toBe('2025-06-11');
    expect($r['label'])->toBe('tomorrow');
});

test('this week returns monday to sunday range', function (): void {
    $tz = 'UTC';
    $now = CarbonImmutable::parse('2025-06-11 12:00:00', $tz);
    $resolver = new TaskAssistantScheduleHorizonResolver;

    $r = $resolver->resolve('find time this week', $tz, $now);

    expect($r['mode'])->toBe('range');
    expect($r['start_date'])->toBe('2025-06-09');
    expect($r['end_date'])->toBe('2025-06-15');
});

test('next weekend is saturday sunday after upcoming weekend', function (): void {
    $tz = 'UTC';
    $now = CarbonImmutable::parse('2025-06-11 12:00:00', $tz);
    $resolver = new TaskAssistantScheduleHorizonResolver;

    $r = $resolver->resolve('schedule next weekend', $tz, $now);

    expect($r['mode'])->toBe('range');
    expect($r['start_date'])->toBe('2025-06-21');
    expect($r['end_date'])->toBe('2025-06-22');
});

test('this week range is capped by max_horizon_days', function (): void {
    config(['task-assistant.schedule.max_horizon_days' => 3]);

    $tz = 'UTC';
    $now = CarbonImmutable::parse('2025-06-11 12:00:00', $tz);
    $resolver = new TaskAssistantScheduleHorizonResolver;

    $r = $resolver->resolve('find time this week', $tz, $now);

    expect($r['mode'])->toBe('range');
    expect($r['start_date'])->toBe('2025-06-09');
    expect($r['end_date'])->toBe('2025-06-11');
});

test('friday resolves to next friday from snapshot day', function (): void {
    $tz = 'UTC';
    $now = CarbonImmutable::parse('2025-06-10 12:00:00', $tz);
    $resolver = new TaskAssistantScheduleHorizonResolver;

    $r = $resolver->resolve('schedule essay for Friday', $tz, $now);

    expect($r['mode'])->toBe('single_day');
    expect($r['start_date'])->toBe('2025-06-13');
});

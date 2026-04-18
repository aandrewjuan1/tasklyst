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

test('this week starts today and extends to week end', function (): void {
    $tz = 'UTC';
    $now = CarbonImmutable::parse('2025-06-11 12:00:00', $tz);
    $resolver = new TaskAssistantScheduleHorizonResolver;

    $r = $resolver->resolve('find time this week', $tz, $now);

    expect($r['mode'])->toBe('range');
    expect($r['start_date'])->toBe('2025-06-11');
    expect($r['end_date'])->toBe('2025-06-17');
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
    expect($r['start_date'])->toBe('2025-06-11');
    expect($r['end_date'])->toBe('2025-06-13');
});

test('friday resolves to next friday from snapshot day', function (): void {
    $tz = 'UTC';
    $now = CarbonImmutable::parse('2025-06-10 12:00:00', $tz);
    $resolver = new TaskAssistantScheduleHorizonResolver;

    $r = $resolver->resolve('schedule essay for Friday', $tz, $now);

    expect($r['mode'])->toBe('single_day');
    expect($r['start_date'])->toBe('2025-06-13');
});

test('explicit month day resolves to strict single day', function (): void {
    $tz = 'UTC';
    $now = CarbonImmutable::parse('2026-04-18 12:00:00', $tz);
    $resolver = new TaskAssistantScheduleHorizonResolver;

    $r = $resolver->resolve('actually schedule them for april 20', $tz, $now);

    expect($r['mode'])->toBe('single_day');
    expect($r['start_date'])->toBe('2026-04-20');
    expect($r['end_date'])->toBe('2026-04-20');
    expect($r['label'])->toBe('explicit_date_month_day');
});

test('iso date resolves to strict single day', function (): void {
    $tz = 'UTC';
    $now = CarbonImmutable::parse('2026-04-18 12:00:00', $tz);
    $resolver = new TaskAssistantScheduleHorizonResolver;

    $r = $resolver->resolve('schedule these on 2026-04-20', $tz, $now);

    expect($r['mode'])->toBe('single_day');
    expect($r['start_date'])->toBe('2026-04-20');
    expect($r['end_date'])->toBe('2026-04-20');
    expect($r['label'])->toBe('explicit_date_iso');
});

test('relative day offset resolves to single day', function (): void {
    $tz = 'UTC';
    $now = CarbonImmutable::parse('2026-04-18 12:00:00', $tz);
    $resolver = new TaskAssistantScheduleHorizonResolver;

    $r = $resolver->resolve('schedule them in 2 days', $tz, $now);

    expect($r['mode'])->toBe('single_day');
    expect($r['start_date'])->toBe('2026-04-20');
    expect($r['end_date'])->toBe('2026-04-20');
    expect($r['label'])->toBe('relative_days_offset');
});

test('qualified weekday resolves to single day label', function (): void {
    $tz = 'UTC';
    $now = CarbonImmutable::parse('2026-04-18 12:00:00', $tz);
    $resolver = new TaskAssistantScheduleHorizonResolver;

    $r = $resolver->resolve('schedule these next monday', $tz, $now);

    expect($r['mode'])->toBe('single_day');
    expect($r['start_date'])->toBe('2026-04-20');
    expect($r['label'])->toBe('qualified_weekday_next');
});

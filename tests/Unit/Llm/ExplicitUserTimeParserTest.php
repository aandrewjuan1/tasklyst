<?php

use App\Services\Llm\ExplicitUserTimeParser;
use Carbon\CarbonImmutable;

test('parses weekday time as upcoming weekday in app timezone', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 10:00:00', 'Asia/Manila')); // Tuesday

    $parser = new ExplicitUserTimeParser;
    $context = [
        'current_time' => '2026-03-10T10:00:00+08:00',
        'timezone' => 'Asia/Manila',
    ];

    $dt = $parser->parseStartDatetime('Reschedule my birthday celebration to Friday at 9am.', $context);

    expect($dt)->not->toBeNull()
        ->and($dt?->toIso8601String())->toBe('2026-03-13T09:00:00+08:00');
});

test('parses tomorrow time relative to context current_time', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 10:00:00', 'Asia/Manila'));

    $parser = new ExplicitUserTimeParser;
    $context = [
        'current_time' => '2026-03-10T10:00:00+08:00',
        'timezone' => 'Asia/Manila',
    ];

    $dt = $parser->parseStartDatetime('Schedule it tomorrow at 9am.', $context);

    expect($dt)->not->toBeNull()
        ->and($dt?->toIso8601String())->toBe('2026-03-11T09:00:00+08:00');
});

test('returns null when message has no explicit time', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 10:00:00', 'Asia/Manila'));

    $parser = new ExplicitUserTimeParser;
    $context = [
        'current_time' => '2026-03-10T10:00:00+08:00',
        'timezone' => 'Asia/Manila',
    ];

    $dt = $parser->parseStartDatetime('Reschedule to Friday.', $context);

    expect($dt)->toBeNull();
});

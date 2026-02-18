<?php

use App\Actions\Pomodoro\SuggestPomodoroSettingsForDurationAction;

beforeEach(function (): void {
    $this->action = app(SuggestPomodoroSettingsForDurationAction::class);
});

test('returns null when task duration is zero', function (): void {
    $result = $this->action->execute(0);

    expect($result)->toBeNull();
});

test('returns null when task duration is negative', function (): void {
    $result = $this->action->execute(-1);

    expect($result)->toBeNull();
});

test('suggests single short block when task duration is less than default work', function (): void {
    $result = $this->action->execute(15);

    expect($result)->not->toBeNull()
        ->and($result['work_duration_minutes'])->toBe(15)
        ->and($result['suggested_pomodoro_count'])->toBe(1)
        ->and($result['long_break_after_pomodoros'])->toBeGreaterThanOrEqual(2);
});

test('suggests two pomodoros for one hour task', function (): void {
    $result = $this->action->execute(60);

    expect($result)->not->toBeNull()
        ->and($result['work_duration_minutes'])->toBe(config('pomodoro.defaults.work_duration_minutes', 25))
        ->and($result['short_break_minutes'])->toBe(config('pomodoro.defaults.short_break_minutes', 5))
        ->and($result['long_break_minutes'])->toBe(config('pomodoro.defaults.long_break_minutes', 15))
        ->and($result['suggested_pomodoro_count'])->toBe(2)
        ->and($result['long_break_after_pomodoros'])->toBe(2);
});

test('suggests four pomodoros for two hour task', function (): void {
    $result = $this->action->execute(120);

    expect($result)->not->toBeNull()
        ->and($result['work_duration_minutes'])->toBe(25)
        ->and($result['suggested_pomodoro_count'])->toBe(4)
        ->and($result['long_break_after_pomodoros'])->toBe(4);
});

test('suggests three pomodoros for ninety minute task', function (): void {
    $result = $this->action->execute(90);

    expect($result)->not->toBeNull()
        ->and($result['suggested_pomodoro_count'])->toBe(3)
        ->and($result['long_break_after_pomodoros'])->toBe(3);
});

test('returns all required keys for suggestion', function (): void {
    $result = $this->action->execute(60);

    expect($result)->toHaveKeys([
        'work_duration_minutes',
        'short_break_minutes',
        'long_break_minutes',
        'long_break_after_pomodoros',
        'suggested_pomodoro_count',
    ]);
});

test('caps long_break_after_pomodoros at config max', function (): void {
    $result = $this->action->execute(480);

    expect($result)->not->toBeNull()
        ->and($result['long_break_after_pomodoros'])->toBeLessThanOrEqual(10)
        ->and($result['suggested_pomodoro_count'])->toBeLessThanOrEqual(10);
});

<?php

use App\Models\PomodoroSetting;
use App\Support\Validation\PomodoroSettingsValidation;
use Illuminate\Support\Facades\Validator;

function validPomodoroSettingsPayload(): array
{
    return array_merge(PomodoroSetting::defaults(), [
        'work_duration_minutes' => 25,
        'short_break_minutes' => 5,
        'long_break_minutes' => 15,
        'long_break_after_pomodoros' => 4,
        'auto_start_break' => false,
        'auto_start_pomodoro' => false,
        'sound_enabled' => true,
        'sound_volume' => 80,
    ]);
}

test('valid pomodoro settings payload passes validation', function (): void {
    $validator = Validator::make(validPomodoroSettingsPayload(), PomodoroSettingsValidation::rules());

    expect($validator->passes())->toBeTrue();
});

test('pomodoro settings fail when work_duration_minutes is below min', function (): void {
    $payload = validPomodoroSettingsPayload();
    $payload['work_duration_minutes'] = 0;

    $validator = Validator::make($payload, PomodoroSettingsValidation::rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('work_duration_minutes'))->toBeTrue();
});

test('pomodoro settings fail when work_duration_minutes exceeds max', function (): void {
    $payload = validPomodoroSettingsPayload();
    $payload['work_duration_minutes'] = 121;

    $validator = Validator::make($payload, PomodoroSettingsValidation::rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('work_duration_minutes'))->toBeTrue();
});

test('pomodoro settings fail when short_break_minutes is out of range', function (): void {
    $payload = validPomodoroSettingsPayload();
    $payload['short_break_minutes'] = 61;

    $validator = Validator::make($payload, PomodoroSettingsValidation::rules());

    expect($validator->fails())->toBeTrue();
});

test('pomodoro settings fail when long_break_after_pomodoros is below 2', function (): void {
    $payload = validPomodoroSettingsPayload();
    $payload['long_break_after_pomodoros'] = 1;

    $validator = Validator::make($payload, PomodoroSettingsValidation::rules());

    expect($validator->fails())->toBeTrue();
});

test('pomodoro settings fail when sound_volume is out of range', function (): void {
    $payload = validPomodoroSettingsPayload();
    $payload['sound_volume'] = 101;

    $validator = Validator::make($payload, PomodoroSettingsValidation::rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('sound_volume'))->toBeTrue();
});

test('pomodoro settings fail when required field is missing', function (): void {
    $payload = validPomodoroSettingsPayload();
    unset($payload['work_duration_minutes']);

    $validator = Validator::make($payload, PomodoroSettingsValidation::rules());

    expect($validator->fails())->toBeTrue();
});

test('pomodoro settings fail when boolean field is not boolean', function (): void {
    $payload = validPomodoroSettingsPayload();
    $payload['auto_start_break'] = 'yes';

    $validator = Validator::make($payload, PomodoroSettingsValidation::rules());

    expect($validator->fails())->toBeTrue();
});

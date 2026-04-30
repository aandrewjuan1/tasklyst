<?php

use App\Enums\FocusModeType;
use App\Enums\FocusSessionType;
use App\Models\FocusSession;
use App\Models\User;
use App\Services\LLM\Scheduling\TaskAssistantScheduleDbContextBuilder;
use Carbon\CarbonImmutable;

it('uses user timezone when building scheduling snapshot day metadata', function (): void {
    CarbonImmutable::setTestNow('2026-04-10T17:30:00+00:00');

    $user = User::factory()->create([
        'timezone' => 'Asia/Manila',
    ]);

    $builder = app(TaskAssistantScheduleDbContextBuilder::class);
    $result = $builder->buildForUser($user, 'schedule my tasks');
    $snapshot = $result['snapshot'] ?? [];

    expect((string) ($snapshot['timezone'] ?? ''))->toBe('Asia/Manila');
    expect((string) ($snapshot['today'] ?? ''))->toBe('2026-04-11');
});

it('overrides schedule_preferences in the snapshot when focus signals are confident', function (): void {
    CarbonImmutable::setTestNow('2026-04-20T12:00:00+00:00');

    $user = User::factory()->create([
        'timezone' => 'UTC',
        'schedule_preferences' => [
            'schema_version' => 1,
            'energy_bias' => 'balanced',
            'day_bounds' => [
                'start' => '08:00',
                'end' => '22:00',
            ],
            'lunch_block' => [
                'enabled' => true,
                'start' => '12:00',
                'end' => '13:00',
            ],
        ],
    ]);

    $workDurationSeconds = 1800; // 30 minutes

    // 9 morning / 3 evening => energyBiasConf ~ 0.75 => confident override.
    for ($i = 0; $i < 9; $i++) {
        $start = CarbonImmutable::parse('2026-04-10 09:00:00+00:00')->addDays($i);
        FocusSession::factory()->completed()->create([
            'user_id' => $user->id,
            'type' => FocusSessionType::Work,
            'focus_mode_type' => FocusModeType::Pomodoro,
            'sequence_number' => 1,
            'duration_seconds' => $workDurationSeconds,
            'started_at' => $start,
            'ended_at' => $start->addSeconds($workDurationSeconds),
            'paused_seconds' => 0,
            'paused_at' => null,
            'payload' => null,
        ]);
    }

    for ($i = 0; $i < 3; $i++) {
        $start = CarbonImmutable::parse('2026-04-10 19:00:00+00:00')->addDays($i);
        FocusSession::factory()->completed()->create([
            'user_id' => $user->id,
            'type' => FocusSessionType::Work,
            'focus_mode_type' => FocusModeType::Pomodoro,
            'sequence_number' => 1,
            'duration_seconds' => $workDurationSeconds,
            'started_at' => $start,
            'ended_at' => $start->addSeconds($workDurationSeconds),
            'paused_seconds' => 0,
            'paused_at' => null,
            'payload' => null,
        ]);
    }

    // Lunch inference: 6 short breaks clustered at 12:20 for a confidence >= 0.6
    $breakDurationSeconds = 1800; // 30 minutes
    for ($i = 0; $i < 6; $i++) {
        $start = CarbonImmutable::parse('2026-04-12 12:20:00+00:00')->addDays($i);
        FocusSession::factory()->completed()->create([
            'user_id' => $user->id,
            'type' => FocusSessionType::ShortBreak,
            'focus_mode_type' => FocusModeType::Pomodoro,
            'sequence_number' => 1,
            'duration_seconds' => $breakDurationSeconds,
            'started_at' => $start,
            'ended_at' => $start->addSeconds($breakDurationSeconds),
            'paused_seconds' => 0,
            'paused_at' => null,
            'payload' => null,
        ]);
    }

    $builder = app(TaskAssistantScheduleDbContextBuilder::class);
    $result = $builder->buildForUser($user, 'schedule my tasks');
    $snapshot = $result['snapshot'] ?? [];

    $schedulePreferences = $snapshot['schedule_preferences'] ?? [];
    expect((string) ($schedulePreferences['energy_bias'] ?? ''))->toBe('morning');
    expect((string) ($schedulePreferences['day_bounds']['start'] ?? ''))->toBe('09:00');
    expect((string) ($schedulePreferences['day_bounds']['end'] ?? ''))->toBe('19:30');
    expect((bool) ($schedulePreferences['lunch_block']['enabled'] ?? false))->toBeTrue();
    expect((string) ($schedulePreferences['lunch_block']['start'] ?? ''))->toBe('12:20');
    expect((string) ($schedulePreferences['lunch_block']['end'] ?? ''))->toBe('12:50');
});

it('keeps schedule_preferences when focus signals are not confident', function (): void {
    CarbonImmutable::setTestNow('2026-04-20T12:00:00+00:00');

    $user = User::factory()->create([
        'timezone' => 'UTC',
        'schedule_preferences' => [
            'schema_version' => 1,
            'energy_bias' => 'balanced',
            'day_bounds' => [
                'start' => '08:00',
                'end' => '22:00',
            ],
            'lunch_block' => [
                'enabled' => true,
                'start' => '12:00',
                'end' => '13:00',
            ],
        ],
    ]);

    // Below minimum sample thresholds (builder defaults require >= 8 work sessions for energy).
    for ($i = 0; $i < 3; $i++) {
        $start = CarbonImmutable::parse('2026-04-15 09:00:00+00:00')->addDays($i);
        FocusSession::factory()->completed()->create([
            'user_id' => $user->id,
            'type' => FocusSessionType::Work,
            'focus_mode_type' => FocusModeType::Pomodoro,
            'sequence_number' => 1,
            'duration_seconds' => 1800,
            'started_at' => $start,
            'ended_at' => $start->addSeconds(1800),
            'paused_seconds' => 0,
            'paused_at' => null,
            'payload' => null,
        ]);
    }

    for ($i = 0; $i < 2; $i++) {
        $start = CarbonImmutable::parse('2026-04-15 12:20:00+00:00')->addDays($i);
        FocusSession::factory()->completed()->create([
            'user_id' => $user->id,
            'type' => FocusSessionType::ShortBreak,
            'focus_mode_type' => FocusModeType::Pomodoro,
            'sequence_number' => 1,
            'duration_seconds' => 1800,
            'started_at' => $start,
            'ended_at' => $start->addSeconds(1800),
            'paused_seconds' => 0,
            'paused_at' => null,
            'payload' => null,
        ]);
    }

    $builder = app(TaskAssistantScheduleDbContextBuilder::class);
    $result = $builder->buildForUser($user, 'schedule my tasks');
    $snapshot = $result['snapshot'] ?? [];

    $schedulePreferences = $snapshot['schedule_preferences'] ?? [];
    expect((string) ($schedulePreferences['energy_bias'] ?? ''))->toBe('balanced');
    expect((string) ($schedulePreferences['day_bounds']['start'] ?? ''))->toBe('08:00');
    expect((string) ($schedulePreferences['day_bounds']['end'] ?? ''))->toBe('22:00');
    expect((string) ($schedulePreferences['lunch_block']['start'] ?? ''))->toBe('12:00');
    expect((string) ($schedulePreferences['lunch_block']['end'] ?? ''))->toBe('13:00');
});

it('does not overwrite explicit energy bias with balanced inference', function (): void {
    CarbonImmutable::setTestNow('2026-04-20T12:00:00+00:00');

    $user = User::factory()->create([
        'timezone' => 'UTC',
        'schedule_preferences' => [
            'schema_version' => 1,
            'energy_bias' => 'evening',
            'day_bounds' => [
                'start' => '08:00',
                'end' => '22:00',
            ],
            'lunch_block' => [
                'enabled' => true,
                'start' => '12:00',
                'end' => '13:00',
            ],
        ],
    ]);

    $workDurationSeconds = 1800;

    // 6 morning + 6 evening => confident balanced inference with sufficient samples.
    for ($i = 0; $i < 6; $i++) {
        $morning = CarbonImmutable::parse('2026-04-10 09:00:00+00:00')->addDays($i);
        FocusSession::factory()->completed()->create([
            'user_id' => $user->id,
            'type' => FocusSessionType::Work,
            'focus_mode_type' => FocusModeType::Pomodoro,
            'sequence_number' => 1,
            'duration_seconds' => $workDurationSeconds,
            'started_at' => $morning,
            'ended_at' => $morning->addSeconds($workDurationSeconds),
            'paused_seconds' => 0,
            'paused_at' => null,
            'payload' => null,
        ]);

        $evening = CarbonImmutable::parse('2026-04-10 19:00:00+00:00')->addDays($i);
        FocusSession::factory()->completed()->create([
            'user_id' => $user->id,
            'type' => FocusSessionType::Work,
            'focus_mode_type' => FocusModeType::Pomodoro,
            'sequence_number' => 1,
            'duration_seconds' => $workDurationSeconds,
            'started_at' => $evening,
            'ended_at' => $evening->addSeconds($workDurationSeconds),
            'paused_seconds' => 0,
            'paused_at' => null,
            'payload' => null,
        ]);
    }

    $builder = app(TaskAssistantScheduleDbContextBuilder::class);
    $result = $builder->buildForUser($user, 'schedule my tasks');
    $snapshot = $result['snapshot'] ?? [];
    $schedulePreferences = $snapshot['schedule_preferences'] ?? [];

    expect((string) ($schedulePreferences['energy_bias'] ?? ''))->toBe('evening');
});

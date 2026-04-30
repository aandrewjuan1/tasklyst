<?php

use App\Enums\FocusModeType;
use App\Enums\FocusSessionType;
use App\Models\FocusSession;
use App\Models\User;
use App\Services\LLM\Scheduling\FocusSessionSchedulingSignalsBuilder;
use Carbon\CarbonImmutable;

it('infers morning energy bias from completed work sessions', function (): void {
    CarbonImmutable::setTestNow('2026-04-20T12:00:00+00:00');

    $user = User::factory()->create([
        'timezone' => 'UTC',
    ]);

    $workDurationSeconds = 1500; // 25 minutes

    for ($i = 0; $i < 6; $i++) {
        FocusSession::factory()->completed()->create([
            'user_id' => $user->id,
            'type' => FocusSessionType::Work,
            'focus_mode_type' => FocusModeType::Sprint,
            'sequence_number' => 1,
            'duration_seconds' => $workDurationSeconds,
            'started_at' => CarbonImmutable::parse('2026-04-15 09:00:00+00:00')->addDays($i),
            'ended_at' => CarbonImmutable::parse('2026-04-15 09:00:00+00:00')->addDays($i)->addSeconds($workDurationSeconds),
            'paused_seconds' => 0,
            'paused_at' => null,
            'payload' => null,
        ]);
    }

    for ($i = 0; $i < 2; $i++) {
        FocusSession::factory()->completed()->create([
            'user_id' => $user->id,
            'type' => FocusSessionType::Work,
            'focus_mode_type' => FocusModeType::Sprint,
            'sequence_number' => 1,
            'duration_seconds' => $workDurationSeconds,
            'started_at' => CarbonImmutable::parse('2026-04-15 19:00:00+00:00')->addDays($i),
            'ended_at' => CarbonImmutable::parse('2026-04-15 19:00:00+00:00')->addDays($i)->addSeconds($workDurationSeconds),
            'paused_seconds' => 0,
            'paused_at' => null,
            'payload' => null,
        ]);
    }

    $builder = app(FocusSessionSchedulingSignalsBuilder::class);
    $signals = $builder->buildForUser($user, 'UTC', CarbonImmutable::now('UTC'));

    expect($signals['schedule_preferences_override']['energy_bias'] ?? null)->toBe('morning');
    expect((float) ($signals['energy_bias_confidence'] ?? 0.0))->toBeGreaterThan(0.6);
});

it('infers day bounds and keeps a sane span', function (): void {
    CarbonImmutable::setTestNow('2026-04-20T12:00:00+00:00');

    $user = User::factory()->create([
        'timezone' => 'UTC',
    ]);

    $workDurationSeconds = 1800; // 30 minutes

    // 10 starts at 09:00, 2 starts at 11:00 -> q10=09:00, q90=11:00
    for ($i = 0; $i < 10; $i++) {
        $start = CarbonImmutable::parse('2026-04-10 09:00:00+00:00')->addDays($i);
        FocusSession::factory()->completed()->create([
            'user_id' => $user->id,
            'type' => FocusSessionType::Work,
            'focus_mode_type' => FocusModeType::Sprint,
            'sequence_number' => 1,
            'duration_seconds' => $workDurationSeconds,
            'started_at' => $start,
            'ended_at' => $start->addSeconds($workDurationSeconds),
            'paused_seconds' => 0,
            'paused_at' => null,
            'payload' => null,
        ]);
    }

    for ($i = 0; $i < 2; $i++) {
        $start = CarbonImmutable::parse('2026-04-18 11:00:00+00:00')->addDays($i);
        FocusSession::factory()->completed()->create([
            'user_id' => $user->id,
            'type' => FocusSessionType::Work,
            'focus_mode_type' => FocusModeType::Sprint,
            'sequence_number' => 1,
            'duration_seconds' => $workDurationSeconds,
            'started_at' => $start,
            'ended_at' => $start->addSeconds($workDurationSeconds),
            'paused_seconds' => 0,
            'paused_at' => null,
            'payload' => null,
        ]);
    }

    $builder = app(FocusSessionSchedulingSignalsBuilder::class);
    $signals = $builder->buildForUser($user, 'UTC', CarbonImmutable::now('UTC'));

    $dayBounds = $signals['schedule_preferences_override']['day_bounds'] ?? [];
    expect((string) ($dayBounds['start'] ?? ''))->toBe('09:00');
    expect((string) ($dayBounds['end'] ?? ''))->toBe('17:00');
});

it('infers lunch block from completed break sessions', function (): void {
    CarbonImmutable::setTestNow('2026-04-20T12:00:00+00:00');

    $user = User::factory()->create([
        'timezone' => 'UTC',
    ]);

    $breakDurationSeconds = 1800; // 30 minutes

    // Keep all breaks in a tight 12:20 cluster so rounding is deterministic.
    for ($i = 0; $i < 6; $i++) {
        $start = CarbonImmutable::parse('2026-04-12 12:20:00+00:00')->addDays($i);
        FocusSession::factory()->completed()->create([
            'user_id' => $user->id,
            'type' => FocusSessionType::ShortBreak,
            'focus_mode_type' => FocusModeType::Sprint,
            'sequence_number' => 1,
            'duration_seconds' => $breakDurationSeconds,
            'started_at' => $start,
            'ended_at' => $start->addSeconds($breakDurationSeconds),
            'paused_seconds' => 0,
            'paused_at' => null,
            'payload' => null,
        ]);
    }

    $builder = app(FocusSessionSchedulingSignalsBuilder::class);
    $signals = $builder->buildForUser($user, 'UTC', CarbonImmutable::now('UTC'));

    $lunch = $signals['schedule_preferences_override']['lunch_block'] ?? null;
    expect(is_array($lunch) ? (bool) ($lunch['enabled'] ?? false) : false)->toBeTrue();
    expect((string) ($lunch['start'] ?? ''))->toBe('12:20');
    expect((string) ($lunch['end'] ?? ''))->toBe('12:50');
});

it('predicts work duration minutes from effectiveWorkSeconds', function (): void {
    CarbonImmutable::setTestNow('2026-04-20T12:00:00+00:00');

    $user = User::factory()->create([
        'timezone' => 'UTC',
    ]);

    $workDurationSeconds = 1800; // 30 minutes

    for ($i = 0; $i < 12; $i++) {
        $start = CarbonImmutable::parse('2026-04-01 09:00:00+00:00')->addDays($i);
        FocusSession::factory()->completed()->create([
            'user_id' => $user->id,
            'type' => FocusSessionType::Work,
            'focus_mode_type' => FocusModeType::Sprint,
            'sequence_number' => 1,
            'duration_seconds' => $workDurationSeconds,
            'started_at' => $start,
            'ended_at' => $start->addSeconds($workDurationSeconds),
            'paused_seconds' => 0,
            'paused_at' => null,
            'payload' => null,
        ]);
    }

    $builder = app(FocusSessionSchedulingSignalsBuilder::class);
    $signals = $builder->buildForUser($user, 'UTC', CarbonImmutable::now('UTC'));

    expect((int) ($signals['work_duration_minutes_predicted'] ?? 0))->toBe(30);
    expect((float) ($signals['work_duration_confidence'] ?? 0.0))->toBeGreaterThanOrEqual(0.6);
});

it('predicts average gaps between completed work sessions', function (): void {
    CarbonImmutable::setTestNow('2026-04-20T12:00:00+00:00');

    $user = User::factory()->create([
        'timezone' => 'UTC',
    ]);

    $sessionDurationMinutes = 60;
    $durationSeconds = $sessionDurationMinutes * 60;
    $gapMinutes = 20;

    // 13 sessions -> 12 gaps -> confidence = 12/20 = 0.6 with default min threshold in compute engine.
    for ($i = 0; $i < 13; $i++) {
        $startMinutes = (9 * 60) + ($i * ($sessionDurationMinutes + $gapMinutes));
        $hour = (int) floor($startMinutes / 60);
        $minute = (int) ($startMinutes % 60);
        $start = CarbonImmutable::parse('2026-04-10 00:00:00+00:00')->setTime($hour, $minute);
        $end = $start->addSeconds($durationSeconds);

        FocusSession::factory()->completed()->create([
            'user_id' => $user->id,
            'type' => FocusSessionType::Work,
            'focus_mode_type' => FocusModeType::Sprint,
            'sequence_number' => 1,
            'duration_seconds' => $durationSeconds,
            'started_at' => $start,
            'ended_at' => $end,
            'paused_seconds' => 0,
            'paused_at' => null,
            'payload' => null,
        ]);
    }

    $builder = app(FocusSessionSchedulingSignalsBuilder::class);
    $signals = $builder->buildForUser($user, 'UTC', CarbonImmutable::now('UTC'));

    expect((int) ($signals['gap_minutes_predicted'] ?? -1))->toBe(20);
    expect((float) ($signals['gap_confidence'] ?? 0.0))->toBeGreaterThanOrEqual(0.6);
});

it('emits active focus session projection even when duration is missing', function (): void {
    CarbonImmutable::setTestNow('2026-04-20T12:00:00+00:00');

    $user = User::factory()->create([
        'timezone' => 'UTC',
    ]);

    FocusSession::factory()->create([
        'user_id' => $user->id,
        'type' => FocusSessionType::Work,
        'focus_mode_type' => FocusModeType::Sprint,
        'sequence_number' => 1,
        'completed' => false,
        'duration_seconds' => 0,
        'started_at' => CarbonImmutable::parse('2026-04-20T11:30:00+00:00'),
        'ended_at' => null,
        'paused_at' => null,
        'paused_seconds' => 0,
        'payload' => null,
    ]);

    $builder = app(FocusSessionSchedulingSignalsBuilder::class);
    $signals = $builder->buildForUser($user, 'UTC', CarbonImmutable::now('UTC'));

    $active = $signals['active_focus_session'] ?? null;
    expect($active)->toBeArray();
    expect((string) ($active['projected_end_at_iso'] ?? ''))->not->toBe('');

    $projectedEnd = CarbonImmutable::parse((string) ($active['projected_end_at_iso'] ?? ''), 'UTC');
    expect($projectedEnd->greaterThan(CarbonImmutable::now('UTC')))->toBeTrue();
});

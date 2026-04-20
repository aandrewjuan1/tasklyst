<?php

use App\Enums\TaskRecurrenceType;
use App\Models\RecurringSchoolClass;
use App\Models\SchoolClass;
use App\Models\SchoolClassException;
use App\Models\User;
use App\Services\Scheduling\SchoolClassBusyIntervalResolver;
use Carbon\CarbonImmutable;

it('skips recurring occurrence dates removed by school class exceptions', function (): void {
    $timezone = (string) config('app.timezone', 'UTC');
    $user = User::factory()->create();
    $start = CarbonImmutable::parse('2026-05-04 10:00:00', $timezone);
    $end = CarbonImmutable::parse('2026-05-04 11:00:00', $timezone);

    $schoolClass = SchoolClass::factory()->for($user)->create([
        'start_datetime' => $start,
        'end_datetime' => $end,
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
    ]);

    $recurring = RecurringSchoolClass::factory()->create([
        'school_class_id' => $schoolClass->id,
        'recurrence_type' => TaskRecurrenceType::Daily,
        'interval' => 1,
        'start_datetime' => $start,
        'end_datetime' => $start->copy()->addDays(2)->endOfDay(),
        'days_of_week' => null,
    ]);

    SchoolClassException::factory()->create([
        'recurring_school_class_id' => $recurring->id,
        'exception_date' => '2026-05-05',
        'is_deleted' => true,
        'replacement_instance_id' => null,
    ]);

    $resolver = app(SchoolClassBusyIntervalResolver::class);
    $intervals = $resolver->resolveForUser(
        user: $user,
        rangeStart: CarbonImmutable::parse('2026-05-04 00:00:00', $timezone),
        rangeEnd: CarbonImmutable::parse('2026-05-06 23:59:59', $timezone),
        bufferMinutes: 0,
    );

    $starts = array_map(
        static fn (array $row): string => CarbonImmutable::parse((string) $row['start'], $timezone)->toDateString(),
        $intervals
    );

    expect($starts)->toContain('2026-05-04');
    expect($starts)->not->toContain('2026-05-05');
    expect($starts)->toContain('2026-05-06');
});

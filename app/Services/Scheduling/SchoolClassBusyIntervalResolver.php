<?php

namespace App\Services\Scheduling;

use App\Enums\EventStatus;
use App\Models\RecurringSchoolClass;
use App\Models\SchoolClass;
use App\Models\SchoolClassException;
use App\Models\SchoolClassInstance;
use App\Models\User;
use App\Services\RecurrenceExpander;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class SchoolClassBusyIntervalResolver
{
    public function __construct(
        private readonly RecurrenceExpander $recurrenceExpander,
    ) {}

    /**
     * @return list<array{school_class_id:int,start:string,end:string,source:string}>
     */
    public function resolveForUser(
        User $user,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
        int $bufferMinutes = 0,
    ): array {
        if ($rangeEnd->lessThanOrEqualTo($rangeStart)) {
            return [];
        }

        $buffer = max(0, $bufferMinutes);
        $timezone = new \DateTimeZone((string) config('app.timezone', 'UTC'));

        $classes = SchoolClass::query()
            ->forUser($user->id)
            ->notArchived()
            ->with('recurringSchoolClass')
            ->get();

        if ($classes->isEmpty()) {
            return [];
        }

        $recurring = $classes->pluck('recurringSchoolClass')
            ->filter(fn (mixed $value): bool => $value instanceof RecurringSchoolClass)
            ->values();

        $exceptionsByRecurringId = $this->preloadExceptionsByRecurringId($recurring, $rangeStart, $rangeEnd);
        $instancesByRecurringId = $this->preloadInstancesByRecurringId($recurring, $rangeStart, $rangeEnd);

        $intervals = [];

        foreach ($classes as $schoolClass) {
            if (! $schoolClass instanceof SchoolClass) {
                continue;
            }

            $recurringClass = $schoolClass->recurringSchoolClass;
            if ($recurringClass instanceof RecurringSchoolClass) {
                $intervals = array_merge(
                    $intervals,
                    $this->resolveRecurringIntervals(
                        schoolClass: $schoolClass,
                        recurringClass: $recurringClass,
                        rangeStart: $rangeStart,
                        rangeEnd: $rangeEnd,
                        timezone: $timezone,
                        bufferMinutes: $buffer,
                        preloadedExceptions: $exceptionsByRecurringId->get($recurringClass->id),
                        preloadedInstances: $instancesByRecurringId->get($recurringClass->id, collect()),
                    ),
                );

                continue;
            }

            $interval = $this->resolveSingleInterval($schoolClass, $buffer, $rangeStart, $rangeEnd, $timezone);
            if ($interval === null) {
                continue;
            }

            $intervals[] = $interval;
        }

        usort(
            $intervals,
            static fn (array $left, array $right): int => strcmp((string) $left['start'], (string) $right['start'])
        );

        return array_values($intervals);
    }

    /**
     * @param  Collection<int, RecurringSchoolClass>  $recurringClasses
     * @return Collection<int, Collection<int, SchoolClassException>>
     */
    private function preloadExceptionsByRecurringId(
        Collection $recurringClasses,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
    ): Collection {
        if ($recurringClasses->isEmpty()) {
            return collect();
        }

        $ids = $recurringClasses->pluck('id')->filter()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        /** @var Collection<int, SchoolClassException> $exceptions */
        $exceptions = SchoolClassException::query()
            ->whereIn('recurring_school_class_id', $ids)
            ->where(function ($query) use ($rangeStart, $rangeEnd): void {
                $query->whereBetween('exception_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
                    ->orWhereHas('replacementInstance', function ($replacement) use ($rangeStart, $rangeEnd): void {
                        $replacement->whereBetween('instance_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()]);
                    });
            })
            ->with('replacementInstance')
            ->get();

        return $exceptions->groupBy('recurring_school_class_id');
    }

    /**
     * @param  Collection<int, RecurringSchoolClass>  $recurringClasses
     * @return Collection<int, Collection<int, SchoolClassInstance>>
     */
    private function preloadInstancesByRecurringId(
        Collection $recurringClasses,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
    ): Collection {
        if ($recurringClasses->isEmpty()) {
            return collect();
        }

        $ids = $recurringClasses->pluck('id')->filter()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        /** @var Collection<int, SchoolClassInstance> $instances */
        $instances = SchoolClassInstance::query()
            ->whereIn('recurring_school_class_id', $ids)
            ->whereBetween('instance_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->get();

        return $instances->groupBy('recurring_school_class_id');
    }

    /**
     * @param  Collection<int, SchoolClassException>|null  $preloadedExceptions
     * @param  Collection<int, SchoolClassInstance>  $preloadedInstances
     * @return list<array{school_class_id:int,start:string,end:string,source:string}>
     */
    private function resolveRecurringIntervals(
        SchoolClass $schoolClass,
        RecurringSchoolClass $recurringClass,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
        \DateTimeZone $timezone,
        int $bufferMinutes,
        ?Collection $preloadedExceptions,
        Collection $preloadedInstances,
    ): array {
        $cancelledInstanceDates = $preloadedInstances
            ->filter(function (mixed $row): bool {
                if (! $row instanceof SchoolClassInstance) {
                    return false;
                }

                return $row->cancelled || $row->status === EventStatus::Cancelled;
            })
            ->map(static fn (SchoolClassInstance $row): ?string => $row->instance_date?->toDateString())
            ->filter(static fn (?string $date): bool => is_string($date) && $date !== '')
            ->values()
            ->all();
        $cancelledLookup = array_flip($cancelledInstanceDates);

        $occurrenceDates = $this->recurrenceExpander->expand(
            $recurringClass,
            $rangeStart,
            $rangeEnd,
            $preloadedExceptions
        );

        if ($occurrenceDates === []) {
            return [];
        }

        $intervals = [];

        foreach ($occurrenceDates as $occurrenceDate) {
            try {
                $occurrenceDay = CarbonImmutable::parse($occurrenceDate, $timezone)->toDateString();
            } catch (\Throwable) {
                continue;
            }

            if (isset($cancelledLookup[$occurrenceDay])) {
                continue;
            }

            $interval = $this->resolveOccurrenceInterval($schoolClass, $occurrenceDay, $bufferMinutes, $rangeStart, $rangeEnd, $timezone);
            if ($interval === null) {
                continue;
            }

            $intervals[] = $interval;
        }

        return $intervals;
    }

    /**
     * @return array{school_class_id:int,start:string,end:string,source:string}|null
     */
    private function resolveSingleInterval(
        SchoolClass $schoolClass,
        int $bufferMinutes,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
        \DateTimeZone $timezone,
    ): ?array {
        $startDateTime = $schoolClass->start_datetime;
        $endDateTime = $schoolClass->end_datetime;

        if ($startDateTime === null || $endDateTime === null) {
            return null;
        }

        $start = CarbonImmutable::instance($startDateTime)->setTimezone($timezone);
        $end = CarbonImmutable::instance($endDateTime)->setTimezone($timezone);

        return $this->buildBufferedInterval(
            schoolClassId: (int) $schoolClass->id,
            start: $start,
            end: $end,
            bufferMinutes: $bufferMinutes,
            rangeStart: $rangeStart,
            rangeEnd: $rangeEnd,
            source: 'school_class',
        );
    }

    /**
     * @return array{school_class_id:int,start:string,end:string,source:string}|null
     */
    private function resolveOccurrenceInterval(
        SchoolClass $schoolClass,
        string $occurrenceDay,
        int $bufferMinutes,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
        \DateTimeZone $timezone,
    ): ?array {
        $startTime = $this->resolveClassClockTime($schoolClass, 'start');
        $endTime = $this->resolveClassClockTime($schoolClass, 'end');

        if ($startTime === null || $endTime === null) {
            return null;
        }

        try {
            $start = CarbonImmutable::parse($occurrenceDay.' '.$startTime, $timezone);
            $end = CarbonImmutable::parse($occurrenceDay.' '.$endTime, $timezone);
        } catch (\Throwable) {
            return null;
        }

        if ($end->lessThanOrEqualTo($start)) {
            return null;
        }

        return $this->buildBufferedInterval(
            schoolClassId: (int) $schoolClass->id,
            start: $start,
            end: $end,
            bufferMinutes: $bufferMinutes,
            rangeStart: $rangeStart,
            rangeEnd: $rangeEnd,
            source: 'school_class_recurring',
        );
    }

    private function resolveClassClockTime(SchoolClass $schoolClass, string $edge): ?string
    {
        $timeColumn = $edge === 'start' ? 'start_time' : 'end_time';
        $dateTimeColumn = $edge === 'start' ? 'start_datetime' : 'end_datetime';

        $timeValue = trim((string) ($schoolClass->{$timeColumn} ?? ''));
        if ($timeValue !== '') {
            try {
                return CarbonImmutable::parse($timeValue)->format('H:i:s');
            } catch (\Throwable) {
                return null;
            }
        }

        $dateTimeValue = $schoolClass->{$dateTimeColumn};
        if ($dateTimeValue === null) {
            return null;
        }

        try {
            return CarbonImmutable::instance($dateTimeValue)->format('H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{school_class_id:int,start:string,end:string,source:string}|null
     */
    private function buildBufferedInterval(
        int $schoolClassId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        int $bufferMinutes,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
        string $source,
    ): ?array {
        $boundedStart = $bufferMinutes > 0 ? $start->subMinutes($bufferMinutes) : $start;
        $boundedEnd = $bufferMinutes > 0 ? $end->addMinutes($bufferMinutes) : $end;

        if ($boundedEnd->lessThanOrEqualTo($boundedStart)) {
            return null;
        }

        if ($boundedEnd->lessThanOrEqualTo($rangeStart) || $boundedStart->greaterThanOrEqualTo($rangeEnd)) {
            return null;
        }

        $clampedStart = $boundedStart->lessThan($rangeStart) ? $rangeStart : $boundedStart;
        $clampedEnd = $boundedEnd->greaterThan($rangeEnd) ? $rangeEnd : $boundedEnd;
        if ($clampedEnd->lessThanOrEqualTo($clampedStart)) {
            return null;
        }

        return [
            'school_class_id' => $schoolClassId,
            'start' => $clampedStart->toIso8601String(),
            'end' => $clampedEnd->toIso8601String(),
            'source' => $source,
        ];
    }
}

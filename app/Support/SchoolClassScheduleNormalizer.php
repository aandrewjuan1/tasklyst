<?php

namespace App\Support;

use App\Enums\TaskRecurrenceType;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class SchoolClassScheduleNormalizer
{
    /**
     * @param  array<string, mixed>  $payload  Validated school class payload (scheduleMode, times, dates, recurrence).
     * @return array{
     *     start_time: string,
     *     end_time: string,
     *     start_datetime: \Illuminate\Support\Carbon|null,
     *     end_datetime: \Illuminate\Support\Carbon|null,
     *     recurrence: array<string, mixed>|null,
     *     recurrence_series_end_datetime: ?\Illuminate\Support\Carbon
     * }
     */
    public static function normalize(array $payload): array
    {
        $mode = $payload['scheduleMode'] ?? 'recurring';
        $timezone = config('app.timezone');

        if ($mode === 'one_off') {
            return self::normalizeOneOff($payload, $timezone);
        }

        return self::normalizeRecurring($payload, $timezone);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     start_time: string,
     *     end_time: string,
     *     start_datetime: \Illuminate\Support\Carbon|null,
     *     end_datetime: \Illuminate\Support\Carbon|null,
     *     recurrence: null,
     *     recurrence_series_end_datetime: null
     * }
     */
    private static function normalizeOneOff(array $payload, string $timezone): array
    {
        $startTime = self::parseTimeToCarbon((string) ($payload['startTime'] ?? ''), $timezone);
        $endTime = self::parseTimeToCarbon((string) ($payload['endTime'] ?? ''), $timezone);

        if (self::minutesSinceMidnight($endTime) <= self::minutesSinceMidnight($startTime)) {
            throw self::validationError(__('End time must be after the start time.'), 'schoolClassPayload.endTime');
        }

        $meetingDateRaw = trim((string) ($payload['meetingDate'] ?? ''));
        $startDatetime = null;
        $endDatetime = null;
        if ($meetingDateRaw !== '') {
            $meetingDate = Carbon::parse($meetingDateRaw, $timezone)->startOfDay();
            $startDatetime = self::combineDateAndTime($meetingDate, $startTime->format('H:i:s'), $timezone);
            $endDatetime = self::combineDateAndTime($meetingDate, $endTime->format('H:i:s'), $timezone);
        }

        return [
            'start_time' => $startTime->format('H:i:s'),
            'end_time' => $endTime->format('H:i:s'),
            'start_datetime' => $startDatetime,
            'end_datetime' => $endDatetime,
            'recurrence' => null,
            'recurrence_series_end_datetime' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     start_time: string,
     *     end_time: string,
     *     start_datetime: \Illuminate\Support\Carbon|null,
     *     end_datetime: \Illuminate\Support\Carbon|null,
     *     recurrence: array<string, mixed>,
     *     recurrence_series_end_datetime: \Illuminate\Support\Carbon|null
     * }
     */
    private static function normalizeRecurring(array $payload, string $timezone): array
    {
        $recurrence = $payload['recurrence'] ?? [];
        $recurrence['enabled'] = true;
        $type = TaskRecurrenceType::tryFrom((string) ($recurrence['type'] ?? ''));
        if ($type === null) {
            throw self::validationError(__('Select how the class repeats.'), 'schoolClassPayload.recurrence.type');
        }

        $startTimeStr = (string) ($payload['startTime'] ?? '');
        $endTimeStr = (string) ($payload['endTime'] ?? '');
        $timeError = self::validateSameDayTimeOrder($startTimeStr, $endTimeStr, $timezone);
        if ($timeError !== null) {
            throw self::validationError($timeError, 'schoolClassPayload.endTime');
        }

        $daysOfWeek = isset($recurrence['daysOfWeek']) && is_array($recurrence['daysOfWeek'])
            ? array_values(array_map(fn (mixed $d): int => (int) $d, $recurrence['daysOfWeek']))
            : [];

        $scheduleStartRaw = trim((string) ($payload['scheduleStartDate'] ?? ''));
        $scheduleEndRaw = trim((string) ($payload['scheduleEndDate'] ?? ''));

        $scheduleStart = $scheduleStartRaw !== '' ? Carbon::parse($scheduleStartRaw, $timezone)->startOfDay() : null;
        $scheduleEnd = $scheduleEndRaw !== '' ? Carbon::parse($scheduleEndRaw, $timezone)->startOfDay() : null;

        if ($scheduleStart !== null && $scheduleEnd !== null && $scheduleEnd->lessThan($scheduleStart)) {
            throw self::validationError(__('The schedule end date must be on or after the start date.'), 'schoolClassPayload.scheduleEndDate');
        }

        if ($type === TaskRecurrenceType::Weekly && $daysOfWeek === []) {
            $anchorDay = $scheduleStart?->dayOfWeek ?? Carbon::now($timezone)->dayOfWeek;
            $daysOfWeek = [$anchorDay];
        }

        $firstDay = null;
        if ($scheduleStart !== null && $scheduleEnd !== null) {
            $firstDay = self::resolveFirstOccurrenceDate($scheduleStart, $scheduleEnd, $type, $daysOfWeek);
            if ($firstDay === null) {
                throw self::validationError(
                    __('No class day falls between the schedule start and end. Adjust the dates or selected days.'),
                    'schoolClassPayload.scheduleStartDate'
                );
            }
        }

        $startTime = self::parseTimeToCarbon($startTimeStr, $timezone);
        $endTime = self::parseTimeToCarbon($endTimeStr, $timezone);

        $start = $firstDay !== null
            ? self::combineDateAndTime($firstDay, $startTime->format('H:i:s'), $timezone)
            : null;
        $end = $firstDay !== null
            ? self::combineDateAndTime($firstDay, $endTime->format('H:i:s'), $timezone)
            : null;

        $recurrenceForService = [
            'enabled' => true,
            'type' => $type->value,
            'interval' => max(1, (int) ($recurrence['interval'] ?? 1)),
            'daysOfWeek' => $daysOfWeek,
        ];

        return [
            'start_time' => $startTime->format('H:i:s'),
            'end_time' => $endTime->format('H:i:s'),
            'start_datetime' => $start,
            'end_datetime' => $end,
            'recurrence' => $recurrenceForService,
            'recurrence_series_end_datetime' => $scheduleEnd?->copy()->endOfDay(),
        ];
    }

    private static function resolveFirstOccurrenceDate(
        CarbonInterface $scheduleStart,
        CarbonInterface $scheduleEnd,
        TaskRecurrenceType $type,
        array $daysOfWeek
    ): ?Carbon {
        $last = Carbon::parse($scheduleEnd)->startOfDay();

        return match ($type) {
            TaskRecurrenceType::Weekly => self::firstWeeklyOccurrence($scheduleStart, $last, $daysOfWeek),
            TaskRecurrenceType::Daily => self::firstDailyOccurrence($scheduleStart, $last),
            TaskRecurrenceType::Monthly, TaskRecurrenceType::Yearly => self::firstMonthlyOrYearlyOccurrence($scheduleStart, $last),
        };
    }

    private static function firstWeeklyOccurrence(CarbonInterface $rangeStart, CarbonInterface $rangeEnd, array $daysOfWeek): ?Carbon
    {
        $cursor = Carbon::parse($rangeStart)->startOfDay();
        $last = Carbon::parse($rangeEnd)->startOfDay();

        while ($cursor->lte($last)) {
            if (in_array($cursor->dayOfWeek, $daysOfWeek, true)) {
                return $cursor->copy();
            }
            $cursor->addDay();
        }

        return null;
    }

    private static function firstDailyOccurrence(CarbonInterface $rangeStart, CarbonInterface $rangeEnd): ?Carbon
    {
        $start = Carbon::parse($rangeStart)->startOfDay();
        $last = Carbon::parse($rangeEnd)->startOfDay();

        if ($start->lte($last)) {
            return $start->copy();
        }

        return null;
    }

    private static function firstMonthlyOrYearlyOccurrence(CarbonInterface $rangeStart, CarbonInterface $rangeEnd): ?Carbon
    {
        return self::firstDailyOccurrence($rangeStart, $rangeEnd);
    }

    private static function combineDateAndTime(CarbonInterface $date, string $timeString, string $timezone): Carbon
    {
        $base = Carbon::parse($date, $timezone)->startOfDay();
        $timePart = self::parseTimeToCarbon($timeString, $timezone);

        return $base->copy()->setTime($timePart->hour, $timePart->minute, $timePart->second);
    }

    private static function parseTimeToCarbon(string $timeString, string $timezone): Carbon
    {
        $trimmed = trim($timeString);
        foreach (['H:i:s', 'H:i', 'g:i A', 'h:i A'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $trimmed, $timezone);
                if ($parsed !== false) {
                    return $parsed;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        try {
            return Carbon::parse($trimmed, $timezone);
        } catch (\Throwable) {
            throw self::validationError(__('Enter a valid start and end time.'), 'schoolClassPayload.startTime');
        }
    }

    private static function validateSameDayTimeOrder(string $startTimeStr, string $endTimeStr, string $timezone): ?string
    {
        if ($startTimeStr === '' || $endTimeStr === '') {
            return __('Start and end times are required.');
        }

        $start = self::parseTimeToCarbon($startTimeStr, $timezone);
        $end = self::parseTimeToCarbon($endTimeStr, $timezone);

        $startM = self::minutesSinceMidnight($start);
        $endM = self::minutesSinceMidnight($end);

        if ($endM <= $startM) {
            return __('End time must be after the start time.');
        }

        return null;
    }

    private static function minutesSinceMidnight(Carbon $time): int
    {
        return $time->hour * 60 + $time->minute;
    }

    private static function validationError(string $message, string $key): \Illuminate\Validation\ValidationException
    {
        return \Illuminate\Validation\ValidationException::withMessages([$key => $message]);
    }
}

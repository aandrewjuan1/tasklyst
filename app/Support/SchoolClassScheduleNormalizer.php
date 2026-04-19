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
     *     start_datetime: \Illuminate\Support\Carbon,
     *     end_datetime: \Illuminate\Support\Carbon,
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
     *     start_datetime: \Illuminate\Support\Carbon,
     *     end_datetime: \Illuminate\Support\Carbon,
     *     recurrence: null,
     *     recurrence_series_end_datetime: null
     * }
     */
    private static function normalizeOneOff(array $payload, string $timezone): array
    {
        $meetingDate = Carbon::parse((string) ($payload['meetingDate'] ?? ''), $timezone)->startOfDay();
        $start = self::combineDateAndTime($meetingDate, (string) ($payload['startTime'] ?? ''), $timezone);
        $end = self::combineDateAndTime($meetingDate, (string) ($payload['endTime'] ?? ''), $timezone);

        if ($end->lessThanOrEqualTo($start)) {
            throw self::validationError(__('End time must be after the start time.'), 'schoolClassPayload.endTime');
        }

        return [
            'start_datetime' => $start,
            'end_datetime' => $end,
            'recurrence' => null,
            'recurrence_series_end_datetime' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     start_datetime: \Illuminate\Support\Carbon,
     *     end_datetime: \Illuminate\Support\Carbon,
     *     recurrence: array<string, mixed>,
     *     recurrence_series_end_datetime: \Illuminate\Support\Carbon
     * }
     */
    private static function normalizeRecurring(array $payload, string $timezone): array
    {
        $scheduleStart = Carbon::parse((string) ($payload['scheduleStartDate'] ?? ''), $timezone)->startOfDay();
        $scheduleEnd = Carbon::parse((string) ($payload['scheduleEndDate'] ?? ''), $timezone)->startOfDay();

        if ($scheduleEnd->lessThan($scheduleStart)) {
            throw self::validationError(__('The schedule end date must be on or after the start date.'), 'schoolClassPayload.scheduleEndDate');
        }

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

        if ($type === TaskRecurrenceType::Weekly && $daysOfWeek === []) {
            throw self::validationError(__('Select at least one day of the week.'), 'schoolClassPayload.recurrence.daysOfWeek');
        }

        $firstDay = self::resolveFirstOccurrenceDate($scheduleStart, $scheduleEnd, $type, $daysOfWeek);
        if ($firstDay === null) {
            throw self::validationError(
                __('No class day falls between the schedule start and end. Adjust the dates or selected days.'),
                'schoolClassPayload.scheduleStartDate'
            );
        }

        $start = self::combineDateAndTime($firstDay, $startTimeStr, $timezone);
        $end = self::combineDateAndTime($firstDay, $endTimeStr, $timezone);

        if ($end->lessThanOrEqualTo($start)) {
            throw self::validationError(__('End time must be after the start time.'), 'schoolClassPayload.endTime');
        }

        $recurrenceForService = [
            'enabled' => true,
            'type' => $type->value,
            'interval' => max(1, (int) ($recurrence['interval'] ?? 1)),
            'daysOfWeek' => $daysOfWeek,
        ];

        return [
            'start_datetime' => $start,
            'end_datetime' => $end,
            'recurrence' => $recurrenceForService,
            'recurrence_series_end_datetime' => Carbon::parse($scheduleEnd, $timezone)->copy()->endOfDay(),
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

        $startM = $start->hour * 60 + $start->minute;
        $endM = $end->hour * 60 + $end->minute;

        if ($endM <= $startM) {
            return __('End time must be after the start time.');
        }

        return null;
    }

    private static function validationError(string $message, string $key): \Illuminate\Validation\ValidationException
    {
        return \Illuminate\Validation\ValidationException::withMessages([$key => $message]);
    }
}

<?php

namespace App\Support\Validation;

use App\Enums\TaskRecurrenceType;
use Illuminate\Validation\Rule;

final class SchoolClassPayloadValidation
{
    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'scheduleMode' => 'recurring',
            'subjectName' => '',
            'teacherName' => '',
            'scheduleStartDate' => null,
            'scheduleEndDate' => null,
            'meetingDate' => null,
            'startTime' => null,
            'endTime' => null,
            'recurrence' => [
                'enabled' => true,
                'type' => TaskRecurrenceType::Weekly->value,
                'interval' => 1,
                'daysOfWeek' => [],
            ],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'schoolClassPayload' => ['required', 'array'],
            'schoolClassPayload.scheduleMode' => ['required', Rule::in(['recurring', 'one_off'])],
            'schoolClassPayload.subjectName' => ['required', 'string', 'max:255', 'regex:/\S/'],
            'schoolClassPayload.teacherName' => ['required', 'string', 'max:255', 'regex:/\S/'],
            'schoolClassPayload.scheduleStartDate' => ['nullable', 'date'],
            'schoolClassPayload.scheduleEndDate' => ['nullable', 'date', 'after_or_equal:schoolClassPayload.scheduleStartDate'],
            'schoolClassPayload.meetingDate' => ['nullable', 'required_if:schoolClassPayload.scheduleMode,one_off', 'date'],
            'schoolClassPayload.startTime' => ['required', 'string'],
            'schoolClassPayload.endTime' => ['required', 'string'],
            'schoolClassPayload.recurrence' => ['nullable', 'required_if:schoolClassPayload.scheduleMode,recurring', 'array'],
            'schoolClassPayload.recurrence.enabled' => ['boolean'],
            'schoolClassPayload.recurrence.type' => ['nullable', 'required_if:schoolClassPayload.scheduleMode,recurring', Rule::in(array_map(fn (TaskRecurrenceType $t) => $t->value, TaskRecurrenceType::cases()))],
            'schoolClassPayload.recurrence.interval' => ['integer', 'min:1'],
            'schoolClassPayload.recurrence.daysOfWeek' => ['array'],
            'schoolClassPayload.recurrence.daysOfWeek.*' => ['integer', 'between:0,6'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function allowedUpdateProperties(): array
    {
        return [
            'subjectName',
            'teacherName',
            'startTime',
            'endTime',
            'startDatetime',
            'endDatetime',
            'recurrence',
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rulesForProperty(string $property): array
    {
        return match ($property) {
            'subjectName' => ['value' => ['required', 'string', 'max:255', 'regex:/\S/']],
            'teacherName' => ['value' => ['required', 'string', 'max:255', 'regex:/\S/']],
            'startTime' => ['value' => ['required', 'string']],
            'endTime' => ['value' => ['required', 'string']],
            'startDatetime' => ['value' => ['nullable', 'date']],
            'endDatetime' => ['value' => ['nullable', 'date']],
            'recurrence' => [
                'value' => ['required', 'array'],
                'value.enabled' => ['boolean'],
                'value.type' => ['required', Rule::in(array_map(fn (TaskRecurrenceType $t) => $t->value, TaskRecurrenceType::cases()))],
                'value.interval' => ['integer', 'min:1'],
                'value.daysOfWeek' => ['array'],
                'value.daysOfWeek.*' => ['integer', 'between:0,6'],
            ],
            default => [],
        };
    }

    /**
     * @return string|null Error message if invalid, null if valid
     */
    public static function validateSchoolClassDateRangeForUpdate(?\DateTimeInterface $start, ?\DateTimeInterface $end): ?string
    {
        if ($start === null || $end === null) {
            return null;
        }

        if ($end < $start) {
            return __('End date must be the same as or after the start date.');
        }

        return null;
    }
}

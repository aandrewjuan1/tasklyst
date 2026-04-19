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
            'subjectName' => '',
            'teacherName' => '',
            'startDatetime' => null,
            'endDatetime' => null,
            'recurrence' => [
                'enabled' => false,
                'type' => null,
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
            'schoolClassPayload.subjectName' => ['required', 'string', 'max:255', 'regex:/\S/'],
            'schoolClassPayload.teacherName' => ['required', 'string', 'max:255', 'regex:/\S/'],
            'schoolClassPayload.startDatetime' => ['required', 'date'],
            'schoolClassPayload.endDatetime' => ['required', 'date', 'after_or_equal:schoolClassPayload.startDatetime'],
            'schoolClassPayload.recurrence' => ['array'],
            'schoolClassPayload.recurrence.enabled' => ['boolean'],
            'schoolClassPayload.recurrence.type' => ['nullable', Rule::in(array_map(fn (TaskRecurrenceType $t) => $t->value, TaskRecurrenceType::cases()))],
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
            'startDatetime' => ['value' => ['required', 'date']],
            'endDatetime' => ['value' => ['required', 'date']],
            'recurrence' => [
                'value' => ['array'],
                'value.enabled' => ['boolean'],
                'value.type' => ['nullable', Rule::in(array_map(fn (TaskRecurrenceType $t) => $t->value, TaskRecurrenceType::cases()))],
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
            return __('Start and end are required.');
        }

        if ($end < $start) {
            return __('End date must be the same as or after the start date.');
        }

        return null;
    }
}

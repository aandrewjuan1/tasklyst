<?php

namespace App\Support\Validation;

final class SchoolClassExceptionPayloadValidation
{
    /**
     * @return array<string, mixed>
     */
    public static function createDefaults(): array
    {
        return [
            'recurringSchoolClassId' => null,
            'exceptionDate' => null,
            'isDeleted' => true,
            'replacementInstanceId' => null,
            'reason' => null,
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function createRules(): array
    {
        return [
            'schoolClassExceptionPayload.recurringSchoolClassId' => ['required', 'integer', 'exists:recurring_school_classes,id'],
            'schoolClassExceptionPayload.exceptionDate' => ['required', 'date', 'date_format:Y-m-d'],
            'schoolClassExceptionPayload.isDeleted' => ['boolean'],
            'schoolClassExceptionPayload.replacementInstanceId' => ['nullable', 'integer', 'exists:school_class_instances,id'],
            'schoolClassExceptionPayload.reason' => ['nullable', 'string', 'max:65535'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function updateDefaults(): array
    {
        return [
            'isDeleted' => null,
            'reason' => null,
            'replacementInstanceId' => null,
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function updateRules(): array
    {
        return [
            'schoolClassExceptionPayload.isDeleted' => ['nullable', 'boolean'],
            'schoolClassExceptionPayload.reason' => ['nullable', 'string', 'max:65535'],
            'schoolClassExceptionPayload.replacementInstanceId' => ['nullable', 'integer', 'exists:school_class_instances,id'],
        ];
    }
}

<?php

namespace App\Support\Validation;

final class EventExceptionPayloadValidation
{
    /**
     * @return array<string, mixed>
     */
    public static function createDefaults(): array
    {
        return [
            'recurringEventId' => null,
            'exceptionDate' => null,
            'isDeleted' => true,
            'replacementInstanceId' => null,
            'reason' => null,
        ];
    }

    /**
     * Livewire rules for creating an event exception.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function createRules(): array
    {
        return [
            'eventExceptionPayload.recurringEventId' => ['required', 'integer', 'exists:recurring_events,id'],
            'eventExceptionPayload.exceptionDate' => ['required', 'date', 'date_format:Y-m-d'],
            'eventExceptionPayload.isDeleted' => ['boolean'],
            'eventExceptionPayload.replacementInstanceId' => ['nullable', 'integer', 'exists:event_instances,id'],
            'eventExceptionPayload.reason' => ['nullable', 'string', 'max:65535'],
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
     * Livewire rules for updating an event exception.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function updateRules(): array
    {
        return [
            'eventExceptionPayload.isDeleted' => ['nullable', 'boolean'],
            'eventExceptionPayload.reason' => ['nullable', 'string', 'max:65535'],
            'eventExceptionPayload.replacementInstanceId' => ['nullable', 'integer', 'exists:event_instances,id'],
        ];
    }
}

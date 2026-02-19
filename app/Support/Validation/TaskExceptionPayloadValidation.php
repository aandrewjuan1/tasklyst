<?php

namespace App\Support\Validation;

final class TaskExceptionPayloadValidation
{
    /**
     * @return array<string, mixed>
     */
    public static function createDefaults(): array
    {
        return [
            'recurringTaskId' => null,
            'exceptionDate' => null,
            'isDeleted' => true,
            'replacementInstanceId' => null,
            'reason' => null,
        ];
    }

    /**
     * Livewire rules for creating a task exception.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function createRules(): array
    {
        return [
            'taskExceptionPayload.recurringTaskId' => ['required', 'integer', 'exists:recurring_tasks,id'],
            'taskExceptionPayload.exceptionDate' => ['required', 'date', 'date_format:Y-m-d'],
            'taskExceptionPayload.isDeleted' => ['boolean'],
            'taskExceptionPayload.replacementInstanceId' => ['nullable', 'integer', 'exists:task_instances,id'],
            'taskExceptionPayload.reason' => ['nullable', 'string', 'max:65535'],
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
     * Livewire rules for updating a task exception.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function updateRules(): array
    {
        return [
            'taskExceptionPayload.isDeleted' => ['nullable', 'boolean'],
            'taskExceptionPayload.reason' => ['nullable', 'string', 'max:65535'],
            'taskExceptionPayload.replacementInstanceId' => ['nullable', 'integer', 'exists:task_instances,id'],
        ];
    }
}

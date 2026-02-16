<?php

namespace App\Support\Validation;

final class FocusSessionStartValidation
{
    /**
     * Validation rules for starting a focus session.
     * Payload: task_id (nullable for breaks), type, duration_seconds, started_at, optional sequence_number, optional payload.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'task_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'type' => ['required', 'string', 'in:work,short_break,long_break'],
            'duration_seconds' => ['required', 'integer', 'min:60'],
            'started_at' => ['required', 'date'],
            'sequence_number' => ['nullable', 'integer', 'min:1'],
            'payload' => ['nullable', 'array'],
            'occurrence_date' => ['nullable', 'date'],
        ];
    }
}

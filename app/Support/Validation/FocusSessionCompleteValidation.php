<?php

namespace App\Support\Validation;

final class FocusSessionCompleteValidation
{
    /**
     * Validation rules for completing or abandoning a focus session.
     * Either focus_session_id OR (task_id + started_at) identifies the session.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'focus_session_id' => ['nullable', 'integer', 'exists:focus_sessions,id'],
            'task_id' => ['nullable', 'required_without:focus_session_id', 'integer', 'exists:tasks,id'],
            'started_at' => ['nullable', 'required_without:focus_session_id', 'date'],
            'ended_at' => ['required', 'date'],
            'completed' => ['required', 'boolean'],
            'paused_seconds' => ['required', 'integer', 'min:0'],
            'duration_seconds' => ['nullable', 'integer', 'min:1'],
            'mark_task_status' => ['nullable', 'string', 'in:to_do,doing,done'],
        ];
    }
}

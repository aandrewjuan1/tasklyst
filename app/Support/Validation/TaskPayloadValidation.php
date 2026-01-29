<?php

namespace App\Support\Validation;

use App\Enums\TaskComplexity;
use App\Enums\TaskPriority;
use App\Enums\TaskRecurrenceType;
use App\Enums\TaskStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

final class TaskPayloadValidation
{
    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'title' => '',
            'status' => TaskStatus::ToDo->value,
            'priority' => TaskPriority::Medium->value,
            'complexity' => TaskComplexity::Moderate->value,
            'duration' => 60,
            'startDatetime' => null,
            'endDatetime' => null,
            'projectId' => null,
            'tagIds' => [],
            'recurrence' => [
                'enabled' => false,
                'type' => null,
                'interval' => 1,
                'daysOfWeek' => [],
            ],
        ];
    }

    /**
     * Livewire rules for validating `taskPayload.*`.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'taskPayload.title' => ['required', 'string', 'max:255', 'regex:/\S/'],

            'taskPayload.status' => ['nullable', Rule::in(array_map(fn (TaskStatus $s) => $s->value, TaskStatus::cases()))],
            'taskPayload.priority' => ['nullable', Rule::in(array_map(fn (TaskPriority $p) => $p->value, TaskPriority::cases()))],
            'taskPayload.complexity' => ['nullable', Rule::in(array_map(fn (TaskComplexity $c) => $c->value, TaskComplexity::cases()))],

            'taskPayload.duration' => ['nullable', 'integer', 'min:1'],
            'taskPayload.startDatetime' => ['nullable', 'date'],
            'taskPayload.endDatetime' => ['nullable', 'date', 'after_or_equal:taskPayload.startDatetime'],

            'taskPayload.projectId' => ['nullable', 'integer', 'exists:projects,id'],
            'taskPayload.tagIds' => ['array'],
            'taskPayload.tagIds.*' => [
                'integer',
                Rule::exists('tags', 'id')->where(function ($query) {
                    $userId = Auth::id();
                    if ($userId !== null) {
                        $query->where('user_id', $userId);
                    }
                }),
            ],

            'taskPayload.recurrence' => ['array'],
            'taskPayload.recurrence.enabled' => ['boolean'],
            'taskPayload.recurrence.type' => ['nullable', Rule::in(array_map(fn (TaskRecurrenceType $t) => $t->value, TaskRecurrenceType::cases()))],
            'taskPayload.recurrence.interval' => ['integer', 'min:1'],
            'taskPayload.recurrence.daysOfWeek' => ['array'],
            'taskPayload.recurrence.daysOfWeek.*' => ['integer', 'between:0,6'],
        ];
    }
}

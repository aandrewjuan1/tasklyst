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
    public const MAX_DURATION_MINUTES = 1440; // 24 hours

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'title' => '',
            'description' => null,
            'status' => TaskStatus::ToDo->value,
            'priority' => TaskPriority::Medium->value,
            'complexity' => TaskComplexity::Moderate->value,
            'duration' => null,
            'startDatetime' => null,
            'endDatetime' => null,
            'projectId' => null,
            'tagIds' => [],
            'pendingTagNames' => [],
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
            'taskPayload.description' => ['nullable', 'string', 'max:65535'],

            'taskPayload.status' => ['nullable', Rule::in(array_map(fn (TaskStatus $s) => $s->value, TaskStatus::cases()))],
            'taskPayload.priority' => ['nullable', Rule::in(array_map(fn (TaskPriority $p) => $p->value, TaskPriority::cases()))],
            'taskPayload.complexity' => ['nullable', Rule::in(array_map(fn (TaskComplexity $c) => $c->value, TaskComplexity::cases()))],

            'taskPayload.duration' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_DURATION_MINUTES],
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
            'taskPayload.pendingTagNames' => ['array'],
            'taskPayload.pendingTagNames.*' => ['string', 'max:255', 'regex:/\S/'],

            'taskPayload.recurrence' => ['array'],
            'taskPayload.recurrence.enabled' => ['boolean'],
            'taskPayload.recurrence.type' => ['nullable', Rule::in(array_map(fn (TaskRecurrenceType $t) => $t->value, TaskRecurrenceType::cases()))],
            'taskPayload.recurrence.interval' => ['integer', 'min:1'],
            'taskPayload.recurrence.daysOfWeek' => ['array'],
            'taskPayload.recurrence.daysOfWeek.*' => ['integer', 'between:0,6'],
        ];
    }

    /**
     * Property names allowed for inline task update (camelCase as sent from frontend).
     *
     * @return array<int, string>
     */
    public static function allowedUpdateProperties(): array
    {
        return [
            'title',
            'description',
            'status',
            'priority',
            'complexity',
            'duration',
            'startDatetime',
            'endDatetime',
            'tagIds',
            'recurrence',
        ];
    }

    /**
     * Validation rules for a single task property when updating inline.
     * Validates input keyed by "value".
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesForProperty(string $property): array
    {
        // For inline updates, allow tags that exist even if they belong to a different user.
        // This is required for collaboration scenarios where the item may already have tags
        // created by the owner, and edit-collaborators should be able to update tagIds.
        $tagExistsRule = Rule::exists('tags', 'id');

        $rules = match ($property) {
            'title' => ['value' => ['required', 'string', 'max:255', 'regex:/\S/']],
            'description' => ['value' => ['nullable', 'string', 'max:65535']],
            'status' => ['value' => ['nullable', Rule::in(array_map(fn (TaskStatus $s) => $s->value, TaskStatus::cases()))]],
            'priority' => ['value' => ['nullable', Rule::in(array_map(fn (TaskPriority $p) => $p->value, TaskPriority::cases()))]],
            'complexity' => ['value' => ['nullable', Rule::in(array_map(fn (TaskComplexity $c) => $c->value, TaskComplexity::cases()))]],
            'duration' => ['value' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_DURATION_MINUTES]],
            'startDatetime' => ['value' => ['nullable', 'date']],
            'endDatetime' => ['value' => ['nullable', 'date']],
            'tagIds' => [
                'value' => ['array'],
                'value.*' => ['integer', $tagExistsRule],
            ],
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

        return $rules;
    }

    /**
     * Validate task start/end date range for inline update (same rules as frontend).
     * End must be >= start; if same day and duration > 0, end must be >= start + duration minutes.
     *
     * @return string|null Error message if invalid, null if valid
     */
    public static function validateTaskDateRangeForUpdate(?\DateTimeInterface $start, ?\DateTimeInterface $end, int $durationMinutes): ?string
    {
        if ($start === null || $end === null) {
            return null;
        }

        if ($end < $start) {
            return __('End date must be the same as or after the start date.');
        }

        $startDate = $start->format('Y-m-d');
        $endDate = $end->format('Y-m-d');
        if ($startDate === $endDate && $durationMinutes > 0) {
            $minimumEndTimestamp = $start->getTimestamp() + ($durationMinutes * 60);
            if ($end->getTimestamp() < $minimumEndTimestamp) {
                return __('End time must be at least :minutes minutes after the start time.', [
                    'minutes' => (string) $durationMinutes,
                ]);
            }
        }

        return null;
    }
}

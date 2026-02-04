<?php

namespace App\Support\Validation;

use App\Enums\EventRecurrenceType;
use App\Enums\EventStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

final class EventPayloadValidation
{
    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'title' => '',
            'description' => null,
            'status' => EventStatus::Scheduled->value,
            'startDatetime' => null,
            'endDatetime' => null,
            'allDay' => false,
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
     * Validation rules for `eventPayload.*`.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'eventPayload.title' => ['required', 'string', 'max:255', 'regex:/\S/'],
            'eventPayload.description' => ['nullable', 'string', 'max:65535'],

            'eventPayload.status' => ['nullable', Rule::in(array_map(fn (EventStatus $s) => $s->value, EventStatus::cases()))],

            'eventPayload.startDatetime' => ['nullable', 'date'],
            'eventPayload.endDatetime' => ['nullable', 'date', 'after_or_equal:eventPayload.startDatetime'],

            'eventPayload.allDay' => ['nullable', 'boolean'],

            'eventPayload.tagIds' => ['array'],
            'eventPayload.tagIds.*' => [
                'integer',
                Rule::exists('tags', 'id')->where(function ($query): void {
                    $userId = Auth::id();
                    if ($userId !== null) {
                        $query->where('user_id', $userId);
                    }
                }),
            ],
            'eventPayload.pendingTagNames' => ['array'],
            'eventPayload.pendingTagNames.*' => ['string', 'max:255', 'regex:/\S/'],

            'eventPayload.recurrence' => ['array'],
            'eventPayload.recurrence.enabled' => ['boolean'],
            'eventPayload.recurrence.type' => ['nullable', Rule::in(array_map(fn (EventRecurrenceType $t) => $t->value, EventRecurrenceType::cases()))],
            'eventPayload.recurrence.interval' => ['integer', 'min:1'],
            'eventPayload.recurrence.daysOfWeek' => ['array'],
            'eventPayload.recurrence.daysOfWeek.*' => ['integer', 'between:0,6'],
        ];
    }

    /**
     * Property names allowed for inline event update (camelCase as sent from frontend).
     *
     * @return array<int, string>
     */
    public static function allowedUpdateProperties(): array
    {
        return [
            'title',
            'status',
            'startDatetime',
            'endDatetime',
            'tagIds',
            'allDay',
            'recurrence',
        ];
    }

    /**
     * Validation rules for a single event property when updating inline.
     * Validates input keyed by "value".
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesForProperty(string $property): array
    {
        $tagExistsRule = Rule::exists('tags', 'id')->where(function ($query): void {
            $userId = Auth::id();
            if ($userId !== null) {
                $query->where('user_id', $userId);
            }
        });

        $rules = match ($property) {
            'title' => ['value' => ['required', 'string', 'max:255', 'regex:/\S/']],
            'status' => ['value' => ['nullable', Rule::in(array_map(fn (EventStatus $s) => $s->value, EventStatus::cases()))]],
            'startDatetime' => ['value' => ['nullable', 'date']],
            'endDatetime' => ['value' => ['nullable', 'date']],
            'tagIds' => [
                'value' => ['array'],
                'value.*' => ['integer', $tagExistsRule],
            ],
            'allDay' => ['value' => ['nullable', 'boolean']],
            'recurrence' => [
                'value' => ['array'],
                'value.enabled' => ['boolean'],
                'value.type' => ['nullable', Rule::in(array_map(fn (EventRecurrenceType $t) => $t->value, EventRecurrenceType::cases()))],
                'value.interval' => ['integer', 'min:1'],
                'value.daysOfWeek' => ['array'],
                'value.daysOfWeek.*' => ['integer', 'between:0,6'],
            ],
            default => [],
        };

        return $rules;
    }

    /**
     * Validate event start/end date range for inline update.
     *
     * @return string|null Error message if invalid, null if valid
     */
    public static function validateEventDateRangeForUpdate(?\DateTimeInterface $start, ?\DateTimeInterface $end): ?string
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

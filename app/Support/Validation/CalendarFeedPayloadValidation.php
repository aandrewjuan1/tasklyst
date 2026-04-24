<?php

namespace App\Support\Validation;

use Illuminate\Validation\Rule;

final class CalendarFeedPayloadValidation
{
    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'feedUrl' => '',
            'name' => null,
            'excludeOverdueItems' => true,
            'importPastMonths' => (int) config('calendar_feeds.default_import_past_months', 3),
        ];
    }

    /**
     * Validation rules for `calendarFeedPayload.*`.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        /** @var list<int|string> $allowedMonths */
        $allowedMonths = config('calendar_feeds.allowed_import_past_months', [1, 3, 6]);

        return [
            'calendarFeedPayload.feedUrl' => [
                'required',
                'string',
                'url',
                'max:2000',
                'regex:/^https:\/\/eac\.brightspace\.com\/d2l\/le\/calendar\/feed\/user\/feed\.ics(\?.+)?$/',
            ],
            'calendarFeedPayload.name' => ['nullable', 'string', 'max:255'],
            'calendarFeedPayload.excludeOverdueItems' => ['required', 'boolean'],
            'calendarFeedPayload.importPastMonths' => ['required', 'integer', Rule::in($allowedMonths)],
        ];
    }
}

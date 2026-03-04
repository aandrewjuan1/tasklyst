<?php

namespace App\Support\Validation;

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
        ];
    }

    /**
     * Validation rules for `calendarFeedPayload.*`.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'calendarFeedPayload.feedUrl' => [
                'required',
                'string',
                'url',
                'max:2000',
                'regex:/^https:\/\/eac\.brightspace\.com\/d2l\/le\/calendar\/feed\/user\/feed\.ics(\?.+)?$/',
            ],
            'calendarFeedPayload.name' => ['nullable', 'string', 'max:255'],
        ];
    }
}

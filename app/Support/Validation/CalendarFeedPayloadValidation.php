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
            'calendarFeedPayload.feedUrl' => ['required', 'string', 'url', 'max:2000'],
            'calendarFeedPayload.name' => ['nullable', 'string', 'max:255'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCalendarFeedImportPastMonthsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, \Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    public function rules(): array
    {
        /** @var list<int|string> $allowed */
        $allowed = config('calendar_feeds.allowed_import_past_months', [1, 3, 6]);

        return [
            'months' => ['required', 'integer', Rule::in($allowed)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'months.required' => __('Choose how many months of past events to import for this feed.'),
            'months.integer' => __('The import window must be a whole number of months.'),
            'months.in' => __('Choose 1, 3, or 6 months of past events to import.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'months' => __('Months of past events to import'),
        ];
    }
}

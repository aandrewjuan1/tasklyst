<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSchedulerPreferencesRequest extends FormRequest
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
        return [
            'timezone' => ['nullable', 'timezone:all'],
            'day_bounds_start' => ['nullable', 'date_format:H:i'],
            'day_bounds_end' => ['nullable', 'date_format:H:i', 'after:day_bounds_start'],
            'energy_bias' => ['filled', 'in:morning,afternoon,evening,balanced'],
            'lunch_block_enabled' => ['boolean'],
            'lunch_block_start' => ['nullable', 'date_format:H:i'],
            'lunch_block_end' => ['nullable', 'date_format:H:i', 'after:lunch_block_start'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'timezone.timezone' => __('Choose a valid timezone identifier.'),
            'day_bounds_end.after' => __('Day bounds end must be later than day bounds start.'),
            'lunch_block_end.after' => __('Lunch end must be later than lunch start.'),
            'energy_bias.in' => __('Energy bias must be morning, afternoon, evening, or balanced.'),
        ];
    }
}

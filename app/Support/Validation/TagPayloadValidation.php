<?php

namespace App\Support\Validation;

final class TagPayloadValidation
{
    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'name' => '',
        ];
    }

    /**
     * Rules for validating tag create payload (e.g. name).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'regex:/\S/'],
        ];
    }

    /**
     * Custom validation messages for tag create.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'name.required' => __('Tag name is required.'),
            'name.max' => __('Tag name cannot exceed 255 characters.'),
            'name.regex' => __('Tag name cannot be empty.'),
        ];
    }
}

<?php

namespace App\Support\Validation;

final class TeacherPayloadValidation
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
     * Rules for validating teacher name on create or update.
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
     * Custom validation messages for teacher create/update.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'name.required' => __('Teacher name is required.'),
            'name.max' => __('Teacher name cannot exceed 255 characters.'),
            'name.regex' => __('Teacher name cannot be empty.'),
        ];
    }
}

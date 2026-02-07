<?php

namespace App\Support\Validation;

final class ProjectPayloadValidation
{
    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'name' => '',
            'description' => null,
            'startDatetime' => null,
            'endDatetime' => null,
        ];
    }

    /**
     * Livewire rules for validating `projectPayload.*`.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'projectPayload.name' => ['required', 'string', 'max:255', 'regex:/\S/'],
            'projectPayload.description' => ['nullable', 'string', 'max:65535'],
            'projectPayload.startDatetime' => ['nullable', 'date'],
            'projectPayload.endDatetime' => ['nullable', 'date', 'after_or_equal:projectPayload.startDatetime'],
        ];
    }

    /**
     * Property names allowed for inline project update (camelCase as sent from frontend).
     *
     * @return array<int, string>
     */
    public static function allowedUpdateProperties(): array
    {
        return [
            'name',
            'description',
            'startDatetime',
            'endDatetime',
        ];
    }

    /**
     * Validation rules for a single project property when updating inline.
     * Validates input keyed by "value".
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesForProperty(string $property): array
    {
        $rules = match ($property) {
            'name' => ['value' => ['required', 'string', 'max:255', 'regex:/\S/']],
            'description' => ['value' => ['nullable', 'string', 'max:65535']],
            'startDatetime' => ['value' => ['nullable', 'date']],
            'endDatetime' => ['value' => ['nullable', 'date']],
            default => [],
        };

        return $rules;
    }
}

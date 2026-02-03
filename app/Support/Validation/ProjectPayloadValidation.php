<?php

namespace App\Support\Validation;

final class ProjectPayloadValidation
{
    /**
     * Property names allowed for inline project update (camelCase as sent from frontend).
     *
     * @return array<int, string>
     */
    public static function allowedUpdateProperties(): array
    {
        return [
            'name',
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
            default => [],
        };

        return $rules;
    }
}

<?php

namespace App\DataTransferObjects\Llm;

final readonly class ProjectUpdatePropertiesRecommendationDto
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function __construct(
        public array $properties,
        public string $reasoning,
        public float $confidence,
    ) {}

    /**
     * Build from structured LLM response payload for project property updates.
     *
     * @param  array<string, mixed>  $structured
     */
    public static function fromStructured(array $structured): ?self
    {
        $reasoning = trim((string) ($structured['reasoning'] ?? ''));
        if ($reasoning === '') {
            return null;
        }

        $rawProperties = isset($structured['properties']) && is_array($structured['properties'])
            ? $structured['properties']
            : [];

        $properties = [];

        foreach ($rawProperties as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $normalizedKey = match ($key) {
                'name',
                'description',
                'startDatetime',
                'endDatetime' => $key,
                default => null,
            };

            if ($normalizedKey === null) {
                continue;
            }

            if (in_array($normalizedKey, ['startDatetime', 'endDatetime'], true) && is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
            }

            $properties[$normalizedKey] = $value;
        }

        $confidence = (float) ($structured['confidence'] ?? 0.0);
        if ($confidence < 0.0) {
            $confidence = 0.0;
        }
        if ($confidence > 1.0) {
            $confidence = 1.0;
        }

        if ($properties === []) {
            return new self(
                properties: [],
                reasoning: $reasoning,
                confidence: $confidence,
            );
        }

        return new self(
            properties: $properties,
            reasoning: $reasoning,
            confidence: $confidence,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function proposedProperties(): array
    {
        return $this->properties;
    }
}

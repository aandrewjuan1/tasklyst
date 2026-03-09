<?php

namespace App\DataTransferObjects\Llm;

final readonly class TaskUpdatePropertiesRecommendationDto
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
     * Build from structured LLM response payload for task property updates.
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
                'title',
                'description',
                'status',
                'priority',
                'complexity',
                'duration',
                'startDatetime',
                'endDatetime',
                'tagNames' => $key,
                'start_datetime' => 'startDatetime',
                'end_datetime' => 'endDatetime',
                default => null,
            };

            if ($normalizedKey === null) {
                continue;
            }

            if (in_array($normalizedKey, ['status', 'priority', 'complexity'], true) && is_string($value)) {
                $value = strtolower(trim($value));
            }

            if ($normalizedKey === 'duration' && is_numeric($value)) {
                $intVal = (int) $value;
                if ($intVal <= 0) {
                    continue;
                }
                $value = $intVal;
            }

            if (in_array($normalizedKey, ['startDatetime', 'endDatetime'], true) && is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
            }

            if ($normalizedKey === 'tagNames' && is_array($value)) {
                $tagNames = [];
                foreach ($value as $tag) {
                    $name = trim((string) $tag);
                    if ($name !== '') {
                        $tagNames[] = $name;
                    }
                }
                $value = $tagNames;
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

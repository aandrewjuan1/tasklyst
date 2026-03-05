<?php

namespace App\DataTransferObjects\Llm;

use App\Support\DateHelper;
use Illuminate\Support\Carbon;

final readonly class ProjectScheduleRecommendationDto
{
    public function __construct(
        public ?Carbon $startDatetime,
        public ?Carbon $endDatetime,
        public string $reasoning
    ) {}

    /**
     * Build from structured LLM response payload for project scheduling or timeline adjustment.
     *
     * @param  array<string, mixed>  $structured
     */
    public static function fromStructured(array $structured): ?self
    {
        $proposed = isset($structured['proposed_properties']) && is_array($structured['proposed_properties'])
            ? $structured['proposed_properties']
            : [];

        $source = array_merge($structured, $proposed);

        $reasoning = trim((string) ($structured['reasoning'] ?? ''));
        if ($reasoning === '') {
            return null;
        }

        $start = isset($source['start_datetime'])
            ? DateHelper::parseOptional($source['start_datetime'])
            : null;

        $end = isset($source['end_datetime'])
            ? DateHelper::parseOptional($source['end_datetime'])
            : null;

        if ($start === null && $end === null) {
            return null;
        }

        return new self(
            startDatetime: $start,
            endDatetime: $end,
            reasoning: $reasoning,
        );
    }

    /**
     * Convert to simple attribute array suitable for UpdateProjectPropertyAction.
     *
     * @return array<string, mixed>
     */
    public function toProjectAttributes(): array
    {
        return [
            'startDatetime' => $this->startDatetime,
            'endDatetime' => $this->endDatetime,
        ];
    }

    /**
     * Normalised set of properties that can be applied to a project.
     *
     * @return array<string, mixed>
     */
    public function proposedProperties(): array
    {
        $properties = [];

        if ($this->startDatetime !== null) {
            $properties['startDatetime'] = $this->startDatetime->toIso8601String();
        }

        if ($this->endDatetime !== null) {
            $properties['endDatetime'] = $this->endDatetime->toIso8601String();
        }

        return $properties;
    }
}

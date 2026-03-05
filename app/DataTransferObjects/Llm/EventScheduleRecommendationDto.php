<?php

namespace App\DataTransferObjects\Llm;

use App\Support\DateHelper;
use Illuminate\Support\Carbon;

final readonly class EventScheduleRecommendationDto
{
    public function __construct(
        public ?Carbon $startDatetime,
        public ?Carbon $endDatetime,
        public ?string $timezone,
        public ?string $location,
        public string $reasoning
    ) {}

    /**
     * Build from structured LLM response payload for event scheduling or adjustment.
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

        $timezone = isset($source['timezone']) ? (string) $source['timezone'] : null;
        $location = isset($source['location']) ? (string) $source['location'] : null;

        if ($start === null && $end === null && $timezone === null && $location === null) {
            return null;
        }

        return new self(
            startDatetime: $start,
            endDatetime: $end,
            timezone: $timezone,
            location: $location,
            reasoning: $reasoning,
        );
    }

    /**
     * Convert to simple attribute array suitable for UpdateEventPropertyAction.
     *
     * @return array<string, mixed>
     */
    public function toEventAttributes(): array
    {
        return [
            'startDatetime' => $this->startDatetime,
            'endDatetime' => $this->endDatetime,
        ];
    }

    /**
     * Normalised set of properties that can be applied to an event.
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

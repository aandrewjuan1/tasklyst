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
        $reasoning = trim((string) ($structured['reasoning'] ?? ''));
        if ($reasoning === '') {
            return null;
        }

        $start = isset($structured['start_datetime'])
            ? DateHelper::parseOptional($structured['start_datetime'])
            : null;

        $end = isset($structured['end_datetime'])
            ? DateHelper::parseOptional($structured['end_datetime'])
            : null;

        $timezone = isset($structured['timezone']) ? (string) $structured['timezone'] : null;
        $location = isset($structured['location']) ? (string) $structured['location'] : null;

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
}

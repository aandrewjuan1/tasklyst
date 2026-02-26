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
}

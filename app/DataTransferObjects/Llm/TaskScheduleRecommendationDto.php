<?php

namespace App\DataTransferObjects\Llm;

use App\Support\DateHelper;
use Illuminate\Support\Carbon;

final readonly class TaskScheduleRecommendationDto
{
    public function __construct(
        public ?Carbon $startDatetime,
        public ?Carbon $endDatetime,
        public ?int $durationMinutes,
        public ?string $priority,
        public string $reasoning
    ) {}

    /**
     * Build from structured LLM response payload for task scheduling or deadline adjustment.
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

        // Duration is optional; normalize to int minutes when present and valid.
        $duration = null;
        if (isset($structured['duration']) && is_numeric($structured['duration'])) {
            $minutes = (int) $structured['duration'];
            if ($minutes > 0) {
                $duration = $minutes;
            }
        }

        $priority = isset($structured['priority']) ? strtolower((string) $structured['priority']) : null;
        if ($priority !== null && ! in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) {
            $priority = null;
        }

        // Basic temporal guardrails: ignore recommendations that are wholly in the past
        // or have an invalid ordering between start and end.
        $now = Carbon::now();
        if ($end !== null && $end->lt($now)) {
            return null;
        }
        if ($start !== null && $end !== null && $end->lte($start)) {
            return null;
        }

        if ($start === null && $end === null && $priority === null && $duration === null) {
            return null;
        }

        return new self(
            startDatetime: $start,
            endDatetime: $end,
            durationMinutes: $duration,
            priority: $priority,
            reasoning: $reasoning,
        );
    }

    /**
     * Convert to simple attribute array suitable for UpdateTaskPropertyAction.
     *
     * @return array<string, mixed>
     */
    public function toTaskAttributes(): array
    {
        return [
            'startDatetime' => $this->startDatetime,
            'endDatetime' => $this->endDatetime,
            'priority' => $this->priority,
            'duration' => $this->durationMinutes,
        ];
    }
}

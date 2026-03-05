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

        // Duration is optional; normalize to int minutes when present and valid.
        $duration = null;
        if (isset($source['duration']) && is_numeric($source['duration'])) {
            $minutes = (int) $source['duration'];
            if ($minutes > 0) {
                $duration = $minutes;
            }
        }

        $priority = isset($source['priority']) ? strtolower((string) $source['priority']) : null;
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

    /**
     * Normalised set of properties that can be applied to a task.
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

        if ($this->durationMinutes !== null) {
            $properties['duration'] = $this->durationMinutes;
        }

        if ($this->priority !== null) {
            $properties['priority'] = $this->priority;
        }

        return $properties;
    }
}

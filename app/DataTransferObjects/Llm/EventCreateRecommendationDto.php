<?php

namespace App\DataTransferObjects\Llm;

use App\Support\DateHelper;
use Illuminate\Support\Carbon;

final readonly class EventCreateRecommendationDto
{
    public function __construct(
        public string $title,
        public ?string $description,
        public ?Carbon $startDatetime,
        public ?Carbon $endDatetime,
        public ?string $timezone,
        public ?string $location,
        public string $reasoning,
    ) {}

    /**
     * Build from structured LLM response payload for event creation.
     *
     * @param  array<string, mixed>  $structured
     */
    public static function fromStructured(array $structured): ?self
    {
        $title = trim((string) ($structured['title'] ?? ''));
        $reasoning = trim((string) ($structured['reasoning'] ?? ''));

        if ($title === '' || $reasoning === '') {
            return null;
        }

        $start = isset($structured['start_datetime'])
            ? DateHelper::parseOptional($structured['start_datetime'])
            : null;

        $end = isset($structured['end_datetime'])
            ? DateHelper::parseOptional($structured['end_datetime'])
            : null;

        return new self(
            title: $title,
            description: isset($structured['description']) ? (string) $structured['description'] : null,
            startDatetime: $start,
            endDatetime: $end,
            timezone: isset($structured['timezone']) ? (string) $structured['timezone'] : null,
            location: isset($structured['location']) ? (string) $structured['location'] : null,
            reasoning: $reasoning,
        );
    }

    /**
     * Convert to eventPayload-like array for HandlesEvents::createEvent.
     *
     * @return array<string, mixed>
     */
    public function toEventPayload(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'status' => null,
            'startDatetime' => $this->startDatetime,
            'endDatetime' => $this->endDatetime,
            'allDay' => false,
            'tagIds' => [],
            'pendingTagNames' => [],
            'recurrence' => [
                'enabled' => false,
                'type' => null,
                'interval' => 1,
                'daysOfWeek' => [],
            ],
        ];
    }
}

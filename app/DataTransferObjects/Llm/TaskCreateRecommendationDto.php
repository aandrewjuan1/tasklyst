<?php

namespace App\DataTransferObjects\Llm;

use App\Support\DateHelper;
use Illuminate\Support\Carbon;

final readonly class TaskCreateRecommendationDto
{
    /**
     * @param  array<int, string>  $tagNames
     */
    public function __construct(
        public string $title,
        public ?string $description,
        public ?Carbon $startDatetime,
        public ?Carbon $endDatetime,
        public ?int $durationMinutes,
        public ?string $priority,
        public array $tagNames,
        public string $reasoning,
    ) {}

    /**
     * Build from structured LLM response payload for task creation.
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

        $tags = [];
        if (isset($structured['tags']) && is_array($structured['tags'])) {
            foreach ($structured['tags'] as $tag) {
                $name = trim((string) $tag);
                if ($name !== '') {
                    $tags[] = $name;
                }
            }
        }

        // If nothing besides title/description/reasoning is present, still allow creation;
        // guardrails at higher layers can decide whether to accept.

        return new self(
            title: $title,
            description: isset($structured['description']) ? (string) $structured['description'] : null,
            startDatetime: $start,
            endDatetime: $end,
            durationMinutes: $duration,
            priority: $priority,
            tagNames: $tags,
            reasoning: $reasoning,
        );
    }

    /**
     * Convert to taskPayload-like array for HandlesTasks::createTask.
     *
     * @return array<string, mixed>
     */
    public function toTaskPayload(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'status' => null,
            'priority' => $this->priority,
            'complexity' => null,
            'duration' => $this->durationMinutes,
            'startDatetime' => $this->startDatetime,
            'endDatetime' => $this->endDatetime,
            'projectId' => null,
            'eventId' => null,
            'tagIds' => [],
            'pendingTagNames' => $this->tagNames,
            'recurrence' => [
                'enabled' => false,
                'type' => null,
                'interval' => 1,
                'daysOfWeek' => [],
            ],
        ];
    }
}

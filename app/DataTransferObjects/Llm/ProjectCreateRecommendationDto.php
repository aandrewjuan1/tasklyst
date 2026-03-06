<?php

namespace App\DataTransferObjects\Llm;

use App\Support\DateHelper;
use Illuminate\Support\Carbon;

final readonly class ProjectCreateRecommendationDto
{
    public function __construct(
        public string $name,
        public ?string $description,
        public ?Carbon $startDatetime,
        public ?Carbon $endDatetime,
        public string $reasoning,
    ) {}

    /**
     * Build from structured LLM response payload for project creation.
     *
     * @param  array<string, mixed>  $structured
     */
    public static function fromStructured(array $structured): ?self
    {
        $name = trim((string) ($structured['name'] ?? ''));
        $reasoning = trim((string) ($structured['reasoning'] ?? ''));

        if ($name === '' || $reasoning === '') {
            return null;
        }

        $start = isset($structured['start_datetime'])
            ? DateHelper::parseOptional($structured['start_datetime'])
            : null;

        $end = isset($structured['end_datetime'])
            ? DateHelper::parseOptional($structured['end_datetime'])
            : null;

        return new self(
            name: $name,
            description: isset($structured['description']) ? (string) $structured['description'] : null,
            startDatetime: $start,
            endDatetime: $end,
            reasoning: $reasoning,
        );
    }

    /**
     * Convert to projectPayload-like array for HandlesProjects::createProject.
     *
     * @return array<string, mixed>
     */
    public function toProjectPayload(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'startDatetime' => $this->startDatetime,
            'endDatetime' => $this->endDatetime,
        ];
    }
}

<?php

namespace App\DataTransferObjects\Project;

use App\Support\DateHelper;
use Illuminate\Support\Carbon;

final readonly class CreateProjectDto
{
    public function __construct(
        public string $name,
        public ?string $description,
        public ?Carbon $startDatetime,
        public ?Carbon $endDatetime,
    ) {}

    /**
     * Create from validated projectPayload array.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            name: (string) ($validated['name'] ?? ''),
            description: isset($validated['description']) ? (string) $validated['description'] : null,
            startDatetime: DateHelper::parseOptional($validated['startDatetime'] ?? null),
            endDatetime: DateHelper::parseOptional($validated['endDatetime'] ?? null),
        );
    }

    /**
     * Convert to array format expected by ProjectService::createProject.
     *
     * @return array<string, mixed>
     */
    public function toServiceAttributes(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'start_datetime' => $this->startDatetime,
            'end_datetime' => $this->endDatetime,
        ];
    }
}

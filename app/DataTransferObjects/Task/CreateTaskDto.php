<?php

namespace App\DataTransferObjects\Task;

use App\Support\DateHelper;
use Illuminate\Support\Carbon;

final readonly class CreateTaskDto
{
    public function __construct(
        public string $title,
        public ?string $description,
        public ?string $status,
        public ?string $priority,
        public ?string $complexity,
        public ?int $duration,
        public ?Carbon $startDatetime,
        public ?Carbon $endDatetime,
        public ?int $projectId,
        public ?int $eventId,
        public ?int $parentTaskId,
        /** @var array<int> */
        public array $tagIds,
        /** @var array<string, mixed>|null */
        public ?array $recurrence,
    ) {}

    /**
     * Create from validated taskPayload array (after tag resolution).
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $recurrenceData = $validated['recurrence'] ?? null;
        $recurrenceEnabled = $recurrenceData['enabled'] ?? false;

        return new self(
            title: (string) ($validated['title'] ?? ''),
            description: isset($validated['description']) ? (string) $validated['description'] : null,
            status: isset($validated['status']) ? (string) $validated['status'] : null,
            priority: isset($validated['priority']) ? (string) $validated['priority'] : null,
            complexity: isset($validated['complexity']) ? (string) $validated['complexity'] : null,
            duration: isset($validated['duration']) ? (int) $validated['duration'] : null,
            startDatetime: DateHelper::parseOptional($validated['startDatetime'] ?? null),
            endDatetime: DateHelper::parseOptional($validated['endDatetime'] ?? null),
            projectId: isset($validated['projectId']) ? (int) $validated['projectId'] : null,
            eventId: isset($validated['eventId']) ? (int) $validated['eventId'] : null,
            parentTaskId: isset($validated['parentTaskId']) ? (int) $validated['parentTaskId'] : null,
            tagIds: $validated['tagIds'] ?? [],
            recurrence: $recurrenceEnabled && is_array($recurrenceData) ? $recurrenceData : null,
        );
    }

    /**
     * Convert to array format expected by TaskService::createTask.
     *
     * @return array<string, mixed>
     */
    public function toServiceAttributes(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => $this->priority,
            'complexity' => $this->complexity,
            'duration' => $this->duration,
            'start_datetime' => $this->startDatetime,
            'end_datetime' => $this->endDatetime,
            'project_id' => $this->projectId,
            'event_id' => $this->eventId,
            'parent_task_id' => $this->parentTaskId,
            'tagIds' => $this->tagIds,
            'recurrence' => $this->recurrence,
        ];
    }
}

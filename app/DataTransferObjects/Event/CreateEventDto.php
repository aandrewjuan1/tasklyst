<?php

namespace App\DataTransferObjects\Event;

use App\Support\DateHelper;
use Illuminate\Support\Carbon;

final readonly class CreateEventDto
{
    public function __construct(
        public string $title,
        public ?string $description,
        public ?string $status,
        public ?Carbon $startDatetime,
        public ?Carbon $endDatetime,
        public bool $allDay,
        /** @var array<int> */
        public array $tagIds,
        /** @var array<string, mixed>|null */
        public ?array $recurrence,
    ) {}

    /**
     * Create from validated eventPayload array (after tag resolution).
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
            startDatetime: DateHelper::parseOptional($validated['startDatetime'] ?? null),
            endDatetime: DateHelper::parseOptional($validated['endDatetime'] ?? null),
            allDay: (bool) ($validated['allDay'] ?? false),
            tagIds: $validated['tagIds'] ?? [],
            recurrence: $recurrenceEnabled && is_array($recurrenceData) ? $recurrenceData : null,
        );
    }

    /**
     * Convert to array format expected by EventService::createEvent.
     *
     * @return array<string, mixed>
     */
    public function toServiceAttributes(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'start_datetime' => $this->startDatetime,
            'end_datetime' => $this->endDatetime,
            'all_day' => $this->allDay,
            'tagIds' => $this->tagIds,
            'recurrence' => $this->recurrence,
        ];
    }
}

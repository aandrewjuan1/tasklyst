<?php

namespace App\DataTransferObjects\SchoolClass;

use App\Support\DateHelper;
use Illuminate\Support\Carbon;

final readonly class CreateSchoolClassDto
{
    public function __construct(
        public string $subjectName,
        public string $teacherName,
        public Carbon $startDatetime,
        public Carbon $endDatetime,
        /** @var array<string, mixed>|null */
        public ?array $recurrence,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $recurrenceData = $validated['recurrence'] ?? null;
        $recurrenceEnabled = $recurrenceData['enabled'] ?? false;

        return new self(
            subjectName: (string) ($validated['subjectName'] ?? ''),
            teacherName: trim((string) ($validated['teacherName'] ?? '')),
            startDatetime: DateHelper::parseRequired($validated['startDatetime'] ?? null),
            endDatetime: DateHelper::parseRequired($validated['endDatetime'] ?? null),
            recurrence: $recurrenceEnabled && is_array($recurrenceData) ? $recurrenceData : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toServiceAttributes(): array
    {
        return [
            'subject_name' => $this->subjectName,
            'teacher_name' => $this->teacherName,
            'start_datetime' => $this->startDatetime,
            'end_datetime' => $this->endDatetime,
            'recurrence' => $this->recurrence,
        ];
    }
}

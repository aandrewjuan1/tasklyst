<?php

namespace App\DataTransferObjects\SchoolClass;

use App\Support\SchoolClassScheduleNormalizer;
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
        public ?Carbon $recurrenceSeriesEndDatetime = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $normalized = SchoolClassScheduleNormalizer::normalize($validated);

        return new self(
            subjectName: (string) ($validated['subjectName'] ?? ''),
            teacherName: trim((string) ($validated['teacherName'] ?? '')),
            startDatetime: $normalized['start_datetime'],
            endDatetime: $normalized['end_datetime'],
            recurrence: $normalized['recurrence'],
            recurrenceSeriesEndDatetime: $normalized['recurrence_series_end_datetime'] ?? null,
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
            'recurrence_series_end_datetime' => $this->recurrenceSeriesEndDatetime,
        ];
    }
}

<?php

namespace App\DataTransferObjects\SchoolClass;

final readonly class CreateSchoolClassExceptionDto
{
    public function __construct(
        public int $recurringSchoolClassId,
        public string $exceptionDate,
        public bool $isDeleted,
        public ?int $replacementInstanceId = null,
        public ?string $reason = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            recurringSchoolClassId: (int) $validated['recurringSchoolClassId'],
            exceptionDate: (string) $validated['exceptionDate'],
            isDeleted: (bool) ($validated['isDeleted'] ?? true),
            replacementInstanceId: isset($validated['replacementInstanceId']) ? (int) $validated['replacementInstanceId'] : null,
            reason: isset($validated['reason']) && $validated['reason'] !== '' ? trim((string) $validated['reason']) : null,
        );
    }
}

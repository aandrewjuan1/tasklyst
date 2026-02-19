<?php

namespace App\DataTransferObjects\Task;

final readonly class CreateTaskExceptionDto
{
    public function __construct(
        public int $recurringTaskId,
        public string $exceptionDate,
        public bool $isDeleted,
        public ?int $replacementInstanceId = null,
        public ?string $reason = null,
    ) {}

    /**
     * Create from validated taskExceptionPayload array.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            recurringTaskId: (int) $validated['recurringTaskId'],
            exceptionDate: (string) $validated['exceptionDate'],
            isDeleted: (bool) ($validated['isDeleted'] ?? true),
            replacementInstanceId: isset($validated['replacementInstanceId']) ? (int) $validated['replacementInstanceId'] : null,
            reason: isset($validated['reason']) && $validated['reason'] !== '' ? trim((string) $validated['reason']) : null,
        );
    }
}

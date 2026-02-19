<?php

namespace App\DataTransferObjects\Event;

final readonly class CreateEventExceptionDto
{
    public function __construct(
        public int $recurringEventId,
        public string $exceptionDate,
        public bool $isDeleted,
        public ?int $replacementInstanceId = null,
        public ?string $reason = null,
    ) {}

    /**
     * Create from validated eventExceptionPayload array.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            recurringEventId: (int) $validated['recurringEventId'],
            exceptionDate: (string) $validated['exceptionDate'],
            isDeleted: (bool) ($validated['isDeleted'] ?? true),
            replacementInstanceId: isset($validated['replacementInstanceId']) ? (int) $validated['replacementInstanceId'] : null,
            reason: isset($validated['reason']) && $validated['reason'] !== '' ? trim((string) $validated['reason']) : null,
        );
    }
}

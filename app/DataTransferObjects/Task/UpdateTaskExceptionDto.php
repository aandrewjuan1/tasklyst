<?php

namespace App\DataTransferObjects\Task;

final readonly class UpdateTaskExceptionDto
{
    public function __construct(
        public ?bool $isDeleted = null,
        public ?string $reason = null,
        public ?int $replacementInstanceId = null,
    ) {}

    /**
     * Create from validated taskExceptionPayload array.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            isDeleted: array_key_exists('isDeleted', $validated) ? (bool) $validated['isDeleted'] : null,
            reason: array_key_exists('reason', $validated) ? (trim((string) $validated['reason']) ?: null) : null,
            replacementInstanceId: array_key_exists('replacementInstanceId', $validated) && $validated['replacementInstanceId'] !== null && $validated['replacementInstanceId'] !== '' ? (int) $validated['replacementInstanceId'] : null,
        );
    }

    /**
     * Convert to array format expected by TaskService::updateTaskException.
     *
     * @return array<string, mixed>
     */
    public function toServiceAttributes(): array
    {
        $attributes = [];
        if ($this->isDeleted !== null) {
            $attributes['is_deleted'] = $this->isDeleted;
        }
        if ($this->reason !== null) {
            $attributes['reason'] = $this->reason;
        }
        if ($this->replacementInstanceId !== null) {
            $attributes['replacement_instance_id'] = $this->replacementInstanceId;
        }

        return $attributes;
    }
}

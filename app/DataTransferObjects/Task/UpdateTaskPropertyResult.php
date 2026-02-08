<?php

namespace App\DataTransferObjects\Task;

final readonly class UpdateTaskPropertyResult
{
    public function __construct(
        public bool $success,
        public mixed $oldValue,
        public mixed $newValue,
        public ?string $addedTagName = null,
        public ?string $removedTagName = null,
        public ?string $errorMessage = null,
    ) {}

    public static function success(mixed $oldValue, mixed $newValue, ?string $addedTagName = null, ?string $removedTagName = null): self
    {
        return new self(
            success: true,
            oldValue: $oldValue,
            newValue: $newValue,
            addedTagName: $addedTagName,
            removedTagName: $removedTagName,
        );
    }

    public static function failure(mixed $oldValue, mixed $attemptedValue = null, ?string $errorMessage = null): self
    {
        return new self(
            success: false,
            oldValue: $oldValue,
            newValue: $attemptedValue ?? $oldValue,
            errorMessage: $errorMessage,
        );
    }
}

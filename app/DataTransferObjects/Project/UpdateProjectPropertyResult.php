<?php

namespace App\DataTransferObjects\Project;

final readonly class UpdateProjectPropertyResult
{
    public function __construct(
        public bool $success,
        public mixed $oldValue,
        public mixed $newValue,
        public ?string $errorMessage = null,
    ) {}

    public static function success(mixed $oldValue, mixed $newValue): self
    {
        return new self(
            success: true,
            oldValue: $oldValue,
            newValue: $newValue,
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

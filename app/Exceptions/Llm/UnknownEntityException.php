<?php

namespace App\Exceptions\Llm;

use RuntimeException;
use Throwable;

class UnknownEntityException extends RuntimeException
{
    public function __construct(
        public readonly string $entityType,
        public readonly string|int $entityId,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            "Unknown {$entityType} with id [{$entityId}] referenced by model - possible injection.",
            0,
            $previous,
        );
    }
}

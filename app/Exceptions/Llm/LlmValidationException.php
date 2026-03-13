<?php

namespace App\Exceptions\Llm;

use RuntimeException;
use Throwable;

class LlmValidationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly ?string $rawResponse = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

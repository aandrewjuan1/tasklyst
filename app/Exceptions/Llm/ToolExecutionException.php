<?php

namespace App\Exceptions\Llm;

use RuntimeException;
use Throwable;

class ToolExecutionException extends RuntimeException
{
    /**
     * @param  array<int|string, mixed>  $args
     */
    public function __construct(
        string $message,
        public readonly string $tool,
        public readonly array $args = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

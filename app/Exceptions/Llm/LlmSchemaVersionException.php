<?php

namespace App\Exceptions\Llm;

use RuntimeException;

class LlmSchemaVersionException extends RuntimeException
{
    public function __construct(
        public readonly string $received,
        public readonly string $expected,
    ) {
        parent::__construct("Schema version mismatch: received [{$received}], expected [{$expected}].");
    }
}

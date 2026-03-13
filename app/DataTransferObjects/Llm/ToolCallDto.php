<?php

namespace App\DataTransferObjects\Llm;

final class ToolCallDto
{
    /**
     * @param  array<int|string, mixed>  $args
     */
    public function __construct(
        public readonly string $tool,
        public readonly array $args,
        public readonly string $clientRequestId,
        public readonly bool $confirmationRequired = false,
    ) {}
}

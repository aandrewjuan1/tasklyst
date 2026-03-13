<?php

namespace App\DataTransferObjects\Llm;

final class LlmRequestDto
{
    /**
     * @param  array<int|string, mixed>  $userPayload
     * @param  array<int|string, mixed>  $options
     */
    public function __construct(
        public readonly string $systemPrompt,
        public readonly string $userPayloadJson,
        public readonly float $temperature,
        public readonly int $maxTokens,
        public readonly array $userPayload = [],
        public readonly array $options = [],
        public readonly ?string $traceId = null,
    ) {}
}

<?php

namespace App\DataTransferObjects\Llm;

final class LlmRawResponseDto
{
    public function __construct(
        public readonly string $rawText,
        public readonly float $latencyMs,
        public readonly ?int $tokensUsed = null,
        public readonly ?string $modelName = null,
    ) {}
}

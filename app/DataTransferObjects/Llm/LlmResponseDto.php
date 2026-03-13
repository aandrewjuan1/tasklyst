<?php

namespace App\DataTransferObjects\Llm;

use App\Enums\LlmIntent;

final class LlmResponseDto
{
    /**
     * @param  array<int|string, mixed>  $data
     */
    public function __construct(
        public readonly LlmIntent $intent,
        public readonly array $data,
        public readonly ?ToolCallDto $toolCall,
        public readonly bool $isError,
        public readonly string $message,
        public readonly float $confidence,
        public readonly string $schemaVersion,
        public readonly ?string $raw = null,
    ) {}

    public function hasToolCall(): bool
    {
        return $this->toolCall !== null && ! $this->isError;
    }

    public function isLowConfidence(): bool
    {
        return $this->confidence < config('llm.confidence.low_threshold');
    }

    public static function error(string $userMessage, ?string $raw = null): self
    {
        return new self(
            intent: LlmIntent::Error,
            data: [],
            toolCall: null,
            isError: true,
            message: $userMessage,
            confidence: 0.0,
            schemaVersion: config('llm.schema_version'),
            raw: $raw,
        );
    }
}

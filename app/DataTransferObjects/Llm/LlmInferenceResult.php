<?php

namespace App\DataTransferObjects\Llm;

final readonly class LlmInferenceResult
{
    public function __construct(
        public array $structured,
        public string $promptVersion,
        public int $promptTokens,
        public int $completionTokens,
        public bool $usedFallback = false,
        public ?string $fallbackReason = null,
        /** Raw structured output from Prism/Ollama/Hermes before sanitization (null when fallback). */
        public ?array $rawStructured = null,
    ) {}

    public function toArray(): array
    {
        return [
            'structured' => $this->structured,
            'prompt_version' => $this->promptVersion,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'used_fallback' => $this->usedFallback,
            'fallback_reason' => $this->fallbackReason,
        ];
    }
}

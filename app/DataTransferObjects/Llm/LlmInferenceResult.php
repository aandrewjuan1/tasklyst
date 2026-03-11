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
        /**
         * Minimal context facts (derived from ContextBuilder) for display-layer validation.
         * Intended for guarding narrative consistency (e.g. due_today/is_overdue/duration) without re-querying.
         *
         * @var array<string, mixed>|null
         */
        public ?array $contextFacts = null,
    ) {}

    public function toArray(): array
    {
        $out = [
            'structured' => $this->structured,
            'prompt_version' => $this->promptVersion,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'used_fallback' => $this->usedFallback,
            'fallback_reason' => $this->fallbackReason,
        ];

        if (is_array($this->contextFacts) && $this->contextFacts !== []) {
            $out['context_facts'] = $this->contextFacts;
        }

        return $out;
    }
}

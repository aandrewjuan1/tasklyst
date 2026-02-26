<?php

namespace App\DataTransferObjects\Llm;

final readonly class LlmSystemPromptResult
{
    public function __construct(
        public string $systemPrompt,
        public string $version,
    ) {}
}

<?php

namespace App\Llm\Contracts;

interface LlmPromptTemplate
{
    public function systemPrompt(): string;

    public function version(): string;
}

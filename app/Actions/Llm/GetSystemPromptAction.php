<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\LlmSystemPromptResult;
use App\Enums\LlmIntent;
use App\Services\LlmPromptService;

class GetSystemPromptAction
{
    public function __construct(
        private LlmPromptService $promptService
    ) {}

    public function execute(LlmIntent $intent): LlmSystemPromptResult
    {
        return $this->promptService->getSystemPromptForIntent($intent);
    }
}

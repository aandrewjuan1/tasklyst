<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\LlmSystemPromptResult;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Enums\LlmOperationMode;
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

    /**
     * @param  array<int, LlmEntityType>  $entityTargets
     */
    public function executeForModeAndScope(LlmOperationMode $mode, LlmEntityType $scope, array $entityTargets = []): LlmSystemPromptResult
    {
        return $this->promptService->getSystemPromptForModeAndScope($mode, $scope, $entityTargets);
    }
}

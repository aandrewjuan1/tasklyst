<?php

namespace App\Actions\Llm;

use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Models\AssistantThread;
use App\Models\User;
use App\Services\LlmContextService;

class BuildLlmContextAction
{
    public function __construct(
        private LlmContextService $contextService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(
        User $user,
        LlmIntent $intent,
        LlmEntityType $entityType,
        ?int $entityId = null,
        ?AssistantThread $thread = null
    ): array {
        return $this->contextService->buildContextForIntent($user, $intent, $entityType, $entityId, $thread);
    }
}

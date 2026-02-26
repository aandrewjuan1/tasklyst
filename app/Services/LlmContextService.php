<?php

namespace App\Services;

use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Models\AssistantThread;
use App\Models\User;
use App\Services\Llm\ContextBuilder;

class LlmContextService
{
    public function __construct(
        private ContextBuilder $contextBuilder
    ) {}

    /**
     * Build structured context payload for the given intent and entity type.
     * Used by Phase 5 (LLM inference) to inject into the prompt.
     *
     * @return array<string, mixed>
     */
    public function buildContextForIntent(
        User $user,
        LlmIntent $intent,
        LlmEntityType $entityType,
        ?int $entityId = null,
        ?AssistantThread $thread = null
    ): array {
        return $this->contextBuilder->build($user, $intent, $entityType, $entityId, $thread);
    }
}

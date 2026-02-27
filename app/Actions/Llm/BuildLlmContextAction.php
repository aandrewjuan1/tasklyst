<?php

namespace App\Actions\Llm;

use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Models\AssistantThread;
use App\Models\User;
use App\Services\Llm\ContextBuilder;

class BuildLlmContextAction
{
    public function __construct(
        private ContextBuilder $contextBuilder
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
        return $this->contextBuilder->build($user, $intent, $entityType, $entityId, $thread);
    }
}

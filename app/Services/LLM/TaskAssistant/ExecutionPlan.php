<?php

namespace App\Services\LLM\TaskAssistant;

use App\Enums\TaskAssistantPrioritizeVariant;

final class ExecutionPlan
{
    /**
     * @param  array<int, string>  $reasonCodes
     * @param  array<string, mixed>  $constraints
     * @param  array<int, array{entity_type: string, entity_id: int, title: string}>  $targetEntities
     */
    public function __construct(
        public readonly string $flow,
        public readonly float $confidence,
        public readonly bool $clarificationNeeded,
        public readonly ?string $clarificationQuestion,
        public readonly array $reasonCodes,
        public readonly array $constraints,
        public readonly array $targetEntities,
        public readonly ?string $timeWindowHint,
        public readonly int $countLimit,
        public readonly string $generationProfile,
        public readonly ?TaskAssistantPrioritizeVariant $prioritizeVariant = null,
    ) {}
}

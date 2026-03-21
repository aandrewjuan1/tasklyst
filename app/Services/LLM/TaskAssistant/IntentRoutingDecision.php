<?php

namespace App\Services\LLM\TaskAssistant;

final class IntentRoutingDecision
{
    /**
     * @param  array<int, string>  $reasonCodes
     * @param  array<string, mixed>  $constraints
     */
    public function __construct(
        public readonly string $flow,
        public readonly float $confidence,
        public readonly array $reasonCodes,
        public readonly array $constraints,
        public readonly bool $clarificationNeeded,
        public readonly ?string $clarificationQuestion = null,
    ) {}
}

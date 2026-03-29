<?php

namespace App\Services\LLM\Intent;

use App\Enums\TaskAssistantUserIntent;

final readonly class TaskAssistantIntentInferenceResult
{
    public function __construct(
        public ?TaskAssistantUserIntent $intent,
        public float $confidence,
        public bool $failed,
        public ?string $rationale = null,
        public bool $connectionFailed = false,
    ) {}
}

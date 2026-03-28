<?php

namespace App\Services\LLM\TaskAssistant;

use App\Enums\TaskAssistantPrioritizeVariant;

final readonly class PrioritizeVariantResolution
{
    public function __construct(
        public TaskAssistantPrioritizeVariant $variant,
        public float $confidence,
        public bool $usedClassifier,
        public ?string $classifierRationale = null,
    ) {}
}

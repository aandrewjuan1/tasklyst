<?php

namespace App\DataTransferObjects\Llm;

use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;

final readonly class LlmIntentClassificationResult
{
    public function __construct(
        public LlmIntent $intent,
        public LlmEntityType $entityType,
        public float $confidence,
    ) {}

    public function toArray(): array
    {
        return [
            'intent' => $this->intent->value,
            'entity_type' => $this->entityType->value,
            'confidence' => $this->confidence,
        ];
    }
}

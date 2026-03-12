<?php

namespace App\DataTransferObjects\Llm;

use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Enums\LlmOperationMode;

final readonly class LlmIntentClassificationResult
{
    public function __construct(
        public LlmIntent $intent,
        public LlmEntityType $entityType,
        public float $confidence,
        public LlmOperationMode $operationMode = LlmOperationMode::General,
        /** @var array<int, LlmEntityType> */
        public array $entityTargets = [],
    ) {}

    public function toArray(): array
    {
        return [
            'intent' => $this->intent->value,
            'entity_type' => $this->entityType->value,
            'confidence' => $this->confidence,
            'operation_mode' => $this->operationMode->value,
            'entity_scope' => $this->entityType->value,
            'entity_targets' => array_map(static fn (LlmEntityType $type): string => $type->value, $this->entityTargets),
        ];
    }
}

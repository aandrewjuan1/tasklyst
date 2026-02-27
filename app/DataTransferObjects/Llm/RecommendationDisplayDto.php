<?php

namespace App\DataTransferObjects\Llm;

use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;

/**
 * Validated recommendation payload for Phase 6 display.
 * validation_confidence is computed server-side (required fields, date parse, enums); do not use model self-reported confidence in UI.
 */
final readonly class RecommendationDisplayDto
{
    public function __construct(
        public LlmIntent $intent,
        public LlmEntityType $entityType,
        public string $recommendedAction,
        public string $reasoning,
        public float $validationConfidence,
        public bool $usedFallback,
        public ?string $fallbackReason = null,
        /** @var array<string, mixed> Entity-specific fields: ranked_tasks, start_datetime, end_datetime, priority, blockers, etc. */
        public array $structured = [],
    ) {}

    public function toArray(): array
    {
        return [
            'intent' => $this->intent->value,
            'entity_type' => $this->entityType->value,
            'recommended_action' => $this->recommendedAction,
            'reasoning' => $this->reasoning,
            'validation_confidence' => $this->validationConfidence,
            'used_fallback' => $this->usedFallback,
            'fallback_reason' => $this->fallbackReason,
            'structured' => $this->structured,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            intent: LlmIntent::from($data['intent']),
            entityType: LlmEntityType::from($data['entity_type']),
            recommendedAction: (string) ($data['recommended_action'] ?? ''),
            reasoning: (string) ($data['reasoning'] ?? ''),
            validationConfidence: (float) ($data['validation_confidence'] ?? 0),
            usedFallback: (bool) ($data['used_fallback'] ?? false),
            fallbackReason: $data['fallback_reason'] ?? null,
            structured: (array) ($data['structured'] ?? []),
        );
    }
}

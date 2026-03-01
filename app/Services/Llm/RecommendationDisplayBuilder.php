<?php

namespace App\Services\Llm;

use App\DataTransferObjects\Llm\LlmInferenceResult;
use App\DataTransferObjects\Llm\RecommendationDisplayDto;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use Carbon\Carbon;

/**
 * Builds validated RecommendationDisplayDto from Phase 5 inference result.
 * Computes validation-based confidence (required fields, date parse, enums) for UI; do not use model self-reported confidence.
 */
class RecommendationDisplayBuilder
{
    private const PRIORITY_VALUES = ['low', 'medium', 'high', 'urgent'];

    public function build(LlmInferenceResult $result, LlmIntent $intent, LlmEntityType $entityType): RecommendationDisplayDto
    {
        $structured = $result->structured;
        $validationScore = $this->computeValidationConfidence($structured, $intent, $entityType);

        $recommendedAction = (string) ($structured['recommended_action'] ?? '');
        $reasoning = (string) ($structured['reasoning'] ?? '');

        $actionForDisplay = $recommendedAction !== '' ? $recommendedAction : __('No specific action suggested.');
        $reasoningForDisplay = $reasoning !== '' ? $reasoning : __('The assistant could not provide detailed reasoning.');

        $listedItems = isset($structured['listed_items']) && is_array($structured['listed_items']) ? $structured['listed_items'] : null;
        $message = $this->buildMessageWithOptionalList($actionForDisplay, $reasoningForDisplay, $listedItems);
        $displayStructured = $this->sanitizeStructuredForDisplay($structured, $intent);

        return new RecommendationDisplayDto(
            intent: $intent,
            entityType: $entityType,
            recommendedAction: $actionForDisplay,
            reasoning: $reasoningForDisplay,
            message: $message,
            validationConfidence: $validationScore,
            usedFallback: $result->usedFallback,
            fallbackReason: $result->fallbackReason,
            structured: $displayStructured,
        );
    }

    /**
     * Validation-based confidence: 0–1 from required fields, date parse, enum validity.
     */
    private function computeValidationConfidence(array $structured, LlmIntent $intent, LlmEntityType $entityType): float
    {
        $checks = 0;
        $passed = 0;

        $checks++;
        $passed += isset($structured['entity_type']) && (string) $structured['entity_type'] === $entityType->value ? 1 : 0;

        $checks++;
        $passed += isset($structured['recommended_action']) && is_string($structured['recommended_action']) && trim($structured['recommended_action']) !== '' ? 1 : 0;

        $checks++;
        $passed += isset($structured['reasoning']) && is_string($structured['reasoning']) && trim($structured['reasoning']) !== '' ? 1 : 0;

        if ($intent === LlmIntent::PrioritizeTasks || $intent === LlmIntent::PrioritizeEvents || $intent === LlmIntent::PrioritizeProjects) {
            $ranked = $structured['ranked_tasks'] ?? $structured['ranked_events'] ?? $structured['ranked_projects'] ?? null;
            $checks++;
            $passed += is_array($ranked) && count($ranked) > 0 ? 1 : 0;
        }

        if ($intent === LlmIntent::ResolveDependency) {
            $steps = $structured['next_steps'] ?? null;
            $checks++;
            $passed += is_array($steps) && count($steps) >= 2 ? 1 : 0;
        }

        if (in_array($intent, [LlmIntent::ScheduleTask, LlmIntent::AdjustTaskDeadline], true)) {
            if (! empty($structured['start_datetime'])) {
                $checks++;
                $passed += $this->parseDateTime($structured['start_datetime']) !== null ? 1 : 0;
            }
            if (! empty($structured['end_datetime'])) {
                $checks++;
                $passed += $this->parseDateTime($structured['end_datetime']) !== null ? 1 : 0;
            }
            if (isset($structured['priority']) && $structured['priority'] !== '') {
                $checks++;
                $passed += in_array(strtolower((string) $structured['priority']), self::PRIORITY_VALUES, true) ? 1 : 0;
            }
        }

        if (in_array($intent, [LlmIntent::ScheduleEvent, LlmIntent::AdjustEventTime], true)) {
            if (! empty($structured['start_datetime'])) {
                $checks++;
                $passed += $this->parseDateTime($structured['start_datetime']) !== null ? 1 : 0;
            }
            if (! empty($structured['end_datetime'])) {
                $checks++;
                $passed += $this->parseDateTime($structured['end_datetime']) !== null ? 1 : 0;
            }
        }

        if (in_array($intent, [LlmIntent::ScheduleProject, LlmIntent::AdjustProjectTimeline], true)) {
            if (! empty($structured['start_datetime'])) {
                $checks++;
                $passed += $this->parseDateTime($structured['start_datetime']) !== null ? 1 : 0;
            }
            if (! empty($structured['end_datetime'])) {
                $checks++;
                $passed += $this->parseDateTime($structured['end_datetime']) !== null ? 1 : 0;
            }
        }

        $checks = max(1, $checks);

        return round($passed / $checks, 2);
    }

    /**
     * Build reply: optional summary, optional list (when LLM returns listed_items), optional reasoning.
     * Flexible format so list-style answers (e.g. "tasks with low priority", "tasks with no due date") are shown as a list.
     *
     * @param  array<int, array{title: string, priority?: string, end_datetime?: string}>|null  $listedItems
     */
    private function buildMessageWithOptionalList(string $recommendedAction, string $reasoning, ?array $listedItems): string
    {
        $action = trim($recommendedAction);
        $reason = trim($reasoning);

        if ($action === '' && $reason === '' && empty($listedItems)) {
            return __('No specific action suggested. The assistant could not provide detailed reasoning.');
        }

        $parts = [];

        if ($action !== '') {
            $parts[] = $action;
        }

        if (! empty($listedItems)) {
            $lines = [];
            foreach ($listedItems as $item) {
                $title = isset($item['title']) && is_string($item['title']) ? trim($item['title']) : '';
                if ($title !== '') {
                    $suffix = [];
                    if (! empty($item['priority']) && is_string($item['priority'])) {
                        $suffix[] = $item['priority'];
                    }
                    if (! empty($item['end_datetime']) && is_string($item['end_datetime'])) {
                        $suffix[] = $item['end_datetime'];
                    }
                    $lines[] = '• '.$title.($suffix !== [] ? ' ('.implode(', ', $suffix).')' : '');
                }
            }
            if ($lines !== []) {
                $parts[] = implode("\n", $lines);
            }
        }

        if ($reason !== '') {
            $parts[] = $reason;
        }

        return implode("\n\n", $parts);
    }

    /**
     * Build reply as two paragraphs: recommendation first, then reasoning.
     * No hardcoded connector so the LLM's own wording stays natural.
     */
    private function buildCombinedMessage(string $recommendedAction, string $reasoning): string
    {
        return $this->buildMessageWithOptionalList($recommendedAction, $reasoning, null);
    }

    private function parseDateTime(mixed $value): ?Carbon
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        try {
            $parsed = Carbon::parse($value);

            return $parsed;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizeStructuredForDisplay(array $structured, LlmIntent $intent): array
    {
        $out = [];

        $allowedKeys = ['ranked_tasks', 'ranked_events', 'ranked_projects', 'listed_items', 'start_datetime', 'end_datetime', 'priority', 'duration', 'timezone', 'location', 'blockers', 'next_steps'];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $structured) && $structured[$key] !== null) {
                $out[$key] = $structured[$key];
            }
        }

        return $out;
    }
}

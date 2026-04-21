<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\TaskAssistantThread;
use App\Support\LLM\TaskAssistantReasonCodes;

final class TaskAssistantClosingIntentClassifier
{
    public function __construct(
        private readonly TaskAssistantConversationStateService $conversationState,
    ) {}

    /**
     * @return array{
     *   is_closing: bool,
     *   kind: 'thanks'|'goodbye'|'short_ack'|null,
     *   confidence: float,
     *   reason_codes: list<string>,
     *   context_weighted: bool
     * }
     */
    public function classify(TaskAssistantThread $thread, string $content): array
    {
        $normalized = $this->normalize($content);
        if ($normalized === '') {
            return $this->negativeDecision();
        }

        $state = $this->conversationState->get($thread);
        $contextWeighted = $this->isRecentPlanningContext($state);
        $hasActionableCue = $this->hasActionableCue($normalized);

        $kind = null;
        $reasonCodes = [];
        $confidence = 0.0;

        if ($this->matchesAnyPattern($normalized, (array) config('task-assistant.closing.thanks_patterns', []))) {
            $kind = 'thanks';
            $reasonCodes[] = TaskAssistantReasonCodes::CLOSING_THANKS_DETECTED;
            $confidence = 0.94;
        } elseif ($this->matchesAnyPattern($normalized, (array) config('task-assistant.closing.goodbye_patterns', []))) {
            $kind = 'goodbye';
            $reasonCodes[] = TaskAssistantReasonCodes::CLOSING_GOODBYE_DETECTED;
            $confidence = 0.93;
        } elseif ($this->matchesAnyPattern($normalized, (array) config('task-assistant.closing.short_ack_patterns', []))) {
            $kind = 'short_ack';
            $reasonCodes[] = TaskAssistantReasonCodes::CLOSING_SHORT_ACK_DETECTED;
            $confidence = 0.82;
        }

        if ($kind === null) {
            return $this->negativeDecision();
        }

        if ($hasActionableCue) {
            $reasonCodes[] = TaskAssistantReasonCodes::CLOSING_SUPPRESSED_ACTIONABLE_CUE;

            return $this->negativeDecision($reasonCodes);
        }

        $minConfidence = $contextWeighted
            ? (float) config('task-assistant.closing.context_weighted_min_confidence', 0.7)
            : (float) config('task-assistant.closing.default_min_confidence', 0.88);

        if ($confidence < $minConfidence) {
            $reasonCodes[] = TaskAssistantReasonCodes::CLOSING_BELOW_THRESHOLD;

            return $this->negativeDecision($reasonCodes);
        }

        if ($contextWeighted) {
            $reasonCodes[] = TaskAssistantReasonCodes::CLOSING_CONTEXT_WEIGHTED;
            $confidence = min(1.0, $confidence + 0.08);
        }

        $reasonCodes[] = TaskAssistantReasonCodes::CLOSING_SHORTCIRCUIT_GENERAL_GUIDANCE;

        return [
            'is_closing' => true,
            'kind' => $kind,
            'confidence' => $confidence,
            'reason_codes' => $reasonCodes,
            'context_weighted' => $contextWeighted,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function isRecentPlanningContext(array $state): bool
    {
        $lastFlow = (string) ($state['last_flow'] ?? '');
        if (in_array($lastFlow, ['prioritize', 'schedule', 'prioritize_schedule', 'listing_followup'], true)) {
            return true;
        }

        if (is_array($state['last_listing'] ?? null)) {
            return true;
        }

        if (is_array($state['last_schedule'] ?? null)) {
            return true;
        }

        if (is_array($state['pending_schedule_fallback'] ?? null)) {
            return true;
        }

        return false;
    }

    private function normalize(string $content): string
    {
        $normalized = mb_strtolower(trim($content));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function hasActionableCue(string $normalized): bool
    {
        $patterns = (array) config('task-assistant.closing.actionable_guard_patterns', []);

        return $this->matchesAnyPattern($normalized, $patterns);
    }

    /**
     * @param  array<int, mixed>  $patterns
     */
    private function matchesAnyPattern(string $normalized, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $rawPattern = trim((string) $pattern);
            if ($rawPattern === '') {
                continue;
            }

            if (@preg_match($rawPattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *   is_closing: false,
     *   kind: null,
     *   confidence: float,
     *   reason_codes: list<string>,
     *   context_weighted: false
     * }
     */
    private function negativeDecision(array $reasonCodes = []): array
    {
        return [
            'is_closing' => false,
            'kind' => null,
            'confidence' => 0.0,
            'reason_codes' => array_values(array_unique(array_map(static fn (mixed $code): string => (string) $code, $reasonCodes))),
            'context_weighted' => false,
        ];
    }
}

<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\TaskAssistantThread;
use App\Services\LLM\Intent\TaskAssistantIntentInferenceService;
use App\Services\LLM\Intent\TaskAssistantIntentResolutionService;
use App\Services\LLM\Intent\TaskAssistantIntentSignalExtractor;

/**
 * Sole entry point for task-assistant route intent: heuristic signals, optional
 * Prism structured LLM classification, merge/validation, and constraints.
 */
final class IntentRoutingPolicy
{
    public function __construct(
        private readonly TaskAssistantIntentInferenceService $intentInference,
        private readonly TaskAssistantIntentSignalExtractor $signalExtractor,
        private readonly TaskAssistantIntentResolutionService $resolution,
        private readonly TaskAssistantConversationStateService $conversationState,
    ) {}

    public function decide(TaskAssistantThread $thread, string $content): IntentRoutingDecision
    {
        $normalized = mb_strtolower(trim($content));
        if ($normalized === '') {
            return new IntentRoutingDecision(
                flow: 'chat',
                confidence: 1.0,
                reasonCodes: ['empty_message'],
                constraints: $this->buildConstraints($thread, $normalized),
                clarificationNeeded: false,
                clarificationQuestion: null,
            );
        }

        $signals = $this->signalExtractor->extract($normalized);

        $inference = null;
        if ((bool) config('task-assistant.intent.use_llm', true)) {
            $inference = $this->intentInference->infer($content);
        }

        $decision = $this->resolution->resolve($thread, $normalized, $inference, $signals);

        return new IntentRoutingDecision(
            flow: $decision->flow,
            confidence: $decision->confidence,
            reasonCodes: $decision->reasonCodes,
            constraints: $this->buildConstraints($thread, $normalized),
            clarificationNeeded: $decision->clarificationNeeded,
            clarificationQuestion: $decision->clarificationQuestion,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildConstraints(TaskAssistantThread $thread, string $normalized): array
    {
        $selected = $this->conversationState->selectedEntities($thread);
        $useSelected = preg_match('/\b(those|them|those\s+\d+|the\s+above)\b/i', $normalized) === 1;

        return [
            'count_limit' => $this->extractCountLimit($normalized),
            'time_window_hint' => $this->extractTimeWindowHint($normalized),
            'target_entities' => $useSelected ? $selected : [],
        ];
    }

    private function extractCountLimit(string $normalized): int
    {
        if (preg_match('/\b(those|them)\s+(\d+)\b/', $normalized, $matches) === 1) {
            return max(1, min((int) ($matches[2] ?? 3), 10));
        }

        if (preg_match('/\b(top|first|only|limit)\s+(\d+)\b/', $normalized, $matches) === 1) {
            return max(1, min((int) ($matches[2] ?? 3), 10));
        }

        return 3;
    }

    private function extractTimeWindowHint(string $normalized): ?string
    {
        if (str_contains($normalized, 'later afternoon') || str_contains($normalized, 'afternoon')) {
            return 'later_afternoon';
        }
        if (str_contains($normalized, 'morning')) {
            return 'morning';
        }
        if (str_contains($normalized, 'evening') || str_contains($normalized, 'night')) {
            return 'evening';
        }

        return null;
    }
}

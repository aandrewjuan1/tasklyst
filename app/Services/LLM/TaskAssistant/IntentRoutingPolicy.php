<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\TaskAssistantThread;
use App\Services\LLM\Intent\IntentClassificationService;

class IntentRoutingPolicy
{
    public function __construct(
        private readonly IntentClassificationService $intentClassifier,
        private readonly TaskAssistantConversationStateService $conversationState,
    ) {}

    public function decide(TaskAssistantThread $thread, string $content, bool $usePolicyRouting): IntentRoutingDecision
    {
        if (! $usePolicyRouting) {
            return $this->legacyDecision($content);
        }

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

        $scheduleScore = $this->scoreSchedule($normalized);
        $prioritizeScore = $this->scorePrioritize($normalized);
        $chatScore = 0.35;

        $scores = [
            'schedule' => $scheduleScore,
            'prioritize' => $prioritizeScore,
            'chat' => $chatScore,
        ];
        arsort($scores);
        $flow = (string) array_key_first($scores);
        $confidence = (float) $scores[$flow];
        $sortedValues = array_values($scores);
        $margin = ($sortedValues[0] ?? 0.0) - ($sortedValues[1] ?? 0.0);

        $reasonCodes = [$flow.'_signal_detected'];
        if ($margin < 0.15) {
            $reasonCodes[] = 'low_margin_between_flows';
        }

        $executeThreshold = (float) config('task-assistant.routing.execute_threshold', 0.7);
        $clarifyThreshold = (float) config('task-assistant.routing.clarify_threshold', 0.45);
        $clarificationNeeded = $confidence < $executeThreshold && $confidence >= $clarifyThreshold;

        return new IntentRoutingDecision(
            flow: $flow,
            confidence: $confidence,
            reasonCodes: $reasonCodes,
            constraints: $this->buildConstraints($thread, $normalized),
            clarificationNeeded: $clarificationNeeded,
            clarificationQuestion: $clarificationNeeded ? $this->buildClarificationQuestion($flow) : null,
        );
    }

    private function legacyDecision(string $content): IntentRoutingDecision
    {
        $normalized = mb_strtolower(trim($content));
        if ($normalized === '') {
            $flow = 'chat';
        } elseif ($this->intentClassifier->isScheduleLikeRequest($normalized)) {
            $flow = 'schedule';
        } elseif (preg_match('/\b(top|priorit|first|next|important|focus|list.*task|show.*task|which task)\b/i', $normalized) === 1) {
            $flow = 'prioritize';
        } else {
            $flow = 'chat';
        }

        return new IntentRoutingDecision(
            flow: $flow,
            confidence: 1.0,
            reasonCodes: ['legacy_routing'],
            constraints: [],
            clarificationNeeded: false,
            clarificationQuestion: null,
        );
    }

    private function scoreSchedule(string $normalized): float
    {
        $score = 0.0;
        if ($this->intentClassifier->isScheduleLikeRequest($normalized)) {
            $score += 0.7;
        }
        if (preg_match('/\b(afternoon|morning|evening|night|time block|time slot|later)\b/i', $normalized) === 1) {
            $score += 0.2;
        }
        if (preg_match('/\b(those|them|above)\b/i', $normalized) === 1) {
            $score += 0.1;
        }

        return min(1.0, $score);
    }

    private function scorePrioritize(string $normalized): float
    {
        $score = 0.0;
        if (preg_match('/\b(top|priorit|first|next|important|focus|which)\b/i', $normalized) === 1) {
            $score += 0.75;
        }
        if (preg_match('/\b(task|tasks)\b/i', $normalized) === 1) {
            $score += 0.15;
        }
        if (preg_match('/\b(\d+)\b/', $normalized) === 1) {
            $score += 0.05;
        }

        return min(1.0, $score);
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

    private function buildClarificationQuestion(string $flow): string
    {
        return match ($flow) {
            'schedule' => 'Do you want me to build a schedule for selected tasks, or create a fresh plan for your whole day?',
            'prioritize' => 'Should I prioritize your top tasks now, or help schedule them on your calendar?',
            default => 'Do you want prioritization advice, scheduling help, or a general assistant response?',
        };
    }
}

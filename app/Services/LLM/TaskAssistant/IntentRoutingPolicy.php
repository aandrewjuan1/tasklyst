<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\TaskAssistantThread;
use App\Services\LLM\Intent\TaskAssistantIntentInferenceService;
use App\Services\LLM\Intent\TaskAssistantIntentResolutionService;
use App\Services\LLM\Intent\TaskAssistantIntentSignalExtractor;
use Illuminate\Support\Facades\Log;

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
        private readonly TaskAssistantListingReferenceResolver $listingReferenceResolver,
    ) {}

    public function decide(TaskAssistantThread $thread, string $content): IntentRoutingDecision
    {
        $normalized = mb_strtolower(trim($content));
        if ($normalized === '') {
            Log::info('task-assistant.intent.policy', [
                'layer' => 'intent_policy',
                'thread_id' => $thread->id,
                'outcome' => 'empty_message',
                'flow' => 'general_guidance',
            ]);

            return new IntentRoutingDecision(
                flow: 'general_guidance',
                confidence: 1.0,
                reasonCodes: ['empty_message'],
                constraints: [],
                clarificationNeeded: false,
                clarificationQuestion: null,
            );
        }

        if ($this->isLikelyPureGreeting($normalized)) {
            Log::info('task-assistant.intent.policy', [
                'layer' => 'intent_policy',
                'thread_id' => $thread->id,
                'outcome' => 'greeting_shortcircuit_general_guidance',
                'flow' => 'general_guidance',
            ]);

            return new IntentRoutingDecision(
                flow: 'general_guidance',
                confidence: 1.0,
                reasonCodes: ['greeting_shortcircuit_chat'],
                constraints: [],
                clarificationNeeded: false,
                clarificationQuestion: null,
            );
        }

        if ($this->isLikelyGeneralAssistancePrompt($normalized)) {
            Log::info('task-assistant.intent.policy', [
                'layer' => 'intent_policy',
                'thread_id' => $thread->id,
                'outcome' => 'general_guidance_heuristic',
                'flow' => 'general_guidance',
            ]);

            return new IntentRoutingDecision(
                flow: 'general_guidance',
                confidence: 1.0,
                reasonCodes: ['general_guidance_heuristic'],
                constraints: [],
                clarificationNeeded: false,
                clarificationQuestion: null,
            );
        }

        if ($this->isLikelyTimeQuery($normalized)) {
            return new IntentRoutingDecision(
                flow: 'general_guidance',
                confidence: 1.0,
                reasonCodes: ['time_query_heuristic'],
                constraints: [],
                clarificationNeeded: false,
                clarificationQuestion: null,
            );
        }

        $signals = $this->signalExtractor->extract($normalized);

        $inference = null;
        $useLlm = (bool) config('task-assistant.intent.use_llm', true);
        if ($useLlm) {
            $inference = $this->intentInference->infer($content);
        }

        $decision = $this->resolution->resolve($thread, $normalized, $inference, $signals);

        $resolvedFlow = $decision->flow === 'chat' ? 'general_guidance' : $decision->flow;
        $constraints = $this->extractConstraintsForFlow($thread, $normalized, $resolvedFlow);

        Log::info('task-assistant.intent.policy', [
            'layer' => 'intent_policy',
            'thread_id' => $thread->id,
            'outcome' => 'resolved',
            'use_llm' => $useLlm,
            'signals' => $signals,
            'resolved_flow' => $resolvedFlow,
            'confidence' => $decision->confidence,
            'reason_codes' => $decision->reasonCodes,
            'clarification_needed' => $decision->clarificationNeeded,
            'constraints' => [
                'count_limit' => $constraints['count_limit'] ?? null,
                'time_window_hint' => $constraints['time_window_hint'] ?? null,
                'target_entities_count' => is_array($constraints['target_entities'] ?? null)
                    ? count($constraints['target_entities'])
                    : 0,
            ],
            'message_length' => mb_strlen($content),
        ]);

        return new IntentRoutingDecision(
            flow: $resolvedFlow,
            confidence: $decision->confidence,
            reasonCodes: $decision->reasonCodes,
            constraints: $constraints,
            clarificationNeeded: $decision->clarificationNeeded,
            clarificationQuestion: $decision->clarificationQuestion,
        );
    }

    /**
     * Build constraints and (when applicable) reference entities for a specific flow.
     *
     * This is used both for normal routing (`decide()`) and for forced-flow
     * behavior (e.g. clarification answers, general-guidance redirects).
     *
     * @return array<string, mixed>
     */
    public function extractConstraintsForFlow(TaskAssistantThread $thread, string $content, string $resolvedFlow): array
    {
        $normalized = mb_strtolower(trim($content));

        $selected = $this->conversationState->selectedEntities($thread);
        $useSelected = preg_match('/\b(those|them|those\s+\d+|the\s+above)\b/i', $normalized) === 1;

        $targetEntities = [];
        if ($resolvedFlow === 'schedule') {
            // When the most recent flow was `schedule`, resolve those/them against
            // schedule-selected target_entities (not the prior prioritize listing).
            $listing = $this->scheduleAwareLastListing($thread);
            $resolved = $this->listingReferenceResolver->resolveForSchedule($normalized, $listing, $resolvedFlow);
            if ($resolved !== []) {
                $targetEntities = $resolved;
            } elseif ($useSelected && $selected !== []) {
                $targetEntities = $selected;
            }
        } elseif ($useSelected && $selected !== []) {
            $targetEntities = $selected;
        }

        return [
            'count_limit' => $this->extractCountLimit($normalized),
            'time_window_hint' => $this->extractTimeWindowHint($normalized),
            'target_entities' => $targetEntities,
        ];
    }

    /**
     * @return array{
     *   source_flow: string,
     *   items: list<array{entity_type: string, entity_id: int, title: string, position: int}>,
     * }|null
     */
    private function scheduleAwareLastListing(TaskAssistantThread $thread): ?array
    {
        $state = $this->conversationState->get($thread);
        $lastFlow = (string) ($state['last_flow'] ?? '');

        if ($lastFlow === 'schedule') {
            $schedule = $state['last_schedule'] ?? null;
            $targets = is_array($schedule) && is_array($schedule['target_entities'] ?? null)
                ? $schedule['target_entities']
                : [];

            if ($targets !== []) {
                $items = [];
                $position = 0;
                foreach ($targets as $entity) {
                    if (! is_array($entity)) {
                        continue;
                    }

                    $type = (string) ($entity['entity_type'] ?? '');
                    $id = (int) ($entity['entity_id'] ?? 0);
                    $title = (string) ($entity['title'] ?? '');
                    if ($type === '' || $id <= 0 || trim($title) === '') {
                        continue;
                    }

                    $items[] = [
                        'entity_type' => $type,
                        'entity_id' => $id,
                        'title' => $title,
                        'position' => $position,
                    ];
                    $position++;
                }

                if ($items !== []) {
                    return [
                        'source_flow' => 'schedule',
                        'items' => $items,
                    ];
                }
            }
        }

        return $this->conversationState->lastListing($thread);
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

    /**
     * Very short social openers with no task intent — route to chat so prioritize intent inference is not invoked.
     */
    private function isLikelyPureGreeting(string $normalized): bool
    {
        if (mb_strlen($normalized) > 48) {
            return false;
        }

        return (bool) preg_match(
            // Also accept informal openers like "hii yo" and "yo".
            '/^(hi|hii|hello|hey|yo|good morning|good afternoon|good evening|howdy|gm|hiya)(\s+(there|yo))?([!?.]|\s)*$/u',
            $normalized
        );
    }

    private function isLikelyGeneralAssistancePrompt(string $normalized): bool
    {
        // Heuristic for vague, help-seeking prompts. The goal is to reduce "wrong flow" guesses
        // (prioritize vs schedule) when the user does not express a clear intent.
        $helpPatterns = [
            '/\bhelp\b/i',
            '/\bassistance\b/i',
            '/\bcan you help\b/i',
            '/\bi need help\b/i',
            "/\bi['’]m stuck\b/i",
            "/\bi['’]m overwhelmed\b/i",
            '/\boverwhelmed\b/i',
            '/\boverwhelemed\b/i',
            '/\btoo much\b/i',
            '/\bwhere (do i|should i) start\b/i',
            '/\bwhat now\b/i',
            '/\bwhat should i do\b/i',
            '/\bnext step\b/i',
        ];

        foreach ($helpPatterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                // If the message is help-seeking but still clearly asks for what to do
                // first (prioritization), do not short-circuit into general_guidance.
                $signals = $this->signalExtractor->extract($normalized);
                $prio = (float) ($signals['prioritization'] ?? 0.0);
                $sched = (float) ($signals['scheduling'] ?? 0.0);
                // Safe default: when the user is vague/help-seeking, we keep them
                // in general guidance unless the prioritization/scheduling
                // signals are clearly strong.
                $strongIntentThreshold = 0.7;

                if ($prio >= $strongIntentThreshold || $sched >= $strongIntentThreshold) {
                    return false;
                }

                return true;
            }
        }

        return false;
    }

    private function isLikelyTimeQuery(string $normalized): bool
    {
        // Narrow matching to avoid false positives from task/schedule phrases that
        // often contain "today/tomorrow".
        return (bool) preg_match(
            '/\b(current\s+time|time\s+now|time\s+right\s+now|what\s+time\s+is\s+it|what\s*\'?s\s+the\s+time|date\s+today|today\s*\'?s\s+date|what\s+date\s+is\s+it|what\s*\'?s\s+the\s+date)\b/u',
            $normalized
        );
    }
}

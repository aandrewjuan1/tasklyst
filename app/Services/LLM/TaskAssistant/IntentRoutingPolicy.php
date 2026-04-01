<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\TaskAssistantThread;
use App\Services\LLM\Intent\TaskAssistantIntentInferenceService;
use App\Services\LLM\Intent\TaskAssistantIntentResolutionService;
use App\Services\LLM\Intent\TaskAssistantIntentSignalExtractor;
use App\Support\LLM\TaskAssistantWhatToDoFirstIntent;
use Illuminate\Support\Facades\Log;

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
                'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
                'thread_id' => $thread->id,
                'assistant_message_id' => app()->bound('task_assistant.message_id') ? app('task_assistant.message_id') : null,
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
                'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
                'thread_id' => $thread->id,
                'assistant_message_id' => app()->bound('task_assistant.message_id') ? app('task_assistant.message_id') : null,
                'outcome' => 'greeting_shortcircuit_general_guidance',
                'flow' => 'general_guidance',
            ]);

            return new IntentRoutingDecision(
                flow: 'general_guidance',
                confidence: 1.0,
                reasonCodes: ['greeting_shortcircuit_general_guidance', 'general_guidance_greeting_only'],
                constraints: [],
                clarificationNeeded: false,
                clarificationQuestion: null,
            );
        }

        if ($this->isLikelyGibberish($normalized)) {
            Log::info('task-assistant.intent.policy', [
                'layer' => 'intent_policy',
                'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
                'thread_id' => $thread->id,
                'assistant_message_id' => app()->bound('task_assistant.message_id') ? app('task_assistant.message_id') : null,
                'outcome' => 'gibberish_shortcircuit_general_guidance',
                'flow' => 'general_guidance',
            ]);

            return new IntentRoutingDecision(
                flow: 'general_guidance',
                confidence: 1.0,
                reasonCodes: ['gibberish_shortcircuit_general_guidance', 'general_guidance_noisy_unclear'],
                constraints: [],
                clarificationNeeded: false,
                clarificationQuestion: null,
            );
        }

        if ($this->isLikelyDirectPrioritizeFirstPrompt($normalized)) {
            $constraints = $this->extractConstraintsForFlow($thread, $normalized, 'prioritize');

            Log::info('task-assistant.intent.policy', [
                'layer' => 'intent_policy',
                'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
                'thread_id' => $thread->id,
                'assistant_message_id' => app()->bound('task_assistant.message_id') ? app('task_assistant.message_id') : null,
                'outcome' => 'prioritize_first_shortcircuit',
                'flow' => 'prioritize',
                'constraints' => [
                    'count_limit' => $constraints['count_limit'] ?? 1,
                    'time_window_hint' => $constraints['time_window_hint'] ?? null,
                    'target_entities_count' => is_array($constraints['target_entities'] ?? null)
                        ? count($constraints['target_entities'])
                        : 0,
                ],
            ]);

            return new IntentRoutingDecision(
                flow: 'prioritize',
                confidence: 1.0,
                reasonCodes: ['prioritize_first_shortcircuit'],
                constraints: $constraints,
                clarificationNeeded: false,
                clarificationQuestion: null,
            );
        }

        if ($this->hasPendingScheduleDraftContext($thread) && $this->isLikelyScheduleRefinementEditPrompt($normalized)) {
            $constraints = $this->extractConstraintsForFlow($thread, $normalized, 'schedule');
            Log::info('task-assistant.intent.policy', [
                'layer' => 'intent_policy',
                'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
                'thread_id' => $thread->id,
                'assistant_message_id' => app()->bound('task_assistant.message_id') ? app('task_assistant.message_id') : null,
                'outcome' => 'schedule_refinement_context_shortcircuit',
                'flow' => 'schedule',
                'constraints' => [
                    'count_limit' => $constraints['count_limit'] ?? 1,
                    'time_window_hint' => $constraints['time_window_hint'] ?? null,
                    'target_entities_count' => is_array($constraints['target_entities'] ?? null)
                        ? count($constraints['target_entities'])
                        : 0,
                ],
            ]);

            return new IntentRoutingDecision(
                flow: 'schedule',
                confidence: 1.0,
                reasonCodes: ['schedule_refinement_context_shortcircuit'],
                constraints: $constraints,
                clarificationNeeded: false,
                clarificationQuestion: null,
            );
        }

        if ($this->isLikelyGeneralAssistancePrompt($normalized)) {
            Log::info('task-assistant.intent.policy', [
                'layer' => 'intent_policy',
                'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
                'thread_id' => $thread->id,
                'assistant_message_id' => app()->bound('task_assistant.message_id') ? app('task_assistant.message_id') : null,
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

        if ($this->isLikelyOffTopicPrompt($normalized)) {
            Log::info('task-assistant.intent.policy', [
                'layer' => 'intent_policy',
                'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
                'thread_id' => $thread->id,
                'assistant_message_id' => app()->bound('task_assistant.message_id') ? app('task_assistant.message_id') : null,
                'outcome' => 'off_topic_heuristic_general_guidance',
                'flow' => 'general_guidance',
            ]);

            return new IntentRoutingDecision(
                flow: 'general_guidance',
                confidence: 1.0,
                reasonCodes: ['off_topic_heuristic_general_guidance', 'intent_off_topic'],
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
        $constraints = $this->extractConstraintsForFlow($thread, $normalized, $decision->flow);

        Log::info('task-assistant.intent.policy', [
            'layer' => 'intent_policy',
            'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
            'thread_id' => $thread->id,
            'assistant_message_id' => app()->bound('task_assistant.message_id') ? app('task_assistant.message_id') : null,
            'outcome' => 'resolved',
            'use_llm' => $useLlm,
            'signals' => $signals,
            'resolved_flow' => $decision->flow,
            'confidence' => $decision->confidence,
            'reason_codes' => $decision->reasonCodes,
            'clarification_needed' => false,
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
            flow: $decision->flow,
            confidence: $decision->confidence,
            reasonCodes: $decision->reasonCodes,
            constraints: $constraints,
            clarificationNeeded: false,
            clarificationQuestion: null,
        );
    }

    public function extractConstraintsForFlow(TaskAssistantThread $thread, string $content, string $resolvedFlow): array
    {
        $normalized = mb_strtolower(trim($content));

        $selected = $this->conversationState->selectedEntities($thread);
        $useSelected = preg_match('/\b(those|them|those\s+\d+|the\s+above)\b/i', $normalized) === 1;

        $targetEntities = [];
        if ($resolvedFlow === 'schedule') {
            $listing = $this->scheduleAwareLastListing($thread, $normalized);
            $resolved = $this->listingReferenceResolver->resolveForSchedule($normalized, $listing, $resolvedFlow);
            if ($resolved !== []) {
                $targetEntities = $resolved;
            } elseif ($useSelected && $selected !== []) {
                $targetEntities = $selected;
            }
        } elseif ($useSelected && $selected !== []) {
            $targetEntities = $selected;
        }

        $countLimit = $this->extractCountLimit($normalized);
        // If we resolved explicit schedule targets from the user's last ordered listing
        // ("those/the above"/sliced subsets), align how many we schedule with that resolved set.
        // This prevents unintentionally truncating the user's requested batch size.
        if ($resolvedFlow === 'schedule' && $targetEntities !== []) {
            $countLimit = max(1, count($targetEntities));
        }

        return [
            'count_limit' => $countLimit,
            'time_window_hint' => $this->extractTimeWindowHint($normalized),
            'strict_window' => $this->extractStrictWindowFlag($normalized),
            'target_entities' => $targetEntities,
        ];
    }

    private function scheduleAwareLastListing(TaskAssistantThread $thread, string $normalizedContent = ''): ?array
    {
        $state = $this->conversationState->get($thread);
        $lastFlow = (string) ($state['last_flow'] ?? '');
        $lastListing = $this->conversationState->lastListing($thread);

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
                    if ($this->isAllReferenceRequest($normalizedContent) && is_array($lastListing)) {
                        $listingItems = is_array($lastListing['items'] ?? null) ? $lastListing['items'] : [];
                        if (count($listingItems) > count($items)) {
                            return $lastListing;
                        }
                    }

                    return [
                        'source_flow' => 'schedule',
                        'items' => $items,
                    ];
                }
            }
        }

        return $lastListing;
    }

    private function isAllReferenceRequest(string $normalized): bool
    {
        if ($normalized === '') {
            return false;
        }

        return preg_match('/\b(all|them all|those all|all of them|all those)\b/u', $normalized) === 1;
    }

    private function extractCountLimit(string $normalized): int
    {
        if (preg_match('/\b(top|first)\s+(task|item)\b/u', $normalized) === 1) {
            return 1;
        }

        if (preg_match('/\b(those|them)\s+(\d+)\b/', $normalized, $matches) === 1) {
            return max(1, min((int) ($matches[2] ?? 3), 10));
        }

        if (preg_match('/\b(top|first|next|only|limit)\s+(\d+)\b/', $normalized, $matches) === 1) {
            return max(1, min((int) ($matches[2] ?? 3), 10));
        }

        $defaultMulti = (int) config('task-assistant.intent.prioritize_default_multi_count', 3);
        $defaultMulti = max(2, min($defaultMulti, 10));

        if (TaskAssistantWhatToDoFirstIntent::impliesMultiplePrioritizedRows($normalized)) {
            return $defaultMulti;
        }

        if (TaskAssistantWhatToDoFirstIntent::matchesSingleFocusPrioritizeFirst($normalized)) {
            return 1;
        }

        return 3;
    }

    private function extractTimeWindowHint(string $normalized): ?string
    {
        $hasOnwards = preg_match('/\bonward(s)?\b/u', $normalized) === 1;
        $hasAfterMeal = preg_match('/\bafter\s+(lunch|dinner)\b/u', $normalized) === 1;
        $hasExplicitTimeAnchor = preg_match('/\b(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/u', $normalized) === 1;
        $hasAfternoon = str_contains($normalized, 'afternoon');
        $hasEvening = str_contains($normalized, 'evening') || str_contains($normalized, 'night');
        $hasMorning = str_contains($normalized, 'morning');

        if ($hasAfternoon && $hasEvening) {
            return 'afternoon_evening';
        }
        if ($hasMorning && $hasAfternoon) {
            return 'morning_afternoon';
        }
        if ($hasMorning && $hasEvening) {
            return 'morning_evening';
        }

        if ($hasEvening) {
            return 'evening';
        }

        if ($hasAfternoon) {
            if ($hasOnwards) {
                return 'afternoon_onwards';
            }

            return 'later_afternoon';
        }

        if ($hasMorning) {
            if ($hasOnwards) {
                return 'morning_onwards';
            }

            return 'morning';
        }

        if (str_contains($normalized, 'later') || $hasOnwards) {
            return 'later';
        }
        if ($hasAfterMeal || $hasExplicitTimeAnchor) {
            return 'later';
        }

        return null;
    }

    private function extractStrictWindowFlag(string $normalized): bool
    {
        return preg_match('/\bonly\b/u', $normalized) === 1;
    }

    private function isLikelyDirectPrioritizeFirstPrompt(string $normalized): bool
    {
        return TaskAssistantWhatToDoFirstIntent::matches($normalized);
    }

    private function hasPendingScheduleDraftContext(TaskAssistantThread $thread): bool
    {
        $state = $this->conversationState->get($thread);
        $lastFlow = (string) ($state['last_flow'] ?? '');
        $targets = data_get($state, 'last_schedule.target_entities', []);

        return $lastFlow === 'schedule' && is_array($targets) && $targets !== [];
    }

    private function isLikelyScheduleRefinementEditPrompt(string $normalized): bool
    {
        $looksLikeFreshPrioritize = preg_match(
            '/\b(top|priorit(?:y|ize)|what should i do|what are my top|rank|list)\b/u',
            $normalized
        ) === 1;
        if ($looksLikeFreshPrioritize) {
            return false;
        }

        $hasEditVerb = preg_match('/\b(move|set|change|edit|shift|push|swap|reorder|put|make|reschedule|adjust)\b/u', $normalized) === 1;
        $hasScheduleCue = preg_match(
            '/\b(first|second|third|last|item|one|it|this|that|before|after|later|earlier|tomorrow|today|next week|next|at\s+\d{1,2}|am|pm|minute|minutes|duration|shorter|longer)\b/u',
            $normalized
        ) === 1;

        $hasStandaloneReorderShape = preg_match(
            '/\b(move|put|swap)\b[^.]*\b(first|second|third|last|item\s*#?\d+)\b[^.]*\b(to|before|after)\b[^.]*\b(first|second|third|last|item\s*#?\d+)\b/u',
            $normalized
        ) === 1;

        return ($hasEditVerb && $hasScheduleCue) || $hasStandaloneReorderShape;
    }

    private function isLikelyPureGreeting(string $normalized): bool
    {
        if (mb_strlen($normalized) > 48) {
            return false;
        }

        return (bool) preg_match(
            '/^(hi|hii|hello|hey|yo|good morning|good afternoon|good evening|howdy|gm|hiya)(\s+(there|yo))?([!?.]|\s)*$/u',
            $normalized
        );
    }

    private function isLikelyGeneralAssistancePrompt(string $normalized): bool
    {
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
                $signals = $this->signalExtractor->extract($normalized);
                $prio = (float) ($signals['prioritization'] ?? 0.0);
                $sched = (float) ($signals['scheduling'] ?? 0.0);
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
        return (bool) preg_match(
            '/\b(current\s+time|time\s+now|time\s+right\s+now|what\s+time\s+is\s+it|what\s*\'?s\s+the\s+time|date\s+today|today\s*\'?s\s+date|what\s+date\s+is\s+it|what\s*\'?s\s+the\s+date)\b/u',
            $normalized
        );
    }

    private function isLikelyGibberish(string $normalized): bool
    {
        if ($normalized === '') {
            return false;
        }

        $hasTaskKeyword = preg_match('/\b(task|tasks|prioritize|schedule|time block|todo|to do|plan)\b/u', $normalized) === 1;
        if ($hasTaskKeyword) {
            return false;
        }

        $hasStrongOffTopicEntity = preg_match('/\b(president|ufc|fighter|politics|election|nba|movie|celebrity)\b/u', $normalized) === 1;
        if ($hasStrongOffTopicEntity) {
            return false;
        }

        return false;
    }

    private function isLikelyOffTopicPrompt(string $normalized): bool
    {
        if ($normalized === '' || $this->isLikelyTimeQuery($normalized)) {
            return false;
        }

        $taskKeywords = [
            'task', 'tasks', 'prioritize', 'priority', 'schedule', 'time block', 'time blocks',
            'calendar', 'study', 'deadline', 'project', 'focus', 'plan my day', 'to do', 'todo',
        ];
        foreach ($taskKeywords as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return false;
            }
        }

        $offTopicMarkers = [
            'best ', 'who is', 'why he', 'why she', 'relationship', 'politics', 'president',
            'shoes', 'cook', 'martial artist', 'love me', 'keyboard', 'laptop', 'phone',
        ];
        foreach ($offTopicMarkers as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }
}

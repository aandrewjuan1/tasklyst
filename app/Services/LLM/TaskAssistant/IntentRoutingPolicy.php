<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\TaskAssistantThread;
use App\Services\LLM\Intent\TaskAssistantIntentHybridCue;
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

        if ($this->isLikelyPrioritizeScheduleCombinedPrompt($normalized)) {
            $constraints = $this->extractConstraintsForFlow($thread, $normalized, 'prioritize_schedule');

            Log::info('task-assistant.intent.policy', [
                'layer' => 'intent_policy',
                'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
                'thread_id' => $thread->id,
                'assistant_message_id' => app()->bound('task_assistant.message_id') ? app('task_assistant.message_id') : null,
                'outcome' => 'prioritize_schedule_combined_prompt_shortcircuit',
                'flow' => 'prioritize_schedule',
                'constraints' => [
                    'count_limit' => $constraints['count_limit'] ?? 1,
                    'time_window_hint' => $constraints['time_window_hint'] ?? null,
                    'target_entities_count' => is_array($constraints['target_entities'] ?? null)
                        ? count($constraints['target_entities'])
                        : 0,
                ],
            ]);

            return new IntentRoutingDecision(
                flow: 'prioritize_schedule',
                confidence: 1.0,
                reasonCodes: ['prioritize_schedule_combined_prompt'],
                constraints: $constraints,
                clarificationNeeded: false,
                clarificationQuestion: null,
            );
        }

        if ($this->isLikelyFreshDayPlanningPrompt($normalized)) {
            $constraints = $this->extractConstraintsForFlow($thread, $normalized, 'prioritize_schedule');

            Log::info('task-assistant.intent.policy', [
                'layer' => 'intent_policy',
                'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
                'thread_id' => $thread->id,
                'assistant_message_id' => app()->bound('task_assistant.message_id') ? app('task_assistant.message_id') : null,
                'outcome' => 'fresh_day_planning_shortcircuit',
                'flow' => 'prioritize_schedule',
                'constraints' => [
                    'count_limit' => $constraints['count_limit'] ?? 1,
                    'time_window_hint' => $constraints['time_window_hint'] ?? null,
                    'target_entities_count' => is_array($constraints['target_entities'] ?? null)
                        ? count($constraints['target_entities'])
                        : 0,
                ],
            ]);

            return new IntentRoutingDecision(
                flow: 'prioritize_schedule',
                confidence: 1.0,
                reasonCodes: ['fresh_day_planning_prioritize_schedule'],
                constraints: $constraints,
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

        if ($this->hasMultiturnListingFollowupContext($thread) && $this->isLikelyListingFollowupQuestion($normalized)) {
            $constraints = $this->extractConstraintsForFlow($thread, $content, 'listing_followup');
            Log::info('task-assistant.intent.policy', [
                'layer' => 'intent_policy',
                'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
                'thread_id' => $thread->id,
                'assistant_message_id' => app()->bound('task_assistant.message_id') ? app('task_assistant.message_id') : null,
                'outcome' => 'listing_followup_shortcircuit',
                'flow' => 'listing_followup',
                'constraints' => [
                    'count_limit' => $constraints['count_limit'] ?? 1,
                    'time_window_hint' => $constraints['time_window_hint'] ?? null,
                    'target_entities_count' => is_array($constraints['target_entities'] ?? null)
                        ? count($constraints['target_entities'])
                        : 0,
                ],
            ]);

            return new IntentRoutingDecision(
                flow: 'listing_followup',
                confidence: 1.0,
                reasonCodes: ['followup_listing_question_shortcircuit'],
                constraints: $constraints,
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
        $listing = null;
        if ($resolvedFlow === 'schedule' || $resolvedFlow === 'prioritize_schedule' || $resolvedFlow === 'listing_followup') {
            $listing = $this->scheduleAwareLastListing($thread, $normalized);
            $referenceFlow = ($resolvedFlow === 'prioritize_schedule' || $resolvedFlow === 'listing_followup')
                ? 'schedule'
                : $resolvedFlow;
            $resolved = $this->listingReferenceResolver->resolveForSchedule($normalized, $listing, $referenceFlow);
            if ($resolved !== []) {
                $targetEntities = $resolved;
            } elseif ($useSelected && $selected !== []) {
                $targetEntities = $selected;
            }
        } elseif ($useSelected && $selected !== []) {
            $targetEntities = $selected;
        }

        $countLimit = $this->extractCountLimit($normalized);

        if ($resolvedFlow === 'listing_followup' && $targetEntities === [] && is_array($listing)) {
            $targetEntities = $this->targetsFromListingHead($listing, $countLimit);
        }

        // If we resolved explicit schedule targets from the user's last ordered listing
        // ("those/the above"/sliced subsets), align how many we schedule with that resolved set.
        // This prevents unintentionally truncating the user's requested batch size.
        if (($resolvedFlow === 'schedule' || $resolvedFlow === 'prioritize_schedule' || $resolvedFlow === 'listing_followup') && $targetEntities !== []) {
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

        // Word-based ordinal singles like:
        // - "schedule only the first one"
        // - "put last one in the evening"
        if (preg_match(
            '/\b(?:schedule|put|plan)\b.{0,40}\b(only\s+)?(?:the\s+)?(first|top|last|bottom)\b.{0,40}\bone\b/iu',
            $normalized
        ) === 1) {
            return 1;
        }

        $wordNumbers = [
            'one' => 1,
            'two' => 2,
            'three' => 3,
            'four' => 4,
            'five' => 5,
            'six' => 6,
            'seven' => 7,
            'eight' => 8,
            'nine' => 9,
            'ten' => 10,
            'couple' => 2,
        ];

        $parseToken = static function (string $token) use ($wordNumbers): ?int {
            $t = mb_strtolower(trim($token));
            if ($t === '') {
                return null;
            }

            if (preg_match('/^\d+$/u', $t) === 1) {
                return max(1, (int) $t);
            }

            if (array_key_exists($t, $wordNumbers)) {
                return $wordNumbers[$t];
            }

            return null;
        };

        // Typed counts adjacent to task/item words, e.g.:
        // - "schedule two tasks for later"
        // - "plan 3 items"
        if (preg_match(
            '/\b(\d+|one|two|three|four|five|six|seven|eight|nine|ten|couple)\b\s+(tasks?|task|items?|item)\b/iu',
            $normalized,
            $matches
        ) === 1) {
            $n = $parseToken((string) ($matches[1] ?? ''));
            if ($n !== null) {
                return max(1, min($n, 10));
            }
        }

        if (preg_match('/\b(those|them)\s+(\d+|one|two|three|four|five|six|seven|eight|nine|ten|couple)\b/iu', $normalized, $matches) === 1) {
            $n = $parseToken((string) ($matches[2] ?? ''));
            if ($n !== null) {
                return max(1, min($n, 10));
            }
        }

        if (preg_match('/\b(top|first|next|only|limit)\s+(\d+|one|two|three|four|five|six|seven|eight|nine|ten|couple)\b/iu', $normalized, $matches) === 1) {
            $n = $parseToken((string) ($matches[2] ?? ''));
            if ($n !== null) {
                return max(1, min($n, 10));
            }
        }

        if ($this->isSingleFocusRequest($normalized)) {
            return 1;
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

    private function isSingleFocusRequest(string $normalized): bool
    {
        if ($normalized === '') {
            return false;
        }

        if (preg_match('/\b(tasks|items)\b/u', $normalized) === 1) {
            return false;
        }

        if (preg_match('/\b(\d+|two|three|four|five|six|seven|eight|nine|ten|couple)\b/u', $normalized) === 1) {
            return false;
        }

        return preg_match(
            '/\b(my\s+)?(most\s+important|top|highest\s+priority|most\s+urgent|main|single)\s+(task|item)\b/u',
            $normalized
        ) === 1;
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

    private function isLikelyPrioritizeScheduleCombinedPrompt(string $normalized): bool
    {
        if ($this->isLikelyScheduleRefinementEditPrompt($normalized)) {
            return false;
        }

        $normalized = TaskAssistantIntentHybridCue::normalizeForSignals($normalized);

        return TaskAssistantIntentHybridCue::matchesCombinedPrioritizeSchedulePrompt($normalized);
    }

    private function isLikelyFreshDayPlanningPrompt(string $normalized): bool
    {
        if ($normalized === '') {
            return false;
        }

        if ($this->isLikelyScheduleRefinementEditPrompt($normalized)) {
            return false;
        }

        return preg_match(
            '/\b(plan|schedule|organi[sz]e|organize|map\s+out|line\s+up)\b.{0,45}\b(my|the)\s+(whole\s+)?day\b/u',
            $normalized
        ) === 1
            || preg_match('/\b(plan|schedule)\b.{0,45}\b(all|everything)\b.{0,30}\b(tasks?|important|priority)\b/u', $normalized) === 1
            || preg_match('/\b(plan|schedule)\b.{0,45}\b(important|priority|urgent)\b.{0,30}\b(tasks?)\b/u', $normalized) === 1;
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

        $hasEditVerb = preg_match('/\b(move|set|change|edit|shift|push|swap|reorder|put|make|reschedule|adjust|bring|bump|drag|slide|delay|advance|pull|drop)\b/u', $normalized) === 1;
        $hasScheduleCue = preg_match(
            '/\b(first|second|third|last|\d+(?:st|nd|rd|th)|item|task|one|it|this|that|same one|before|after|later|earlier|tomorrow|today|tmrw|tomorow|next week|next|at\s+\d{1,2}|am|pm|minute|minutes|duration|shorter|longer)\b/u',
            $normalized
        ) === 1;

        $hasStandaloneReorderShape = preg_match(
            '/\b(move|put|swap)\b[^.]*\b(first|second|third|last|item\s*#?\d+)\b[^.]*\b(to|before|after)\b[^.]*\b(first|second|third|last|item\s*#?\d+)\b/u',
            $normalized
        ) === 1;

        $implicitEditPhrase = preg_match(
            '/\b(first|second|third|last|\d+(?:st|nd|rd|th)|#\d+|item\s*#?\d+|task\s*#?\d+)\b.{0,40}\b(instead|please|at|for|to|on)\b.{0,60}\b(morning|afternoon|evening|night|today|tomorrow|tmrw|\d{1,2}(:\d{2})?\s*(am|pm)?)\b/u',
            $normalized
        ) === 1;

        $hasDoIndexedSchedulingPhrase = preg_match(
            '/\bdo\b.{0,16}\b(the\s+)?(first|second|third|last|\d+(?:st|nd|rd|th)|one)\b.{0,36}\b(later|today|tomorrow|morning|afternoon|evening|night|tonight)\b/u',
            $normalized
        ) === 1;

        return ($hasEditVerb && $hasScheduleCue) || $hasStandaloneReorderShape || $implicitEditPhrase || $hasDoIndexedSchedulingPhrase;
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

        $hasTaskKeyword = preg_match('/\b(task|tasks|prioritize|priority|schedule|reschedule|time block|calendar|todo|to do|plan|deadline|study|project)\b/u', $normalized) === 1;
        if ($hasTaskKeyword) {
            return false;
        }

        // Keep clear off-topic prompts on the off-topic branch, not gibberish.
        $hasReadableOffTopicPrompt = preg_match('/\b(best|who is|why|relationship|politics|president|movie|laptop|phone)\b/u', $normalized) === 1;
        if ($hasReadableOffTopicPrompt) {
            return false;
        }

        $letters = preg_match_all('/\pL/u', $normalized);
        $numbers = preg_match_all('/\pN/u', $normalized);
        $symbols = preg_match_all('/[^\pL\pN\s]/u', $normalized);
        $length = max(1, mb_strlen($normalized));

        $alphaNumericRatio = ($letters + $numbers) / $length;
        $symbolRatio = $symbols / $length;

        // Lots of symbols and too little language content.
        if ($symbolRatio >= 0.35 && $alphaNumericRatio < 0.65) {
            return true;
        }

        // Repeated character bursts like "aaaaaa", "??????", "zzzzzzzz".
        if (preg_match('/(.)\1{5,}/u', $normalized) === 1) {
            return true;
        }

        // Token-level nonsense: many tiny fragments with little lexical structure.
        $tokens = preg_split('/\s+/u', trim($normalized), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return true;
        }

        $shortTokenCount = 0;
        foreach ($tokens as $token) {
            $clean = preg_replace('/[^\pL\pN]/u', '', $token) ?? '';
            if ($clean !== '' && mb_strlen($clean) <= 2) {
                $shortTokenCount++;
            }
        }

        if (count($tokens) >= 4 && $shortTokenCount / count($tokens) >= 0.75) {
            return true;
        }

        // Dense random-ish mixtures like "asdj12 qwe!! zx9".
        if ($alphaNumericRatio < 0.55 && preg_match('/[a-z]{2,}\d+|\d+[a-z]{2,}/iu', $normalized) === 1) {
            return true;
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

    private function hasMultiturnListingFollowupContext(TaskAssistantThread $thread): bool
    {
        if ($this->conversationState->lastListing($thread) !== null) {
            return true;
        }

        $state = $this->conversationState->get($thread);
        if ((string) ($state['last_flow'] ?? '') !== 'schedule') {
            return false;
        }

        $targets = data_get($state, 'last_schedule.target_entities', []);

        return is_array($targets) && $targets !== [];
    }

    private function isLikelyListingFollowupQuestion(string $normalized): bool
    {
        $trimmed = trim($normalized);
        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/\b(schedule|reschedule|block\s+time|put\s+on\s+my\s+calendar)\b/u', $trimmed) === 1
            && ! str_ends_with($trimmed, '?')) {
            return false;
        }

        $questionShape = str_ends_with($trimmed, '?')
            || preg_match('/^(are|is|was|were|do|does|did|why|what\s+about|should\s+i|would\s+that|can\s+you\s+explain|tell\s+me)\b/u', $trimmed) === 1;

        if (! $questionShape) {
            return false;
        }

        return preg_match(
            '/\b(those|these|that|this|them|two|three|both|most\s+urgent|urgent|right\s+order|correct|make\s+sense|trust|sure|really)\b/u',
            $trimmed
        ) === 1;
    }

    /**
     * @param  array{source_flow: string, items: list<array<string, mixed>>, assistant_message_id?: int|null, last_limit?: int}  $listing
     * @return list<array{entity_type: string, entity_id: int, title: string, position: int}>
     */
    private function targetsFromListingHead(array $listing, int $limit): array
    {
        $items = $listing['items'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        $limit = max(1, min($limit, 10));
        $out = [];
        $position = 0;

        foreach (array_slice($items, 0, $limit) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = trim((string) ($row['entity_type'] ?? ''));
            $id = (int) ($row['entity_id'] ?? 0);
            $title = trim((string) ($row['title'] ?? ''));
            if ($type === '' || $id <= 0 || $title === '') {
                continue;
            }

            $out[] = [
                'entity_type' => $type,
                'entity_id' => $id,
                'title' => $title,
                'position' => $position,
            ];
            $position++;
        }

        return $out;
    }
}

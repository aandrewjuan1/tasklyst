<?php

namespace App\Services\LLM\Intent;

use App\Enums\TaskAssistantUserIntent;
use App\Models\TaskAssistantThread;
use App\Services\LLM\TaskAssistant\IntentRoutingDecision;
use App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService;
use Illuminate\Support\Facades\Log;

/**
 * Merges LLM intent inference with heuristic signals; may fall back to general guidance or clarification.
 */
final class TaskAssistantIntentResolutionService
{
    public function __construct(
        private readonly TaskAssistantConversationStateService $conversationState,
    ) {}

    /**
     * @param  array{prioritization: float, scheduling: float, hybrid?: float}  $signals
     */
    public function resolve(
        TaskAssistantThread $thread,
        string $normalized,
        ?TaskAssistantIntentInferenceResult $inference,
        array $signals,
    ): IntentRoutingDecision {
        // Deterministic-first runtime path: if no inference payload is provided,
        // route strictly from heuristic signals regardless of config flags.
        if ($inference === null) {
            return $this->resolveSignalOnly($thread, $signals);
        }

        Log::debug('task-assistant.intent_resolution.begin', [
            'layer' => 'intent_resolution',
            'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
            'thread_id' => $thread->id,
            'assistant_message_id' => app()->bound('task_assistant.message_id') ? app('task_assistant.message_id') : null,
            'use_llm' => (bool) config('task-assistant.intent.use_llm', true),
            'normalized_length' => mb_strlen($normalized),
            'signals' => $signals,
        ]);

        $useLlm = (bool) config('task-assistant.intent.use_llm', true);

        if (! $useLlm) {
            return $this->resolveSignalOnly($thread, $signals);
        }

        if ($inference->connectionFailed) {
            $signalDecision = $this->resolveSignalOnly($thread, $signals);
            $mergedCodes = array_values(array_unique(array_merge(
                ['intent_llm_unavailable_signal_fallback'],
                $signalDecision->reasonCodes,
            )));

            return new IntentRoutingDecision(
                flow: $signalDecision->flow,
                confidence: $signalDecision->confidence,
                reasonCodes: $mergedCodes,
                constraints: $signalDecision->constraints,
                clarificationNeeded: $signalDecision->clarificationNeeded,
                clarificationQuestion: $signalDecision->clarificationQuestion,
            );
        }

        if ($inference->failed || $inference->intent === null) {
            $signalDecision = $this->resolveSignalOnly($thread, $signals);
            $mergedCodes = array_values(array_unique(array_merge(
                ['intent_llm_failed_signal_fallback'],
                $signalDecision->reasonCodes,
            )));

            return new IntentRoutingDecision(
                flow: $signalDecision->flow,
                confidence: $signalDecision->confidence,
                reasonCodes: $mergedCodes,
                constraints: $signalDecision->constraints,
                clarificationNeeded: $signalDecision->clarificationNeeded,
                clarificationQuestion: $signalDecision->clarificationQuestion,
            );
        }

        $hybridNormalized = TaskAssistantIntentHybridCue::normalizeForSignals($normalized);
        if (TaskAssistantIntentHybridCue::matchesCombinedPrioritizeSchedulePrompt($hybridNormalized)
            && ($inference->intent === TaskAssistantUserIntent::Prioritization
                || $inference->intent === TaskAssistantUserIntent::Scheduling)) {
            $llmConf = max(0.0, min(1.0, $inference->confidence));
            $prioritizeSignal = (float) ($signals['prioritization'] ?? 0.0);
            $scheduleSignal = (float) ($signals['scheduling'] ?? 0.0);
            $mergedConfidence = max(
                $llmConf,
                min(1.0, ($prioritizeSignal + $scheduleSignal) / 2)
            );
            $overrideCode = $inference->intent === TaskAssistantUserIntent::Scheduling
                ? 'intent_llm_scheduling_combined_prompt_override'
                : 'intent_llm_prioritization_combined_prompt_override';
            $codes = [
                $overrideCode,
                'intent_merge_prioritize_schedule_composite',
            ];

            $this->logResolution(
                $thread,
                $inference->intent->value,
                $llmConf,
                $signals,
                'prioritize_schedule',
                $codes,
                false,
                [
                    'prioritize_schedule' => $mergedConfidence,
                    'prioritize' => $prioritizeSignal,
                    'schedule' => $scheduleSignal,
                ]
            );

            return new IntentRoutingDecision(
                flow: 'prioritize_schedule',
                confidence: $mergedConfidence,
                reasonCodes: $codes,
                constraints: [],
                clarificationNeeded: false,
                clarificationQuestion: null,
            );
        }

        $llmIntent = $inference->intent;
        $llmConf = max(0.0, min(1.0, $inference->confidence));

        $reasonCodes = ['llm_intent_'.$llmIntent->value];
        $prioritizeSignal = (float) ($signals['prioritization'] ?? 0.0);
        $scheduleSignal = (float) ($signals['scheduling'] ?? 0.0);
        $signalOverrideThreshold = (float) config('task-assistant.intent.merge.validator_override_signal_min', 0.72);

        // LLM can directly classify the unified flow (rank top tasks + schedule them).
        if ($llmIntent === TaskAssistantUserIntent::PrioritizeSchedule) {
            $minScheduleSignal = (float) config('task-assistant.intent.merge.prioritize_schedule_min_schedule_signal', 0.35);
            $hybridNorm = TaskAssistantIntentHybridCue::normalizeForSignals($normalized);
            if ($scheduleSignal < $minScheduleSignal
                && $prioritizeSignal >= $scheduleSignal
                && ! TaskAssistantIntentHybridCue::matchesCombinedPrioritizeSchedulePrompt($hybridNorm)
            ) {
                $demoteCodes = array_values(array_unique(array_merge(
                    $reasonCodes,
                    ['prioritize_schedule_demoted_weak_schedule_signal']
                )));

                $this->logResolution(
                    $thread,
                    $llmIntent->value,
                    $llmConf,
                    $signals,
                    'prioritize',
                    $demoteCodes,
                    false,
                    [
                        'prioritize_schedule' => $llmConf,
                        'prioritize' => $prioritizeSignal,
                        'schedule' => $scheduleSignal,
                    ]
                );

                return new IntentRoutingDecision(
                    flow: 'prioritize',
                    confidence: max($llmConf, $prioritizeSignal),
                    reasonCodes: $demoteCodes,
                    constraints: [],
                    clarificationNeeded: false,
                    clarificationQuestion: null,
                );
            }

            $mergedConfidence = max(
                $llmConf,
                min(1.0, ($prioritizeSignal + $scheduleSignal) / 2)
            );

            $codes = array_values(array_unique(array_merge(
                $reasonCodes,
                ['intent_llm_prioritize_schedule_composite']
            )));

            $wSig = (float) config('task-assistant.intent.merge.signal_weight', 0.5);
            $signalHybrid = (float) ($signals['hybrid'] ?? 0.0);

            $this->logResolution(
                $thread,
                $llmIntent->value,
                $llmConf,
                $signals,
                'prioritize_schedule',
                $codes,
                false,
                [
                    'prioritize_schedule' => $mergedConfidence,
                    'prioritize' => $wSig * $prioritizeSignal,
                    'schedule' => $wSig * $scheduleSignal,
                    'hybrid_signal' => $signalHybrid,
                ]
            );

            return new IntentRoutingDecision(
                flow: 'prioritize_schedule',
                confidence: $mergedConfidence,
                reasonCodes: $codes,
                constraints: [],
                clarificationNeeded: false,
                clarificationQuestion: null,
            );
        }

        if ($llmIntent === TaskAssistantUserIntent::ListingFollowup) {
            if (! $this->hasListingFollowupContext($thread)) {
                $codes = array_values(array_unique(array_merge(
                    $reasonCodes,
                    ['intent_llm_listing_followup_missing_context_clarify']
                )));

                $this->logResolution(
                    $thread,
                    $llmIntent->value,
                    $llmConf,
                    $signals,
                    'general_guidance',
                    $codes,
                    false,
                    null
                );

                return new IntentRoutingDecision(
                    flow: 'general_guidance',
                    confidence: max($llmConf, 0.6),
                    reasonCodes: $codes,
                    constraints: [],
                    clarificationNeeded: false,
                    clarificationQuestion: null,
                );
            }

            $codes = array_values(array_unique(array_merge(
                $reasonCodes,
                ['intent_llm_listing_followup']
            )));

            $this->logResolution(
                $thread,
                $llmIntent->value,
                $llmConf,
                $signals,
                'listing_followup',
                $codes,
                false,
                null
            );

            return new IntentRoutingDecision(
                flow: 'listing_followup',
                confidence: max($llmConf, $prioritizeSignal),
                reasonCodes: $codes,
                constraints: [],
                clarificationNeeded: false,
                clarificationQuestion: null,
            );
        }

        if ($llmIntent === TaskAssistantUserIntent::Greeting || $llmIntent === TaskAssistantUserIntent::GeneralGuidance) {
            // If the model says "general guidance" but signals are clearly prioritize/schedule,
            // prefer the deterministic signal route to avoid obvious misclassification.
            if ($llmIntent === TaskAssistantUserIntent::GeneralGuidance) {
                if ($prioritizeSignal >= $signalOverrideThreshold && $prioritizeSignal > $scheduleSignal) {
                    $codes = array_values(array_unique(array_merge($reasonCodes, ['intent_general_guidance_overridden_by_signal_prioritize'])));
                    $this->logResolution(
                        $thread,
                        $llmIntent->value,
                        $llmConf,
                        $signals,
                        'prioritize',
                        $codes,
                        false,
                        null
                    );

                    return new IntentRoutingDecision(
                        flow: 'prioritize',
                        confidence: $prioritizeSignal,
                        reasonCodes: $codes,
                        constraints: [],
                        clarificationNeeded: false,
                        clarificationQuestion: null,
                    );
                }

                if ($scheduleSignal >= $signalOverrideThreshold && $scheduleSignal > $prioritizeSignal) {
                    $codes = array_values(array_unique(array_merge($reasonCodes, ['intent_general_guidance_overridden_by_signal_schedule'])));
                    $this->logResolution(
                        $thread,
                        $llmIntent->value,
                        $llmConf,
                        $signals,
                        'schedule',
                        $codes,
                        false,
                        null
                    );

                    return new IntentRoutingDecision(
                        flow: 'schedule',
                        confidence: $scheduleSignal,
                        reasonCodes: $codes,
                        constraints: [],
                        clarificationNeeded: false,
                        clarificationQuestion: null,
                    );
                }
            }

            $this->logResolution(
                $thread,
                $llmIntent->value,
                $llmConf,
                $signals,
                'general_guidance',
                array_values(array_unique(array_merge($reasonCodes, ['intent_general_guidance']))),
                false,
                null
            );

            return new IntentRoutingDecision(
                flow: 'general_guidance',
                confidence: $llmConf,
                reasonCodes: array_values(array_unique(array_merge($reasonCodes, ['intent_general_guidance']))),
                constraints: [],
                clarificationNeeded: false,
                clarificationQuestion: null,
            );
        }

        if ($llmIntent === TaskAssistantUserIntent::Unclear) {
            $this->logResolution(
                $thread,
                $llmIntent->value,
                $llmConf,
                $signals,
                'general_guidance',
                array_values(array_unique(array_merge($reasonCodes, ['intent_unclear']))),
                false,
                null
            );

            return new IntentRoutingDecision(
                flow: 'general_guidance',
                confidence: $llmConf,
                reasonCodes: array_values(array_unique(array_merge($reasonCodes, ['intent_unclear']))),
                constraints: [],
                clarificationNeeded: false,
                clarificationQuestion: null,
            );
        }

        if ($llmIntent === TaskAssistantUserIntent::OffTopic) {
            // Off-topic should always take the general-guidance guardrail path so
            // we never "helpfully answer" unrelated questions (e.g. product recs).
            // Confidence can be low, but the policy should still protect scope.
            $reasonCodes = array_values(array_unique(array_merge($reasonCodes, ['intent_off_topic'])));

            $this->logResolution(
                $thread,
                $llmIntent->value,
                $llmConf,
                $signals,
                'general_guidance',
                $reasonCodes,
                false,
                null
            );

            return new IntentRoutingDecision(
                flow: 'general_guidance',
                confidence: $llmConf,
                reasonCodes: $reasonCodes,
                constraints: [],
                clarificationNeeded: false,
                clarificationQuestion: null,
            );
        }

        $strongestSignalKey = $this->strongestSignalKey($signals);
        $strongestScore = $signals[$strongestSignalKey] ?? 0.0;

        $overrideThreshold = (float) config('task-assistant.intent.merge.validator_override_signal_min', 0.72);
        $llmWeakBelow = (float) config('task-assistant.intent.merge.llm_weak_below', 0.55);

        $effectiveIntent = $llmIntent;
        if ($strongestScore >= $overrideThreshold
            && $llmConf < $llmWeakBelow
        ) {
            $strongestIntent = $this->signalKeyToIntent($strongestSignalKey);
            if ($strongestIntent !== null && $strongestIntent !== $llmIntent) {
                $effectiveIntent = $strongestIntent;
                $reasonCodes[] = 'validator_override_signal';
            }
        }

        $compositeScores = $this->compositeScores($signals, $effectiveIntent, $llmConf);
        arsort($compositeScores);
        $sorted = array_values($compositeScores);
        $topComposite = $sorted[0] ?? 0.0;
        $secondComposite = $sorted[1] ?? 0.0;
        $margin = $topComposite - $secondComposite;

        $pComp = (float) ($compositeScores['prioritize'] ?? 0.0);
        $sComp = (float) ($compositeScores['schedule'] ?? 0.0);
        $hybridComposite = (float) ($compositeScores['prioritize_schedule'] ?? 0.0);
        $topPairComposite = max($pComp, $sComp);
        $secondPairComposite = min($pComp, $sComp);
        $prioritizeScheduleMargin = $topPairComposite - $secondPairComposite;

        $weakMax = (float) config('task-assistant.intent.merge.weak_composite_max', 0.38);
        $clarifyMargin = (float) config('task-assistant.intent.merge.clarify_margin', 0.12);
        $clarifyCeiling = (float) config('task-assistant.intent.merge.clarify_composite_ceiling', 0.55);

        if ($topComposite < $weakMax && $llmConf < 0.45 && $strongestScore < 0.35) {
            $reasonCodes[] = 'validator_fallback_general';
            $this->logResolution($thread, $llmIntent->value, $llmConf, $signals, 'general_guidance', $reasonCodes, false, $compositeScores);

            return new IntentRoutingDecision(
                flow: 'general_guidance',
                confidence: $topComposite,
                reasonCodes: $reasonCodes,
                constraints: [],
                clarificationNeeded: false,
                clarificationQuestion: null,
            );
        }

        $finalFlow = (string) array_key_first($compositeScores);
        $clarificationNeeded = $margin < $clarifyMargin && $topComposite < $clarifyCeiling;

        // Confidence gap hardening (prioritize vs schedule composites only — ignore third flow).
        // If those two are close, prefer general guidance unless hybrid composite can resolve the tie.
        $ambiguityGapMin = (float) config('task-assistant.intent.merge.ambiguity_gap_min', 0.15);
        $ambiguitySecondCompositeMin = (float) config('task-assistant.intent.merge.ambiguity_second_composite_min', 0.12);
        $ambiguityTopCompositeMax = (float) config('task-assistant.intent.merge.ambiguity_top_composite_max', 0.65);
        $hybridAmbiguityMin = (float) config('task-assistant.intent.merge.hybrid_ambiguity_resolution_min', 0.47);

        $confidenceGapAmbiguous = $prioritizeScheduleMargin < $ambiguityGapMin
            && $secondPairComposite >= $ambiguitySecondCompositeMin
            && $topPairComposite <= $ambiguityTopCompositeMax;

        if ($confidenceGapAmbiguous) {
            if ($hybridComposite >= $hybridAmbiguityMin) {
                $reasonCodes[] = 'hybrid_resolves_prioritize_schedule_ambiguity';
                $reasonCodes[] = 'validator_merged';

                $this->logResolution(
                    $thread,
                    $llmIntent->value,
                    $llmConf,
                    $signals,
                    'prioritize_schedule',
                    array_values(array_unique($reasonCodes)),
                    false,
                    $compositeScores
                );

                return new IntentRoutingDecision(
                    flow: 'prioritize_schedule',
                    confidence: $hybridComposite,
                    reasonCodes: array_values(array_unique($reasonCodes)),
                    constraints: [],
                    clarificationNeeded: false,
                    clarificationQuestion: null,
                );
            }

            $reasonCodes[] = 'confidence_gap_ambiguous_general_guidance';
            $this->logResolution(
                $thread,
                $llmIntent->value,
                $llmConf,
                $signals,
                'general_guidance',
                $reasonCodes,
                false,
                $compositeScores
            );

            return new IntentRoutingDecision(
                flow: 'general_guidance',
                confidence: $topComposite,
                reasonCodes: array_values(array_unique($reasonCodes)),
                constraints: [],
                clarificationNeeded: false,
                clarificationQuestion: null,
            );
        }

        $hybridDualSignalMin = (float) config('task-assistant.intent.merge.hybrid_dual_signal_min', 0.5);
        if (($finalFlow === 'prioritize' || $finalFlow === 'schedule')
            && $prioritizeSignal >= $hybridDualSignalMin
            && $scheduleSignal >= $hybridDualSignalMin
            && $hybridComposite >= $hybridAmbiguityMin
        ) {
            $reasonCodes[] = 'hybrid_promoted_dual_signal';
            $reasonCodes[] = 'validator_merged';

            $this->logResolution(
                $thread,
                $llmIntent->value,
                $llmConf,
                $signals,
                'prioritize_schedule',
                array_values(array_unique($reasonCodes)),
                false,
                $compositeScores
            );

            return new IntentRoutingDecision(
                flow: 'prioritize_schedule',
                confidence: $hybridComposite,
                reasonCodes: array_values(array_unique($reasonCodes)),
                constraints: [],
                clarificationNeeded: false,
                clarificationQuestion: null,
            );
        }

        if ($clarificationNeeded) {
            // Clarification is no longer a separate branch; fall back to general guidance
            // and let the general flow ask the user to pick prioritize vs schedule.
            $reasonCodes[] = 'low_margin_intent_general_guidance';
            $this->logResolution($thread, $llmIntent->value, $llmConf, $signals, 'general_guidance', $reasonCodes, false, $compositeScores);

            return new IntentRoutingDecision(
                flow: 'general_guidance',
                confidence: $topComposite,
                reasonCodes: $reasonCodes,
                constraints: [],
                clarificationNeeded: false,
                clarificationQuestion: null,
            );
        }

        $reasonCodes[] = 'validator_merged';

        if ($finalFlow === 'prioritize_schedule') {
            $reasonCodes[] = 'validator_merge_prioritize_schedule';
        }

        $this->logResolution($thread, $llmIntent->value, $llmConf, $signals, $finalFlow, $reasonCodes, false, $compositeScores);

        return new IntentRoutingDecision(
            flow: $finalFlow,
            confidence: $topComposite,
            reasonCodes: $reasonCodes,
            constraints: [],
            clarificationNeeded: false,
            clarificationQuestion: null,
        );
    }

    /**
     * @param  array{prioritization: float, scheduling: float, hybrid?: float}  $signals
     */
    private function resolveSignalOnly(
        TaskAssistantThread $thread,
        array $signals,
    ): IntentRoutingDecision {
        $weakThreshold = (float) config('task-assistant.intent.merge.signal_only_weak_max', 0.35);
        $clarifyMargin = (float) config('task-assistant.intent.merge.signal_only_clarify_margin', 0.15);

        $flowScores = [
            'prioritize' => (float) ($signals['prioritization'] ?? 0.0),
            'schedule' => (float) ($signals['scheduling'] ?? 0.0),
            'prioritize_schedule' => (float) ($signals['hybrid'] ?? 0.0),
        ];
        arsort($flowScores);
        $orderedKeys = array_keys($flowScores);
        $topFlow = (string) ($orderedKeys[0] ?? '');
        $secondFlow = (string) ($orderedKeys[1] ?? '');
        $sorted = array_values($flowScores);
        $top = $sorted[0] ?? 0.0;
        $second = $sorted[1] ?? 0.0;
        $margin = $top - $second;

        $topTwoArePrioritizeSchedulePair =
            ($topFlow === 'prioritize' && $secondFlow === 'schedule')
            || ($topFlow === 'schedule' && $secondFlow === 'prioritize');

        $clarificationNeeded = $margin < $clarifyMargin && $topTwoArePrioritizeSchedulePair;

        if ($top < $weakThreshold) {
            $this->logResolution($thread, null, null, $signals, 'general_guidance', ['intent_llm_disabled_signal_weak_general_guidance'], false, $flowScores);

            return new IntentRoutingDecision(
                flow: 'general_guidance',
                confidence: $top,
                reasonCodes: ['intent_llm_disabled_signal_weak_general_guidance'],
                constraints: [],
                clarificationNeeded: false,
                clarificationQuestion: null,
            );
        }

        if ($clarificationNeeded) {
            $reasonCodes = ['signal_only_low_margin_general_guidance'];
            $this->logResolution($thread, null, null, $signals, 'general_guidance', $reasonCodes, false, $flowScores);

            return new IntentRoutingDecision(
                flow: 'general_guidance',
                confidence: $top,
                reasonCodes: $reasonCodes,
                constraints: [],
                clarificationNeeded: false,
                clarificationQuestion: null,
            );
        }

        $reasonCodes = $topFlow === 'prioritize_schedule'
            ? ['signal_only_prioritize_schedule']
            : ['signal_only'];
        $this->logResolution($thread, null, null, $signals, $topFlow, $reasonCodes, false, $flowScores);

        return new IntentRoutingDecision(
            flow: $topFlow,
            confidence: $top,
            reasonCodes: $reasonCodes,
            constraints: [],
            clarificationNeeded: false,
            clarificationQuestion: null,
        );
    }

    /**
     * @param  array{prioritization: float, scheduling: float, hybrid?: float}  $signals
     * @return array<string, float>
     */
    private function compositeScores(array $signals, TaskAssistantUserIntent $effectiveIntent, float $llmConf): array
    {
        $wLlm = (float) config('task-assistant.intent.merge.llm_weight', 0.5);
        $wSig = (float) config('task-assistant.intent.merge.signal_weight', 0.5);

        $llmPrioritize = $effectiveIntent === TaskAssistantUserIntent::Prioritization ? $llmConf : 0.0;

        $llmSched = $effectiveIntent === TaskAssistantUserIntent::Scheduling ? $llmConf : 0.0;

        $signalPrioritize = (float) ($signals['prioritization'] ?? 0.0);
        $signalSchedule = (float) ($signals['scheduling'] ?? 0.0);
        $signalHybrid = (float) ($signals['hybrid'] ?? 0.0);

        return [
            'prioritize' => $wLlm * $llmPrioritize + $wSig * $signalPrioritize,
            'schedule' => $wLlm * $llmSched + $wSig * $signalSchedule,
            'prioritize_schedule' => $wLlm * 0.0 + $wSig * $signalHybrid,
        ];
    }

    /**
     * @param  array{prioritization: float, scheduling: float, hybrid?: float}  $signals
     */
    private function strongestSignalKey(array $signals): string
    {
        $copy = [
            'prioritization' => (float) ($signals['prioritization'] ?? 0.0),
            'scheduling' => (float) ($signals['scheduling'] ?? 0.0),
        ];
        arsort($copy);

        return (string) array_key_first($copy);
    }

    private function signalKeyToIntent(string $key): ?TaskAssistantUserIntent
    {
        return match ($key) {
            'prioritization' => TaskAssistantUserIntent::Prioritization,
            'scheduling' => TaskAssistantUserIntent::Scheduling,
            default => null,
        };
    }

    private function hasListingFollowupContext(TaskAssistantThread $thread): bool
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

    /**
     * @param  array{prioritization: float, scheduling: float, hybrid?: float}  $signals
     * @param  array<int, string>  $reasonCodes
     * @param  array<string, float>|null  $compositeScores
     */
    private function logResolution(
        TaskAssistantThread $thread,
        ?string $llmIntent,
        ?float $llmConfidence,
        array $signals,
        string $finalFlow,
        array $reasonCodes,
        bool $clarificationNeeded,
        ?array $compositeScores = null,
    ): void {
        Log::info('task-assistant.intent_resolution', [
            'layer' => 'intent_resolution',
            'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
            'thread_id' => $thread->id,
            'assistant_message_id' => app()->bound('task_assistant.message_id') ? app('task_assistant.message_id') : null,
            'llm_intent' => $llmIntent,
            'llm_confidence' => $llmConfidence,
            'signals' => $signals,
            'composite_scores' => $compositeScores,
            'final_flow' => $finalFlow,
            'reason_codes' => $reasonCodes,
            'clarification_needed' => $clarificationNeeded,
        ]);
    }
}

<?php

namespace App\Services\LLM\Intent;

use App\Enums\TaskAssistantUserIntent;
use App\Models\TaskAssistantThread;
use App\Services\LLM\TaskAssistant\IntentRoutingDecision;
use Illuminate\Support\Facades\Log;

/**
 * Merges LLM intent inference with heuristic signals; may fall back to general guidance or clarification.
 */
final class TaskAssistantIntentResolutionService
{
    /**
     * @param  array{prioritization: float, scheduling: float}  $signals
     */
    public function resolve(
        TaskAssistantThread $thread,
        string $normalized,
        ?TaskAssistantIntentInferenceResult $inference,
        array $signals,
    ): IntentRoutingDecision {
        $useLlm = (bool) config('task-assistant.intent.use_llm', true);

        if (! $useLlm) {
            return $this->resolveSignalOnly($thread, $signals);
        }

        if ($inference === null || $inference->failed || $inference->intent === null) {
            $this->logResolution($thread, null, null, $signals, 'general_guidance', ['intent_llm_failed_fallback_general_guidance'], false);

            return new IntentRoutingDecision(
                flow: 'general_guidance',
                confidence: 0.0,
                reasonCodes: ['intent_llm_failed_fallback_general_guidance'],
                constraints: [],
                clarificationNeeded: false,
                clarificationQuestion: null,
            );
        }

        $llmIntent = $inference->intent;
        $llmConf = max(0.0, min(1.0, $inference->confidence));

        $reasonCodes = ['llm_intent_'.$llmIntent->value];

        if ($llmIntent === TaskAssistantUserIntent::Greeting || $llmIntent === TaskAssistantUserIntent::GeneralGuidance) {
            $this->logResolution(
                $thread,
                $llmIntent->value,
                $llmConf,
                $signals,
                'general_guidance',
                array_values(array_unique(array_merge($reasonCodes, ['intent_general_guidance']))),
                false
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
                false
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
            $minConf = (float) config('task-assistant.intent.off_topic_min_confidence', 0.65);
            if ($llmConf >= $minConf) {
                $this->logResolution(
                    $thread,
                    $llmIntent->value,
                    $llmConf,
                    $signals,
                    'general_guidance',
                    array_values(array_unique(array_merge(
                        $reasonCodes,
                        ['intent_off_topic']
                    ))),
                    false
                );

                return new IntentRoutingDecision(
                    flow: 'general_guidance',
                    confidence: $llmConf,
                    reasonCodes: array_values(array_unique(array_merge(
                        $reasonCodes,
                        ['intent_off_topic']
                    ))),
                    constraints: [],
                    clarificationNeeded: false,
                    clarificationQuestion: null,
                );
            }
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

        $weakMax = (float) config('task-assistant.intent.merge.weak_composite_max', 0.38);
        $clarifyMargin = (float) config('task-assistant.intent.merge.clarify_margin', 0.12);
        $clarifyCeiling = (float) config('task-assistant.intent.merge.clarify_composite_ceiling', 0.55);

        if ($topComposite < $weakMax && $llmConf < 0.45 && $strongestScore < 0.35) {
            $reasonCodes[] = 'validator_fallback_general';
            $this->logResolution($thread, $llmIntent->value, $llmConf, $signals, 'general_guidance', $reasonCodes, false);

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

        // Confidence gap hardening:
        // If the two top candidate composites are close, prefer general guidance
        // (ask a question) instead of forcing prioritize/schedule or a
        // clarification flow. This reduces wrong hard routing when Hermes is
        // uncertain but still outputs a valid label.
        $ambiguityGapMin = (float) config('task-assistant.intent.merge.ambiguity_gap_min', 0.15);
        $ambiguitySecondCompositeMin = (float) config('task-assistant.intent.merge.ambiguity_second_composite_min', 0.12);
        $ambiguityTopCompositeMax = (float) config('task-assistant.intent.merge.ambiguity_top_composite_max', 0.65);

        $confidenceGapAmbiguous = $margin < $ambiguityGapMin
            && $secondComposite >= $ambiguitySecondCompositeMin
            && $topComposite <= $ambiguityTopCompositeMax;

        if ($confidenceGapAmbiguous) {
            $reasonCodes[] = 'confidence_gap_ambiguous_general_guidance';
            $this->logResolution(
                $thread,
                $llmIntent->value,
                $llmConf,
                $signals,
                'general_guidance',
                $reasonCodes,
                false
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

        if ($clarificationNeeded) {
            $reasonCodes[] = 'low_margin_intent';
        } else {
            $reasonCodes[] = 'validator_merged';
        }

        $this->logResolution($thread, $llmIntent->value, $llmConf, $signals, $finalFlow, $reasonCodes, $clarificationNeeded);

        return new IntentRoutingDecision(
            flow: $finalFlow,
            confidence: $topComposite,
            reasonCodes: $reasonCodes,
            constraints: [],
            clarificationNeeded: $clarificationNeeded,
            clarificationQuestion: $clarificationNeeded ? $this->buildClarificationQuestion($finalFlow) : null,
        );
    }

    /**
     * @param  array{prioritization: float, scheduling: float}  $signals
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
        ];
        arsort($flowScores);
        $topFlow = (string) array_key_first($flowScores);
        $sorted = array_values($flowScores);
        $top = $sorted[0] ?? 0.0;
        $second = $sorted[1] ?? 0.0;
        $margin = $top - $second;

        if ($top < $weakThreshold) {
            $this->logResolution($thread, null, null, $signals, 'general_guidance', ['intent_llm_disabled_signal_weak_general_guidance'], false);

            return new IntentRoutingDecision(
                flow: 'general_guidance',
                confidence: $top,
                reasonCodes: ['intent_llm_disabled_signal_weak_general_guidance'],
                constraints: [],
                clarificationNeeded: false,
                clarificationQuestion: null,
            );
        }

        $clarificationNeeded = $margin < $clarifyMargin;
        $reasonCodes = $clarificationNeeded ? ['signal_only_low_margin'] : ['signal_only'];

        $this->logResolution($thread, null, null, $signals, $topFlow, $reasonCodes, $clarificationNeeded);

        return new IntentRoutingDecision(
            flow: $topFlow,
            confidence: $top,
            reasonCodes: $reasonCodes,
            constraints: [],
            clarificationNeeded: $clarificationNeeded,
            clarificationQuestion: $clarificationNeeded ? $this->buildClarificationQuestion($topFlow) : null,
        );
    }

    /**
     * @param  array{prioritization: float, scheduling: float}  $signals
     * @return array<string, float>
     */
    private function compositeScores(array $signals, TaskAssistantUserIntent $effectiveIntent, float $llmConf): array
    {
        $wLlm = (float) config('task-assistant.intent.merge.llm_weight', 0.5);
        $wSig = (float) config('task-assistant.intent.merge.signal_weight', 0.5);

        $llmPrioritize = $effectiveIntent === TaskAssistantUserIntent::Prioritization ? $llmConf : 0.0;

        $llmSched = $effectiveIntent === TaskAssistantUserIntent::Scheduling ? $llmConf : 0.0;

        $signalPrioritize = (float) ($signals['prioritization'] ?? 0.0);

        return [
            'prioritize' => $wLlm * $llmPrioritize + $wSig * $signalPrioritize,
            'schedule' => $wLlm * $llmSched + $wSig * ($signals['scheduling'] ?? 0.0),
        ];
    }

    /**
     * @param  array{prioritization: float, scheduling: float}  $signals
     */
    private function strongestSignalKey(array $signals): string
    {
        $copy = $signals;
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

    private function buildClarificationQuestion(string $flow): string
    {
        return match ($flow) {
            'schedule' => 'Do you want me to build a schedule for selected tasks, or create a fresh plan for your whole day?',
            'prioritize' => 'Should I prioritize your top tasks now, or help schedule them on your calendar?',
            default => 'Do you want to list or filter tasks, prioritize what to do next, or schedule time for them?',
        };
    }

    /**
     * @param  array{prioritization: float, scheduling: float}  $signals
     * @param  array<int, string>  $reasonCodes
     */
    private function logResolution(
        TaskAssistantThread $thread,
        ?string $llmIntent,
        ?float $llmConfidence,
        array $signals,
        string $finalFlow,
        array $reasonCodes,
        bool $clarificationNeeded,
    ): void {
        Log::info('task-assistant.intent_resolution', [
            'layer' => 'intent_resolution',
            'thread_id' => $thread->id,
            'llm_intent' => $llmIntent,
            'llm_confidence' => $llmConfidence,
            'signals' => $signals,
            'final_flow' => $finalFlow,
            'reason_codes' => $reasonCodes,
            'clarification_needed' => $clarificationNeeded,
        ]);
    }
}

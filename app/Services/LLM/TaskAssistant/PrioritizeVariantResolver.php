<?php

namespace App\Services\LLM\TaskAssistant;

use App\Enums\TaskAssistantPrioritizeVariant;
use App\Models\TaskAssistantThread;
use App\Support\LLM\TaskAssistantSchemas;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;

final class PrioritizeVariantResolver
{
    public function __construct(
        private readonly TaskAssistantPromptData $promptData,
        private readonly TaskAssistantConversationStateService $conversationState,
    ) {}

    /**
     * @param  array<string, mixed>  $constraints
     * @param  array<int, string>  $_reasonCodes  Kept for orchestration parity with {@see ExecutionPlan}; unused today.
     */
    public function resolve(
        TaskAssistantThread $thread,
        string $content,
        array $constraints,
        array $_reasonCodes = [],
    ): PrioritizeVariantResolution {
        return $this->doResolve($thread, $content, $constraints);
    }

    /**
     * @param  array<string, mixed>  $constraints
     */
    private function doResolve(TaskAssistantThread $thread, string $content, array $constraints): PrioritizeVariantResolution
    {
        if ($this->isFollowupSlice($thread, $constraints, $content)) {
            return new PrioritizeVariantResolution(
                variant: TaskAssistantPrioritizeVariant::FollowupSlice,
                confidence: 1.0,
                usedClassifier: false,
            );
        }

        $browse = $this->matchesBrowseListingPattern($content);
        $focus = $this->matchesPrioritizeFocusKeyword($content);

        if ($browse && ! $focus) {
            return new PrioritizeVariantResolution(
                variant: TaskAssistantPrioritizeVariant::Browse,
                confidence: 0.95,
                usedClassifier: false,
            );
        }

        if ($focus && ! $browse) {
            return new PrioritizeVariantResolution(
                variant: TaskAssistantPrioritizeVariant::Rank,
                confidence: 0.95,
                usedClassifier: false,
            );
        }

        if ($browse && $focus) {
            if (! (bool) config('task-assistant.prioritize.use_variant_classifier', true)) {
                return new PrioritizeVariantResolution(
                    variant: TaskAssistantPrioritizeVariant::Rank,
                    confidence: 0.55,
                    usedClassifier: false,
                );
            }

            return $this->classifyRankVsBrowse($thread, $content);
        }

        return new PrioritizeVariantResolution(
            variant: TaskAssistantPrioritizeVariant::Rank,
            confidence: 0.7,
            usedClassifier: false,
        );
    }

    /**
     * @param  array<string, mixed>  $constraints
     */
    private function isFollowupSlice(TaskAssistantThread $thread, array $constraints, string $content): bool
    {
        $fromRouting = (bool) ($constraints['prioritize_followup'] ?? false);
        if (! $fromRouting) {
            return false;
        }

        if ($this->conversationState->lastListing($thread) === null) {
            return false;
        }

        return preg_match('/\bshow\s+next(\s+\d+)?\b|\bnext\s+\d+\b|\bshow\s+more\b/i', $content) === 1;
    }

    private function matchesBrowseListingPattern(string $content): bool
    {
        $msg = mb_strtolower(trim($content));

        return (bool) preg_match('/\b(list|show|display|give me|what)\s+(all\s+)?(my\s+)?tasks?\b/i', $msg);
    }

    private function matchesPrioritizeFocusKeyword(string $content): bool
    {
        return preg_match('/\b(prioritize|focus)\b/i', $content) === 1;
    }

    private function classifyRankVsBrowse(TaskAssistantThread $thread, string $content): PrioritizeVariantResolution
    {
        $maxRetries = max(0, (int) config('task-assistant.retry.max_retries', 2));
        $promptData = $this->promptData->forUser($thread->user);
        $promptData['toolManifest'] = [];
        $schema = TaskAssistantSchemas::prioritizeVariantClassifierSchema();

        $messages = collect([
            new UserMessage(
                'The user message mixes list/show-my-tasks style wording with prioritize or focus. '.
                'Choose exactly one execution mode for this single turn:'."\n".
                '- rank: They mainly want an urgency-aware ranked ordering (top next actions / what to do first).'."\n".
                '- browse: They mainly want a readable listing view (show what they have) with light ordering.'."\n\n".
                'User message: '.$content
            ),
        ]);

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $structuredResponse = Prism::structured()
                    ->using($this->resolveProvider(), $this->resolveModel())
                    ->withSystemPrompt(view('prompts.task-assistant-system', $promptData))
                    ->withMessages($messages->all())
                    ->withTools([])
                    ->withSchema($schema)
                    ->withClientOptions($this->resolveClientOptionsForRoute())
                    ->asStructured();

                $payload = $structuredResponse->structured ?? [];
                $payload = is_array($payload) ? $payload : [];

                $raw = strtolower(trim((string) ($payload['prioritize_variant'] ?? $payload['variant'] ?? '')));
                $variant = match ($raw) {
                    'browse' => TaskAssistantPrioritizeVariant::Browse,
                    default => TaskAssistantPrioritizeVariant::Rank,
                };

                $confidence = isset($payload['confidence']) && is_numeric($payload['confidence'])
                    ? max(0.0, min(1.0, (float) $payload['confidence']))
                    : 0.6;

                $rationale = isset($payload['rationale']) ? trim((string) $payload['rationale']) : null;
                if ($rationale === '') {
                    $rationale = null;
                }

                if ($confidence < 0.35) {
                    return new PrioritizeVariantResolution(
                        variant: TaskAssistantPrioritizeVariant::Rank,
                        confidence: 0.5,
                        usedClassifier: true,
                        classifierRationale: 'low_confidence_fallback_rank',
                    );
                }

                Log::info('task-assistant.prioritize.variant_classifier', [
                    'layer' => 'prioritize',
                    'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
                    'thread_id' => $thread->id,
                    'variant' => $variant->value,
                    'confidence' => $confidence,
                    'attempt' => $attempt,
                ]);

                return new PrioritizeVariantResolution(
                    variant: $variant,
                    confidence: $confidence,
                    usedClassifier: true,
                    classifierRationale: $rationale,
                );
            } catch (\Throwable $e) {
                Log::warning('task-assistant.prioritize.variant_classifier_failed', [
                    'layer' => 'prioritize',
                    'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
                    'thread_id' => $thread->id,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt === $maxRetries) {
                    return new PrioritizeVariantResolution(
                        variant: TaskAssistantPrioritizeVariant::Rank,
                        confidence: 0.5,
                        usedClassifier: true,
                        classifierRationale: 'classifier_failed_fallback_rank',
                    );
                }
            }
        }

        return new PrioritizeVariantResolution(
            variant: TaskAssistantPrioritizeVariant::Rank,
            confidence: 0.5,
            usedClassifier: false,
        );
    }

    private function resolveProvider(): Provider
    {
        $provider = strtolower((string) config('task-assistant.provider', 'ollama'));

        return match ($provider) {
            'ollama' => Provider::Ollama,
            default => Provider::Ollama,
        };
    }

    private function resolveModel(): string
    {
        return (string) config('task-assistant.model', 'hermes3:3b');
    }

    /**
     * @return array<string, int|float>
     */
    private function resolveClientOptionsForRoute(): array
    {
        $route = 'prioritize_variant';
        $temperature = config('task-assistant.generation.'.$route.'.temperature');
        $maxTokens = config('task-assistant.generation.'.$route.'.max_tokens');
        $topP = config('task-assistant.generation.'.$route.'.top_p');

        return [
            'timeout' => (int) config('prism.request_timeout', 120),
            'temperature' => is_numeric($temperature) ? (float) $temperature : (float) config('task-assistant.generation.intent.temperature', 0.1),
            'max_tokens' => is_numeric($maxTokens) ? (int) $maxTokens : (int) config('task-assistant.generation.intent.max_tokens', 200),
            'top_p' => is_numeric($topP) ? (float) $topP : (float) config('task-assistant.generation.intent.top_p', 0.85),
        ];
    }
}

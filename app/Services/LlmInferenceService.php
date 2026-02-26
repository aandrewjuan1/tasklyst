<?php

namespace App\Services;

use App\DataTransferObjects\Llm\LlmInferenceResult;
use App\DataTransferObjects\Llm\LlmSystemPromptResult;
use App\Enums\LlmIntent;
use App\Models\User;
use App\Services\Llm\LlmSchemaFactory;
use App\Services\Llm\RuleBasedPrioritizationService;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;

class LlmInferenceService
{
    public function __construct(
        private LlmSchemaFactory $schemaFactory,
        private RuleBasedPrioritizationService $ruleBasedPrioritization,
    ) {}

    /**
     * Return a fallback result without calling the LLM (e.g. when Ollama is unreachable).
     */
    public function fallbackOnly(LlmIntent $intent, string $promptVersion, ?User $user = null): LlmInferenceResult
    {
        return $this->fallbackResult($intent, $promptVersion, $user);
    }

    /**
     * Run LLM inference with the given system prompt, user prompt (with context), and intent.
     * Returns structured result or fallback when Prism fails.
     */
    public function infer(
        string $systemPrompt,
        string $userPrompt,
        LlmIntent $intent,
        LlmSystemPromptResult $promptResult,
        ?User $user = null,
    ): LlmInferenceResult {
        try {
            $schema = $this->schemaFactory->schemaForIntent($intent);
            $response = Prism::structured()
                ->using(Provider::Ollama, config('tasklyst.llm.model', 'hermes3:3b'))
                ->withSchema($schema)
                ->withSystemPrompt($systemPrompt)
                ->withPrompt($userPrompt)
                ->withClientOptions([
                    'timeout' => (int) config('tasklyst.llm.timeout', 30),
                ])
                ->withProviderOptions([
                    'temperature' => 0.3,
                    'num_ctx' => 4096,
                ])
                ->withMaxTokens((int) config('tasklyst.llm.max_tokens', 500))
                ->asStructured();

            $structured = $response->structured;

            if ($structured === null || ! $this->isValidStructured($structured)) {
                return $this->fallbackResult($intent, $promptResult->version, $user);
            }

            return new LlmInferenceResult(
                structured: $structured,
                promptVersion: $promptResult->version,
                promptTokens: $response->usage->promptTokens,
                completionTokens: $response->usage->completionTokens,
                usedFallback: false,
            );
        } catch (PrismException $e) {
            Log::warning('LLM inference failed, using fallback', [
                'intent' => $intent->value,
                'message' => $e->getMessage(),
            ]);

            return $this->fallbackResult($intent, $promptResult->version, $user);
        }
    }

    private function isValidStructured(array $structured): bool
    {
        return isset(
            $structured['entity_type'],
            $structured['recommended_action'],
            $structured['reasoning']
        );
    }

    private function fallbackResult(LlmIntent $intent, string $promptVersion, ?User $user): LlmInferenceResult
    {
        $structured = $this->buildFallbackStructured($intent, $user);

        return new LlmInferenceResult(
            structured: $structured,
            promptVersion: $promptVersion,
            promptTokens: 0,
            completionTokens: 0,
            usedFallback: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFallbackStructured(LlmIntent $intent, ?User $user): array
    {
        if ($intent === LlmIntent::PrioritizeTasks && $user !== null) {
            $tasks = $this->ruleBasedPrioritization->prioritizeTasks($user, 10);
            $ranked = $tasks->map(fn ($t, $i) => [
                'rank' => $i + 1,
                'task_id' => $t->id,
                'title' => $t->title,
                'end_datetime' => $t->end_datetime?->toIso8601String(),
            ])->values()->all();

            return [
                'entity_type' => 'task',
                'recommended_action' => 'Prioritized by due date and priority (rule-based fallback).',
                'reasoning' => 'AI was unavailable. Tasks are ordered by: overdue first, then soonest due, then priority.',
                'confidence' => 0.7,
                'ranked_tasks' => $ranked,
            ];
        }

        return [
            'entity_type' => 'task',
            'recommended_action' => 'Unable to get AI recommendation. Please try again or rephrase.',
            'reasoning' => 'The assistant is temporarily unavailable or could not produce a valid response.',
            'confidence' => 0.0,
        ];
    }
}

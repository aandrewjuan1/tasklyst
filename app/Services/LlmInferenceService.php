<?php

namespace App\Services;

use App\DataTransferObjects\Llm\EventPrioritizationRecommendationDto;
use App\DataTransferObjects\Llm\EventScheduleRecommendationDto;
use App\DataTransferObjects\Llm\LlmInferenceResult;
use App\DataTransferObjects\Llm\LlmSystemPromptResult;
use App\DataTransferObjects\Llm\ProjectPrioritizationRecommendationDto;
use App\DataTransferObjects\Llm\ProjectScheduleRecommendationDto;
use App\DataTransferObjects\Llm\ResolveDependencyRecommendationDto;
use App\DataTransferObjects\Llm\TaskPrioritizationRecommendationDto;
use App\DataTransferObjects\Llm\TaskScheduleRecommendationDto;
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
    public function fallbackOnly(
        LlmIntent $intent,
        string $promptVersion,
        ?User $user = null,
        ?string $fallbackReason = null
    ): LlmInferenceResult {
        return $this->fallbackResult($intent, $promptVersion, $user, $fallbackReason);
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
        $schema = $this->schemaFactory->schemaForIntent($intent);
        $model = config('tasklyst.llm.model', 'hermes3:3b');

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = Prism::structured()
                    ->using(Provider::Ollama, $model)
                    ->withSchema($schema)
                    ->withSystemPrompt($systemPrompt)
                    ->withPrompt($userPrompt)
                    ->withClientOptions([
                        'timeout' => (int) config('tasklyst.llm.timeout', 30),
                    ])
                    ->withProviderOptions([
                        'temperature' => (float) config('tasklyst.llm.temperature', 0.3),
                        'num_ctx' => (int) config('tasklyst.llm.num_ctx', 4096),
                    ])
                    ->withMaxTokens((int) config('tasklyst.llm.max_tokens', 500))
                    ->asStructured();

                $structured = $response->structured;

                if ($structured === null
                    || ! $this->isValidStructured($intent, $structured)
                    || ! $this->passesIntentSpecificValidation($intent, $structured)
                ) {
                    Log::warning('LLM returned invalid structured payload, using fallback', [
                        'intent' => $intent->value,
                        'prompt_version' => $promptResult->version,
                        'model' => $model,
                        'user_id' => $user?->id,
                    ]);

                    return $this->fallbackResult($intent, $promptResult->version, $user, 'invalid_structured');
                }

                return new LlmInferenceResult(
                    structured: $structured,
                    promptVersion: $promptResult->version,
                    promptTokens: $response->usage->promptTokens,
                    completionTokens: $response->usage->completionTokens,
                    usedFallback: false,
                    fallbackReason: null,
                );
            } catch (PrismException $e) {
                Log::warning('LLM inference failed, will '.($attempt === 0 ? 'retry' : 'use fallback'), [
                    'intent' => $intent->value,
                    'prompt_version' => $promptResult->version,
                    'model' => $model,
                    'user_id' => $user?->id,
                    'attempt' => $attempt + 1,
                    'message' => $e->getMessage(),
                ]);

                if ($attempt === 0) {
                    continue;
                }

                return $this->fallbackResult($intent, $promptResult->version, $user, 'prism_exception');
            }
        }

        return $this->fallbackResult($intent, $promptResult->version, $user, 'unknown_error');
    }

    private function isValidStructured(LlmIntent $intent, array $structured): bool
    {
        if (! isset(
            $structured['entity_type'],
            $structured['recommended_action'],
            $structured['reasoning']
        )) {
            return false;
        }

        if (! is_string($structured['entity_type'])
            || ! is_string($structured['recommended_action'])
            || ! is_string($structured['reasoning'])
        ) {
            return false;
        }

        if (isset($structured['confidence'])
            && (! is_numeric($structured['confidence']) || (float) $structured['confidence'] < 0.0 || (float) $structured['confidence'] > 1.0)
        ) {
            return false;
        }

        $expectedEntityType = match ($intent) {
            LlmIntent::ScheduleTask,
            LlmIntent::AdjustTaskDeadline,
            LlmIntent::PrioritizeTasks => 'task',
            LlmIntent::ScheduleEvent,
            LlmIntent::AdjustEventTime,
            LlmIntent::PrioritizeEvents => 'event',
            LlmIntent::ScheduleProject,
            LlmIntent::AdjustProjectTimeline,
            LlmIntent::PrioritizeProjects => 'project',
            default => null,
        };

        if ($expectedEntityType !== null && $structured['entity_type'] !== $expectedEntityType) {
            return false;
        }

        return true;
    }

    /**
     * Run additional per-intent validation using DTOs where available.
     *
     * @param  array<string, mixed>  $structured
     */
    private function passesIntentSpecificValidation(LlmIntent $intent, array $structured): bool
    {
        return match ($intent) {
            LlmIntent::ScheduleTask,
            LlmIntent::AdjustTaskDeadline => TaskScheduleRecommendationDto::fromStructured($structured) !== null,
            LlmIntent::ScheduleEvent,
            LlmIntent::AdjustEventTime => EventScheduleRecommendationDto::fromStructured($structured) !== null,
            LlmIntent::ScheduleProject,
            LlmIntent::AdjustProjectTimeline => ProjectScheduleRecommendationDto::fromStructured($structured) !== null,
            LlmIntent::PrioritizeTasks => TaskPrioritizationRecommendationDto::fromStructured($structured) !== null,
            LlmIntent::PrioritizeEvents => EventPrioritizationRecommendationDto::fromStructured($structured) !== null,
            LlmIntent::PrioritizeProjects => ProjectPrioritizationRecommendationDto::fromStructured($structured) !== null,
            LlmIntent::ResolveDependency => ResolveDependencyRecommendationDto::fromStructured($structured) !== null,
            default => true,
        };
    }

    private function fallbackResult(
        LlmIntent $intent,
        string $promptVersion,
        ?User $user,
        ?string $fallbackReason
    ): LlmInferenceResult {
        $structured = $this->buildFallbackStructured($intent, $user);

        return new LlmInferenceResult(
            structured: $structured,
            promptVersion: $promptVersion,
            promptTokens: 0,
            completionTokens: 0,
            usedFallback: true,
            fallbackReason: $fallbackReason,
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

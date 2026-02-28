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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        $model = config('tasklyst.llm.model', 'hermes3:3b');
        $ollamaUrl = rtrim((string) config('prism.providers.ollama.url', 'http://127.0.0.1:11434'), '/');

        $maxAttempts = max(1, (int) config('tasklyst.llm.max_attempts', 1));

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                $timeout = (int) config('tasklyst.llm.timeout', 30);
                $temperature = (float) config('tasklyst.llm.temperature', 0.3);
                $numCtx = (int) config('tasklyst.llm.num_ctx', 4096);
                $maxTokens = (int) config('tasklyst.llm.max_tokens', 500);

                $payload = [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'stream' => false,
                    'format' => 'json',
                    'options' => [
                        'temperature' => $temperature,
                        'num_ctx' => $numCtx,
                        'num_predict' => $maxTokens,
                    ],
                ];

                /** @var \Illuminate\Http\Client\Response $httpResponse */
                $httpResponse = Http::timeout($timeout)
                    ->post($ollamaUrl.'/api/chat', $payload);

                $httpResponse->throw();

                $body = $httpResponse->json();

                if (! is_array($body)) {
                    Log::warning('LLM HTTP response was not JSON object, using fallback', [
                        'intent' => $intent->value,
                        'prompt_version' => $promptResult->version,
                        'model' => $model,
                        'user_id' => $user?->id,
                        'body_type' => get_debug_type($body),
                    ]);

                    return $this->fallbackResult($intent, $promptResult->version, $user, 'invalid_structured');
                }

                $content = (string) ($body['message']['content'] ?? '');

                if (trim($content) === '') {
                    Log::warning('LLM HTTP response had empty message content, using fallback', [
                        'intent' => $intent->value,
                        'prompt_version' => $promptResult->version,
                        'model' => $model,
                        'user_id' => $user?->id,
                        'body_preview' => mb_substr(json_encode($body), 0, 500),
                    ]);

                    return $this->fallbackResult($intent, $promptResult->version, $user, 'invalid_structured');
                }

                $structured = json_decode($content, true);

                if (! is_array($structured)) {
                    $start = strpos($content, '{');
                    $end = strrpos($content, '}');

                    if ($start !== false && $end !== false && $end > $start) {
                        $candidate = substr($content, $start, $end - $start + 1);
                        $decodedCandidate = json_decode($candidate, true);

                        if (is_array($decodedCandidate)) {
                            $structured = $decodedCandidate;
                        }
                    }
                }

                if (! is_array($structured)) {
                    $structured = $this->tryRepairTruncatedJson($content);
                }

                if (! is_array($structured)) {
                    Log::warning('LLM HTTP response content was not valid JSON, using fallback', [
                        'intent' => $intent->value,
                        'prompt_version' => $promptResult->version,
                        'model' => $model,
                        'user_id' => $user?->id,
                        'content_preview' => mb_substr($content, 0, 500),
                        'body_preview' => mb_substr(json_encode($body), 0, 500),
                    ]);

                    return $this->fallbackResult($intent, $promptResult->version, $user, 'invalid_structured');
                }

                $structured = $this->trimStructuredKeys($structured);
                $structured = $this->normalizeStructuredForIntent($intent, $structured);

                if ($structured === null
                    || ! $this->isValidStructured($intent, $structured)
                    || ! $this->passesIntentSpecificValidation($intent, $structured)
                ) {
                    Log::warning('LLM returned invalid structured payload, using fallback', [
                        'intent' => $intent->value,
                        'prompt_version' => $promptResult->version,
                        'model' => $model,
                        'user_id' => $user?->id,
                        'structured_raw' => $structured,
                    ]);

                    return $this->fallbackResult($intent, $promptResult->version, $user, 'invalid_structured');
                }

                return new LlmInferenceResult(
                    structured: $structured,
                    promptVersion: $promptResult->version,
                    promptTokens: (int) ($body['prompt_eval_count'] ?? 0),
                    completionTokens: (int) ($body['eval_count'] ?? 0),
                    usedFallback: false,
                    fallbackReason: null,
                );
            } catch (\Throwable $e) {
                $fallbackReason = $e instanceof ConnectionException ? 'connection_exception' : 'http_exception';

                Log::warning('LLM inference failed, will '.($attempt === 0 ? 'retry' : 'use fallback'), [
                    'intent' => $intent->value,
                    'prompt_version' => $promptResult->version,
                    'model' => $model,
                    'user_id' => $user?->id,
                    'attempt' => $attempt + 1,
                    'exception_class' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                if ($attempt === 0) {
                    continue;
                }

                return $this->fallbackResult($intent, $promptResult->version, $user, $fallbackReason);
            }
        }

        return $this->fallbackResult($intent, $promptResult->version, $user, 'unknown_error');
    }

    /**
     * Attempt to repair truncated JSON from the LLM by appending closing brackets.
     * Returns the decoded array if repair succeeds, null otherwise.
     *
     * @return array<string, mixed>|null
     */
    private function tryRepairTruncatedJson(string $content): ?array
    {
        $start = strpos($content, '{');
        if ($start === false) {
            return null;
        }

        $base = substr($content, $start);
        $openBraces = substr_count($base, '{') - substr_count($base, '}');
        $openBrackets = substr_count($base, '[') - substr_count($base, ']');

        if ($openBraces <= 0 && $openBrackets <= 0) {
            return null;
        }

        $suffixes = ['}]}', ']}', '}', ']'];
        foreach ($suffixes as $suffix) {
            $repaired = $base.$suffix;
            $decoded = json_decode($repaired, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Recursively trim whitespace from array keys. Some LLMs (e.g. Ollama) return
     * JSON with leading/trailing spaces in key names, which breaks validation.
     *
     * @param  array<string, mixed>  $arr
     * @return array<string, mixed>
     */
    private function trimStructuredKeys(array $arr): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            $key = is_string($k) ? trim($k) : $k;
            $out[$key] = is_array($v)
                ? (array_is_list($v)
                    ? array_map(fn ($item) => is_array($item) ? $this->trimStructuredKeys($item) : $item, $v)
                    : $this->trimStructuredKeys($v))
                : $v;
        }

        return $out;
    }

    /**
     * Normalise provider-specific quirks in structured payloads so that validation
     * remains stable even if the model returns minor variants like "tasks" instead
     * of "task" for entity_type.
     *
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    private function normalizeStructuredForIntent(LlmIntent $intent, array $structured): array
    {
        $rawValue = $structured['entity_type'] ?? null;
        $raw = is_string($rawValue) ? trim(mb_strtolower($rawValue)) : '';

        $normalized = match ($intent) {
            LlmIntent::ScheduleTask,
            LlmIntent::AdjustTaskDeadline,
            LlmIntent::PrioritizeTasks => in_array($raw, ['task', 'tasks'], true) || $raw === ''
                ? 'task'
                : $raw,
            LlmIntent::ScheduleEvent,
            LlmIntent::AdjustEventTime,
            LlmIntent::PrioritizeEvents => in_array($raw, ['event', 'events'], true) || $raw === ''
                ? 'event'
                : $raw,
            LlmIntent::ScheduleProject,
            LlmIntent::AdjustProjectTimeline,
            LlmIntent::PrioritizeProjects => in_array($raw, ['project', 'projects'], true) || $raw === ''
                ? 'project'
                : $raw,
            default => $raw !== '' ? $raw : 'task',
        };

        $structured['entity_type'] = $normalized;

        return $structured;
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
        $hasCoreText = isset($structured['recommended_action'], $structured['reasoning'])
            && is_string($structured['recommended_action'])
            && trim($structured['recommended_action']) !== ''
            && is_string($structured['reasoning'])
            && trim($structured['reasoning']) !== '';

        return match ($intent) {
            LlmIntent::ScheduleTask,
            LlmIntent::AdjustTaskDeadline => TaskScheduleRecommendationDto::fromStructured($structured) !== null
                || $hasCoreText,
            LlmIntent::ScheduleEvent,
            LlmIntent::AdjustEventTime => EventScheduleRecommendationDto::fromStructured($structured) !== null
                || $hasCoreText,
            LlmIntent::ScheduleProject,
            LlmIntent::AdjustProjectTimeline => ProjectScheduleRecommendationDto::fromStructured($structured) !== null
                || $hasCoreText,
            LlmIntent::PrioritizeTasks => TaskPrioritizationRecommendationDto::fromStructured($structured) !== null
                || $hasCoreText,
            LlmIntent::PrioritizeEvents => EventPrioritizationRecommendationDto::fromStructured($structured) !== null
                || $hasCoreText,
            LlmIntent::PrioritizeProjects => ProjectPrioritizationRecommendationDto::fromStructured($structured) !== null
                || $hasCoreText,
            LlmIntent::ResolveDependency => ResolveDependencyRecommendationDto::fromStructured($structured) !== null
                || $hasCoreText,
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

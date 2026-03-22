<?php

namespace App\Services\LLM\Intent;

use App\Enums\TaskAssistantUserIntent;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

/**
 * Structured LLM step for high-level route intent (listing vs prioritization vs scheduling).
 */
final class TaskAssistantIntentInferenceService
{
    public function infer(string $userMessage): TaskAssistantIntentInferenceResult
    {
        $trimmed = trim($userMessage);
        if ($trimmed === '') {
            return new TaskAssistantIntentInferenceResult(
                intent: null,
                confidence: 0.0,
                failed: true,
                rationale: null,
            );
        }

        $maxRetries = max(0, (int) config('task-assistant.retry.max_retries', 2));

        try {
            $response = $this->attemptInference($trimmed, $maxRetries);
            $structured = $response->structured ?? [];
            $structured = is_array($structured) ? $structured : [];

            $intentRaw = isset($structured['intent']) ? (string) $structured['intent'] : '';
            $intent = TaskAssistantUserIntent::tryFrom(mb_strtolower(trim($intentRaw)));

            $confidence = isset($structured['confidence']) && is_numeric($structured['confidence'])
                ? max(0.0, min(1.0, (float) $structured['confidence']))
                : 0.65;

            $rationale = isset($structured['rationale']) ? trim((string) $structured['rationale']) : null;
            if ($rationale === '') {
                $rationale = null;
            }

            if ($intent === null) {
                Log::warning('task-assistant.intent_inference_invalid_label', [
                    'intent_raw' => $intentRaw,
                ]);

                return new TaskAssistantIntentInferenceResult(
                    intent: null,
                    confidence: $confidence,
                    failed: true,
                    rationale: $rationale,
                );
            }

            return new TaskAssistantIntentInferenceResult(
                intent: $intent,
                confidence: $confidence,
                failed: false,
                rationale: $rationale,
            );
        } catch (\Throwable $e) {
            Log::warning('task-assistant.intent_inference_failed', [
                'error' => $e->getMessage(),
            ]);

            return new TaskAssistantIntentInferenceResult(
                intent: null,
                confidence: 0.0,
                failed: true,
                rationale: null,
            );
        }
    }

    private function buildPrompt(string $userMessage): string
    {
        $allowed = implode(', ', TaskAssistantUserIntent::values());

        return <<<PROMPT
Classify the user's message for a student task assistant.

Allowed intent values (exactly one): {$allowed}
- listing: list, show, search, filter, or find tasks (read/browse).
- prioritization: what to do first, top tasks, ordering by importance/urgency.
- scheduling: calendar, time blocks, plan my day, when to work on something.

USER MESSAGE:
"{$userMessage}"

Respond with JSON matching the schema. Set confidence between 0 and 1. Keep rationale under one sentence.
PROMPT;
    }

    private function intentSchema(): ObjectSchema
    {
        $allowed = implode(', ', TaskAssistantUserIntent::values());

        return new ObjectSchema(
            name: 'task_assistant_route_intent',
            description: 'High-level intent for routing the task assistant.',
            properties: [
                new StringSchema(
                    name: 'intent',
                    description: 'One of: '.$allowed
                ),
                new NumberSchema(
                    name: 'confidence',
                    description: 'Confidence between 0 and 1.'
                ),
                new StringSchema(
                    name: 'rationale',
                    description: 'Brief reason for the classification.'
                ),
            ],
            requiredFields: ['intent', 'confidence']
        );
    }

    /**
     * @return array<string, int|float>
     */
    private function resolveClientOptions(): array
    {
        $temperature = config('task-assistant.generation.intent.temperature');
        $maxTokens = config('task-assistant.generation.intent.max_tokens');
        $topP = config('task-assistant.generation.intent.top_p');

        return [
            'timeout' => (int) config('prism.request_timeout', 120),
            'temperature' => is_numeric($temperature) ? (float) $temperature : 0.1,
            'max_tokens' => is_numeric($maxTokens) ? (int) $maxTokens : 200,
            'top_p' => is_numeric($topP) ? (float) $topP : 0.85,
        ];
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

    private function attemptInference(string $userMessage, int $maxRetries): mixed
    {
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                return Prism::structured()
                    ->using($this->resolveProvider(), $this->resolveModel())
                    ->withPrompt($this->buildPrompt($userMessage))
                    ->withSchema($this->intentSchema())
                    ->withClientOptions($this->resolveClientOptions())
                    ->asStructured();
            } catch (\Throwable $exception) {
                if ($attempt === $maxRetries) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException('Unreachable intent inference retry state.');
    }
}

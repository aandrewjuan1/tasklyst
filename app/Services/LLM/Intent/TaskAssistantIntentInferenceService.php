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
 * Structured LLM step for high-level route intent (prioritization vs scheduling).
 */
final class TaskAssistantIntentInferenceService
{
    public function infer(string $userMessage): TaskAssistantIntentInferenceResult
    {
        $trimmed = trim($userMessage);
        if ($trimmed === '') {
            Log::info('task-assistant.intent_inference', [
                'layer' => 'intent_inference',
                'thread_id' => app()->bound('task_assistant.thread_id') ? app('task_assistant.thread_id') : null,
                'outcome' => 'empty_input',
            ]);

            return new TaskAssistantIntentInferenceResult(
                intent: null,
                confidence: 0.0,
                failed: true,
                rationale: null,
            );
        }

        $maxRetries = max(0, (int) config('task-assistant.retry.max_retries', 2));

        $startedAt = microtime(true);

        try {
            ['response' => $response, 'attempts' => $attempts] = $this->attemptInference($trimmed, $maxRetries);
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
                    'layer' => 'intent_inference',
                    'thread_id' => app()->bound('task_assistant.thread_id') ? app('task_assistant.thread_id') : null,
                    'intent_raw' => $intentRaw,
                    'structured_raw' => $structured,
                    'provider' => (string) config('task-assistant.provider', 'ollama'),
                    'model' => $this->resolveModel(),
                ]);

                return new TaskAssistantIntentInferenceResult(
                    intent: null,
                    confidence: $confidence,
                    failed: true,
                    rationale: $rationale,
                );
            }

            Log::info('task-assistant.intent_inference', [
                'layer' => 'intent_inference',
                'thread_id' => app()->bound('task_assistant.thread_id') ? app('task_assistant.thread_id') : null,
                'structured_raw' => $structured,
                'intent' => $intent->value,
                'confidence' => $confidence,
                'rationale' => $rationale,
                'provider' => (string) config('task-assistant.provider', 'ollama'),
                'model' => $this->resolveModel(),
                'attempts' => $attempts,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return new TaskAssistantIntentInferenceResult(
                intent: $intent,
                confidence: $confidence,
                failed: false,
                rationale: $rationale,
            );
        } catch (\Throwable $e) {
            Log::warning('task-assistant.intent_inference_failed', [
                'layer' => 'intent_inference',
                'thread_id' => app()->bound('task_assistant.thread_id') ? app('task_assistant.thread_id') : null,
                'error' => $e->getMessage(),
                'provider' => (string) config('task-assistant.provider', 'ollama'),
                'model' => $this->resolveModel(),
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return new TaskAssistantIntentInferenceResult(
                intent: null,
                confidence: 0.0,
                failed: true,
                rationale: null,
                connectionFailed: true,
            );
        }
    }

    private function buildPrompt(string $userMessage): string
    {
        $allowed = implode(', ', TaskAssistantUserIntent::values());

        return <<<PROMPT
Classify the user's message for a student task assistant.

Allowed intent values (exactly one): {$allowed}
- greeting: social greeting-only (hi/hello/yo) with no task request.
- general_guidance: vague help-seeking, stress/overwhelm, \"help\", \"what now\", or productivity/task support without a clear request to prioritize vs schedule.
- unclear: unintelligible, noisy, or unclear message where you cannot identify a real request yet.
- prioritization: what to do first, top tasks, ordering by importance/urgency, ranking, deadlines, homework/quiz/exam stress, \"idk what to do\", \"what matters most\", listing/filtering, or choosing between tasks.
- listing_followup: a **follow-up** about something the assistant **already showed** (a ranked list or a schedule)—verification or explanation, **not** a new request to place blocks. Examples: \"are those the most urgent?\", \"is that the right order?\", \"why those two?\", \"does that make sense?\". Do **not** use this when they clearly want new calendar blocks or a fresh day plan (use scheduling or prioritize_schedule).
- scheduling: calendar, time blocks, plan my day, when to work on something, \"fit it in\", \"when can I\", \"what time\", \"block time\", \"remind me\", afternoon/tomorrow/this week.
- prioritize_schedule: the user wants **both** (a) which tasks matter most / what to do first **and** (b) **when** to do them or a concrete time plan. Use this whenever **importance/ordering** language is combined with **time, calendar, or planning-when** language—even if one side is short. Examples: "when should I do my most important tasks?", "plan my most important tasks", "what time should I tackle my top homework?", "when can I fit in my urgent tasks?", "rank these and put them on my calendar", "schedule my top 3 for tomorrow afternoon". If the user only wants an ordered list with **no** timing/planning angle, use prioritization; if they only want slots with **no** "what matters first" angle, use scheduling.
- off_topic: unrelated to task management or productivity (e.g., politics, celebrity, product recommendations, relationship advice).

USER MESSAGE:
"{$userMessage}"

Rules for confidence discipline:
- If the user clearly wants help deciding order, urgency, or what to tackle (even if messy slang or short), prefer prioritization with confidence >= 0.72.
- If the user clearly wants a time, calendar, or slot (even if informal), prefer scheduling with confidence >= 0.72.
- If the user explicitly asks to prioritize or list what to do next, set confidence >= 0.75.
- If the user explicitly asks for scheduling, calendar, or time blocks, set confidence >= 0.75.
- If the user explicitly asks to schedule their top/first/next tasks (rank + time window), set confidence >= 0.75.
- If the message combines **which tasks matter / most important / top / urgent (for tasks)** with **when / plan / time / calendar / fit in**, choose **prioritize_schedule** with confidence >= 0.78 (do not split into prioritization-only).
- If the user is vague / only says \"help\", \"what now\", \"overwhelmed\", or otherwise does not clearly ask for prioritize vs schedule,
  use general_guidance with confidence between 0.55 and 0.80.
- If the user is social/greeting-only, use greeting with confidence >= 0.80.
- If the user is gibberish/noisy/unclear, use unclear with confidence >= 0.75.
- Keep confidence <= 0.60 only when the input is truly ambiguous between prioritize and scheduling (both sound equally likely).

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
            default => $this->fallbackProvider($provider),
        };
    }

    private function fallbackProvider(string $provider): Provider
    {
        Log::warning('task-assistant.provider.fallback', [
            'layer' => 'intent_inference',
            'requested_provider' => $provider,
            'fallback_provider' => 'ollama',
        ]);

        return Provider::Ollama;
    }

    private function resolveModel(): string
    {
        return (string) config('task-assistant.model', 'hermes3:3b');
    }

    /**
     * @return array{response: mixed, attempts: int}
     */
    private function attemptInference(string $userMessage, int $maxRetries): array
    {
        $attempts = 0;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $attempts++;
            try {
                $response = Prism::structured()
                    ->using($this->resolveProvider(), $this->resolveModel())
                    ->withPrompt($this->buildPrompt($userMessage))
                    ->withSchema($this->intentSchema())
                    ->withClientOptions($this->resolveClientOptions())
                    ->asStructured();

                return [
                    'response' => $response,
                    'attempts' => $attempts,
                ];
            } catch (\Throwable $exception) {
                if ($attempt === $maxRetries) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException('Unreachable intent inference retry state.');
    }
}

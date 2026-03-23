<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\User;
use App\Support\LLM\TaskAssistantSchemas;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Structured LLM calls for the general guidance redirect flow.
 */
final class TaskAssistantGeneralGuidanceService
{
    public function __construct(
        private readonly TaskAssistantPromptData $promptData,
    ) {}

    /**
     * @return array{
     *   message: string,
     *   clarifying_question: string,
     *   redirect_target: string,
     *   suggested_replies?: list<string>|null
     * }
     */
    public function generateGeneralGuidance(
        User $user,
        string $userMessage,
        ?string $forcedClarifyingQuestion = null
    ): array {
        $promptData = $this->promptData->forUser($user);
        $maxRetries = max(0, (int) config('task-assistant.retry.max_retries', 2));
        $schema = TaskAssistantSchemas::generalGuidanceSchema();

        $messages = collect([
            new UserMessage(
                'User message (vague/help-seeking/overwhelmed): '.$userMessage."\n\n".
                ($forcedClarifyingQuestion !== null
                    ? 'Re-ask mode: keep clarifying_question EXACTLY as provided: '.$forcedClarifyingQuestion."\n".'Do not change question wording.'
                    : 'Generate one helpful empathetic message and exactly one clarifying question.')
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
                    ->withClientOptions($this->resolveClientOptionsForRoute('general_guidance'))
                    ->asStructured();

                $payload = $structuredResponse->structured ?? [];
                $payload = is_array($payload) ? $payload : [];

                return [
                    'message' => (string) ($payload['message'] ?? ''),
                    'clarifying_question' => (string) ($payload['clarifying_question'] ?? ''),
                    'redirect_target' => (string) ($payload['redirect_target'] ?? 'either'),
                    'suggested_replies' => is_array($payload['suggested_replies'] ?? null)
                        ? array_values(array_map(static fn (mixed $v): string => (string) $v, $payload['suggested_replies']))
                        : null,
                ];
            } catch (\Throwable $e) {
                if ($attempt === $maxRetries) {
                    Log::warning('task-assistant.general_guidance.generate_failed', [
                        'layer' => 'llm_guidance',
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);

                    return [
                        'message' => 'I hear you. We can make this feel more manageable with a simple next step.',
                        'clarifying_question' => $forcedClarifyingQuestion ?? 'Do you want me to show your top tasks, or help plan time blocks for them?',
                        'redirect_target' => 'either',
                        'suggested_replies' => [
                            'Show my top tasks.',
                            'Plan time blocks for my tasks.',
                        ],
                    ];
                }
            }
        }

        return [
            'message' => 'I hear you. We can make this feel more manageable with a simple next step.',
            'clarifying_question' => $forcedClarifyingQuestion ?? 'Do you want me to show your top tasks, or help plan time blocks for them?',
            'redirect_target' => 'either',
            'suggested_replies' => [
                'Show my top tasks.',
                'Plan time blocks for my tasks.',
            ],
        ];
    }

    /**
     * @return array{target: string, confidence: float, rationale?: string|null}
     */
    public function resolveTargetFromAnswer(
        User $user,
        string $clarifyingQuestion,
        string $userAnswer
    ): array {
        $promptData = $this->promptData->forUser($user);
        $maxRetries = max(0, (int) config('task-assistant.retry.max_retries', 2));
        $schema = TaskAssistantSchemas::generalGuidanceTargetSchema();

        $messages = collect([
            new UserMessage(
                'Guidance question that the assistant asked: '.$clarifyingQuestion."\n\n".
                'User answer: '.$userAnswer."\n\n".
                'Decide whether the answer indicates: prioritize or schedule.'
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
                    ->withClientOptions($this->resolveClientOptionsForRoute('general_guidance_target'))
                    ->asStructured();

                $payload = $structuredResponse->structured ?? [];
                $payload = is_array($payload) ? $payload : [];

                $confidence = isset($payload['confidence']) && is_numeric($payload['confidence'])
                    ? max(0.0, min(1.0, (float) $payload['confidence']))
                    : 0.0;

                return [
                    'target' => (string) ($payload['target'] ?? 'either'),
                    'confidence' => $confidence,
                    'rationale' => isset($payload['rationale']) ? trim((string) $payload['rationale']) : null,
                ];
            } catch (\Throwable $e) {
                if ($attempt === $maxRetries) {
                    Log::warning('task-assistant.general_guidance.target_resolve_failed', [
                        'layer' => 'llm_guidance',
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);

                    return [
                        'target' => 'either',
                        'confidence' => 0.0,
                        'rationale' => null,
                    ];
                }
            }
        }

        return [
            'target' => 'either',
            'confidence' => 0.0,
            'rationale' => null,
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
            'layer' => 'llm_guidance',
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
     * @return array<string, int|float|null>
     */
    private function resolveClientOptionsForRoute(string $route): array
    {
        $temperature = config('task-assistant.generation.'.$route.'.temperature');
        $maxTokens = config('task-assistant.generation.'.$route.'.max_tokens');
        $topP = config('task-assistant.generation.'.$route.'.top_p');

        return [
            'timeout' => (int) config('prism.request_timeout', 120),
            'temperature' => is_numeric($temperature) ? (float) $temperature : (float) config('task-assistant.generation.temperature', 0.3),
            'max_tokens' => is_numeric($maxTokens) ? (int) $maxTokens : (int) config('task-assistant.generation.max_tokens', 1200),
            'top_p' => is_numeric($topP) ? (float) $topP : (float) config('task-assistant.generation.top_p', 0.9),
        ];
    }
}

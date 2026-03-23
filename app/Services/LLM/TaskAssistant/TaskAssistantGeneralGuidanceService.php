<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\User;
use App\Support\LLM\TaskAssistantSchemas;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Structured LLM calls for the general guidance redirect flow.
 */
final class TaskAssistantGeneralGuidanceService
{
    private const MAX_GENERAL_GUIDANCE_MESSAGE_CHARS = 500;

    private const MAX_GENERAL_GUIDANCE_QUESTION_CHARS = 220;

    private const MAX_GENERAL_GUIDANCE_SUGGESTED_REPLY_CHARS = 140;

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
        // Hide tools from this prompt so the model doesn't leak tool/function
        // signature artifacts (we also pass withTools([]) below).
        $promptData['toolManifest'] = [];

        $timeContext = '';
        $timeLabelForFallback = null;
        if ($this->isLikelyTimeQuery($userMessage)) {
            $timezone = (string) ($promptData['userContext']['timezone'] ?? config('app.timezone', 'UTC'));
            $now = CarbonImmutable::now($timezone);
            $timeLabel = $now->format('g:i A');
            $timeLabelForFallback = $timeLabel;

            // Feed deterministic time context into the LLM so the final wording
            // (acknowledgement + redirect question) stays fully dynamic.
            $timeContext = "\n\nTime context (deterministic, use exactly): The current time for the user is {$timeLabel}. ".
                'Use this exact time value in your answer (do not reformat/guess), then redirect into the next action by asking a single question about prioritizing vs scheduling.';
        }

        $maxRetries = max(0, (int) config('task-assistant.retry.max_retries', 2));
        $schema = TaskAssistantSchemas::generalGuidanceSchema();

        $messages = collect([
            new UserMessage(
                'User message (vague/help-seeking/overwhelmed): '.$userMessage.$timeContext."\n\n".
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

                $message = (string) ($payload['message'] ?? '');
                $clarifyingQuestion = (string) ($payload['clarifying_question'] ?? '');
                $suggestedReplies = $payload['suggested_replies'] ?? null;

                $message = $this->normalizeGeneralGuidanceText($message);
                $clarifyingQuestion = $this->normalizeGeneralGuidanceText($clarifyingQuestion);

                // Hermes sometimes leaks the redirect question inside `message`.
                // The UI always renders `message + clarifying_question`, so we strip
                // overlap to avoid duplication.
                $message = $this->stripClarifyingQuestionFromMessage(
                    message: $message,
                    clarifyingQuestion: $clarifyingQuestion,
                );

                // If the user message looks like typing noise or otherwise
                // unintelligible, steer to a short "rephrase" acknowledgement.
                if ($this->isLikelyGibberish($userMessage) && $forcedClarifyingQuestion === null) {
                    $message = "I didn't quite catch that. Can you rephrase what you want to do in one short sentence so I can help you prioritize or schedule time blocks?";
                }

                // `clarifying_question` is the only question allowed. If the model
                // still included a question mark inside `message`, sanitize it.
                if (mb_strpos($message, '?') !== false) {
                    $message = str_replace('?', '.', $message);
                }

                if ($message === '') {
                    $message = 'I hear you. We can take one clear next step.';
                }

                if ($clarifyingQuestion === '') {
                    $clarifyingQuestion = 'Would you like my help prioritizing which tasks to focus on, or schedule time blocks for them?';
                }

                // Emotional/personal off-domain messages: empathize briefly,
                // refuse the personal topic, and redirect into task management.
                if ($this->isLikelyEmotionalOffDomain($userMessage) && $forcedClarifyingQuestion === null) {
                    $message = $this->emotionalOffDomainMessageVariant();
                }

                $clarifyingQuestion = $this->clampString($clarifyingQuestion, self::MAX_GENERAL_GUIDANCE_QUESTION_CHARS);
                if (! str_ends_with($clarifyingQuestion, '?')) {
                    $clarifyingQuestion = rtrim($clarifyingQuestion, " \t\n\r\0\x0B.").'?';
                }

                $message = $this->clampString($message, self::MAX_GENERAL_GUIDANCE_MESSAGE_CHARS);
                $message = $this->simplifyGeneralGuidanceMessage($message);
                $suggestedReplies = $this->normalizeSuggestedReplies($suggestedReplies);

                $redirectTarget = $this->normalizeRedirectTarget((string) ($payload['redirect_target'] ?? 'either'));

                if ($suggestedReplies === null) {
                    $suggestedReplies = match ($redirectTarget) {
                        'prioritize' => [
                            'Show my top tasks.',
                            'Help me pick my next priority.',
                        ],
                        'schedule' => [
                            'Plan time blocks for my tasks.',
                            'Schedule focused work time for my next task.',
                        ],
                        default => [
                            'Prioritize my tasks.',
                            'Schedule time blocks for my tasks.',
                        ],
                    };
                }

                return [
                    'message' => $message,
                    'clarifying_question' => $clarifyingQuestion,
                    'redirect_target' => $redirectTarget,
                    'suggested_replies' => $suggestedReplies,
                ];
            } catch (\Throwable $e) {
                if ($attempt === $maxRetries) {
                    Log::warning('task-assistant.general_guidance.generate_failed', [
                        'layer' => 'llm_guidance',
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);

                    return [
                        'message' => $timeLabelForFallback !== null
                            ? "Right now, it's {$timeLabelForFallback} for you."
                            : 'I hear you. We can make this feel more manageable with a simple next step.',
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
            'message' => $timeLabelForFallback !== null
                ? "Right now, it's {$timeLabelForFallback} for you."
                : 'I hear you. We can make this feel more manageable with a simple next step.',
            'clarifying_question' => $forcedClarifyingQuestion ?? 'Do you want me to show your top tasks, or help plan time blocks for them?',
            'redirect_target' => 'either',
            'suggested_replies' => [
                'Show my top tasks.',
                'Plan time blocks for my tasks.',
            ],
        ];
    }

    /**
     * @return list<string>|null
     */
    private function normalizeSuggestedReplies(mixed $suggestedReplies): ?array
    {
        if (! is_array($suggestedReplies)) {
            return null;
        }

        $clean = array_values(array_filter(array_map(static function (mixed $v): string {
            return trim((string) $v);
        }, $suggestedReplies), static fn (string $v): bool => $v !== ''));

        if ($clean === []) {
            return null;
        }

        $clean = array_slice($clean, 0, 3);
        $clean = array_values(array_map(fn (string $s): string => $this->clampString($s, self::MAX_GENERAL_GUIDANCE_SUGGESTED_REPLY_CHARS), $clean));

        // Ensure suggested replies still meet min length.
        $clean = array_values(array_filter($clean, static fn (string $s): bool => mb_strlen($s) >= 1));

        return $clean === [] ? null : $clean;
    }

    private function normalizeGeneralGuidanceText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // Collapse whitespace to prevent duplicated question variants and
        // reduce character-limit surprises.
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim((string) $value);
    }

    private function isLikelyGibberish(string $userMessage): bool
    {
        $msg = mb_strtolower(trim($userMessage));
        if ($msg === '') {
            return false;
        }

        // If it's multiple words, it is more likely to be understandable.
        if (str_contains($msg, ' ')) {
            return false;
        }

        if (mb_strlen($msg) < 9) {
            return false;
        }

        // If we find common English bigrams, treat it as real text.
        $commonBigrams = ['th', 'he', 'in', 'er', 'an', 're', 'on', 'at', 'en', 'nd', 'ti', 'es', 'or', 'te', 'of'];
        foreach ($commonBigrams as $b) {
            if (mb_stripos($msg, $b) !== false) {
                return false;
            }
        }

        return true;
    }

    private function isLikelyEmotionalOffDomain(string $userMessage): bool
    {
        $msg = mb_strtolower($userMessage);

        $emotions = ['sad', 'overwhelmed', 'kicked out', 'felt so sad', 'heartbroken', 'broke up', 'cry', 'depressed', 'devastated'];
        $hitsEmotion = false;
        foreach ($emotions as $needle) {
            if (mb_stripos($msg, $needle) !== false) {
                $hitsEmotion = true;
                break;
            }
        }

        if (! $hitsEmotion) {
            return false;
        }

        // If they clearly mention task management, let the normal guidance
        // handle it (still includes empathy).
        $taskKeywords = ['task', 'tasks', 'prioritize', 'priority', 'schedule', 'time block', 'work on', 'to do', 'todo', 'list'];
        foreach ($taskKeywords as $kw) {
            if (mb_stripos($msg, $kw) !== false) {
                return false;
            }
        }

        return true;
    }

    private function emotionalOffDomainMessageVariant(): string
    {
        // Keep it declarative: message should not include the redirect question.
        return "I'm really sorry you're feeling that way. I'm a task assistant, so I can't help with that personal topic - but I can help you get unstuck with your tasks.";
    }

    private function simplifyGeneralGuidanceMessage(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return $message;
        }

        $message = preg_replace('/\s+/u', ' ', $message) ?? $message;

        // Remove common greeting-only first sentence fragments to reduce repetition.
        $message = preg_replace('/^(hi|hello|hey)\b[^.!?]{0,40}[.!?]\s*/iu', '', $message) ?? $message;

        // Keep at most 2 sentences to avoid long "generic paragraphs".
        $parts = preg_split('/(?<=[.!?])\s+/u', $message) ?: [];
        if (count($parts) > 2) {
            $message = trim(implode(' ', array_slice($parts, 0, 2)));
        }

        // If the message still contains question-leading phrasing, cut it off.
        $questionStarters = ['would you', 'could you', 'let me know', 'how about', 'can you', 'would you like'];
        $lower = mb_strtolower($message);
        foreach ($questionStarters as $starter) {
            $idx = mb_stripos($lower, $starter);
            if ($idx !== false) {
                $message = trim(mb_substr($message, 0, $idx));
                break;
            }
        }

        $message = rtrim($message, " \t\n\r\0\x0B-:;.");
        if ($message !== '') {
            $message .= '.';
        }

        return trim($message);
    }

    private function stripClarifyingQuestionFromMessage(string $message, string $clarifyingQuestion): string
    {
        if ($message === '' || $clarifyingQuestion === '') {
            return $message;
        }

        // Exact overlap: message already includes the full question.
        if (str_contains($message, $clarifyingQuestion)) {
            $message = str_replace($clarifyingQuestion, '', $message);

            return trim(preg_replace('/\s+/u', ' ', $message) ?: '');
        }

        // Heuristic: message includes a "Would you like / Do you want" tail.
        $startTokens = ['would you like', 'do you want', 'if you want', 'which would you like', 'can you help'];
        $lower = mb_strtolower($message);

        $startIndex = null;
        foreach ($startTokens as $token) {
            $idx = mb_stripos($lower, $token);
            if ($idx === false) {
                continue;
            }
            $startIndex = $idx;
            break;
        }

        if ($startIndex !== null) {
            $message = mb_substr($message, 0, $startIndex);
            $message = trim($message);

            // Avoid trailing punctuation artifacts like ":" or "-" leftovers.
            $message = rtrim($message, " \t\n\r\0\x0B-:;");

            return trim(preg_replace('/\s+/u', ' ', $message) ?: '');
        }

        return $message;
    }

    private function clampString(string $value, int $maxChars): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (mb_strlen($value) <= $maxChars) {
            return $value;
        }

        return mb_substr($value, 0, $maxChars);
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
        $promptData['toolManifest'] = [];
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

                $target = $this->normalizeTarget((string) ($payload['target'] ?? 'either'));

                return [
                    'target' => $target,
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

    private function normalizeRedirectTarget(string $redirectTarget): string
    {
        $t = mb_strtolower(trim($redirectTarget));

        if (in_array($t, ['prioritize', 'priority'], true)) {
            return 'prioritize';
        }

        if (in_array($t, [
            'schedule',
            'calendar',
            'time block',
            'timeblock',
            'time blocks',
            'time slot',
            'time slots',
            'time blocking',
        ], true)) {
            return 'schedule';
        }

        if (in_array($t, ['prioritizing', 'prioritization'], true)) {
            return 'prioritize';
        }

        if (in_array($t, ['top tasks', 'next tasks', 'rank', 'ranking', 'order', 'do first'], true)) {
            return 'prioritize';
        }

        // The model may emit "tasks"/"task list" when it means "prioritize".
        if (in_array($t, ['tasks', 'task list', 'task', 'todo', 'to-do', 'to do', 'todos', 'to-dos'], true)) {
            return 'either';
        }

        if (in_array($t, ['either', 'unknown', 'both'], true)) {
            return 'either';
        }

        return 'either';
    }

    private function normalizeTarget(string $target): string
    {
        $t = mb_strtolower(trim($target));

        if (in_array($t, [
            'schedule',
            'calendar',
            'time block',
            'timeblock',
            'time blocks',
            'time slot',
            'time slots',
            'time blocking',
            'plan my day',
            'plan the day',
        ], true)) {
            return 'schedule';
        }

        if (in_array($t, [
            'prioritize',
            'prioritizing',
            'prioritization',
            'priority',
            'tasks',
            'task list',
            'task',
            'top tasks',
            'next tasks',
            'rank',
            'ranking',
            'order',
            'do first',
            'what to do next',
            'todo',
            'to-do',
            'to do',
            'todos',
            'to-dos',
        ], true)) {
            return 'prioritize';
        }

        if (in_array($t, ['either', 'unknown', 'both'], true)) {
            return 'either';
        }

        return 'either';
    }

    private function isLikelyTimeQuery(string $userMessage): bool
    {
        $msg = mb_strtolower(trim($userMessage));

        return (bool) preg_match(
            '/\b(current\s+time|time\s+now|time\s+right\s+now|what\s+time\s+is\s+it|what\s*\'?s\s+the\s+time|date\s+today|today\s*\'?s\s+date|what\s+date\s+is\s+it|what\s*\'?s\s+the\s+date)\b/u',
            $msg
        );
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

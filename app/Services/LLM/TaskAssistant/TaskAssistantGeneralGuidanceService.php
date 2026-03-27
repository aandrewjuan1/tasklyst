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
    private const MODE_FRIENDLY_GENERAL = 'friendly_general';

    private const MODE_GIBBERISH_UNCLEAR = 'gibberish_unclear';

    private const MODE_OFF_TOPIC = 'off_topic';

    private const INTENT_TASK = 'task';

    private const INTENT_OUT_OF_SCOPE = 'out_of_scope';

    private const INTENT_UNCLEAR = 'unclear';

    private const MAX_GENERAL_GUIDANCE_MESSAGE_CHARS = 500;

    private const MAX_GENERAL_GUIDANCE_QUESTION_CHARS = 220;

    private const MAX_GENERAL_GUIDANCE_SUGGESTED_REPLY_CHARS = 140;

    public function __construct(
        private readonly TaskAssistantPromptData $promptData,
    ) {}

    /**
     * @return array{
     *   intent: string,
     *   acknowledgement: string,
     *   framing: string,
     *   response: string,
     *   suggested_next_actions: list<string>
     * }
     */
    public function generateGeneralGuidance(
        User $user,
        string $userMessage,
        ?string $forcedMode = null,
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
            // (response + redirect question) stays fully dynamic.
            $timeContext = "\n\nTime context (deterministic, use exactly): The current time for the user is {$timeLabel}. ".
                'Use this exact time value in your answer (do not reformat/guess), then redirect into the next action by asking a single question about prioritizing vs scheduling.';
        }

        $maxRetries = max(0, (int) config('task-assistant.retry.max_retries', 2));
        $schema = TaskAssistantSchemas::generalGuidanceSchema();

        $messages = collect([
            new UserMessage(
                'User message for guidance mode selection: '.$userMessage.$timeContext."\n\n".
                'Generate intent + acknowledgement + framing + response + suggested_next_actions. '.
                'Keep the user prompt as the primary context anchor for every field. '.
                'Do not output generic boilerplate if the prompt gives concrete context. '.
                'Use concise, supportive wording grounded in the user message. '.
                'Write natural conversational English for a student. Prefer verbal clauses over nominalized/formal phrasing. '.
                'Do not quote or parrot the full user message. Paraphrase the meaning naturally.'
            ),
        ]);

        $resolvedMode = $this->resolveGuidanceMode($userMessage, $forcedMode);
        $resolvedIntent = $this->intentFromMode($resolvedMode);

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

                $mode = $forcedMode !== null ? $this->normalizeGuidanceMode($forcedMode) : $resolvedMode;

                $intent = $this->normalizeIntent((string) ($payload['intent'] ?? ''));
                if ($intent === self::INTENT_TASK) {
                    $intent = $this->intentFromMode($mode);
                }

                if ($this->isLikelyEmotionalOffDomain($userMessage)) {
                    $intent = self::INTENT_OUT_OF_SCOPE;
                }

                $acknowledgement = $this->normalizeGeneralGuidanceText((string) ($payload['acknowledgement'] ?? ''));
                $framing = $this->normalizeGeneralGuidanceText((string) ($payload['framing'] ?? ''));
                $response = $this->normalizeGeneralGuidanceText((string) ($payload['response'] ?? ''));
                $suggestedNextActions = $this->normalizeSuggestedNextActions($payload['suggested_next_actions'] ?? null);

                $acknowledgement = $this->sanitizeUserFacingLanguage($acknowledgement);
                $framing = $this->sanitizeUserFacingLanguage($framing);
                $response = $this->sanitizeUserFacingLanguage($response);

                $acknowledgement = $this->humanizeTone(
                    $this->lightweightAcknowledgementPolish($intent, $acknowledgement),
                    $intent,
                    $userMessage
                );
                $framing = $this->humanizeTone(
                    $this->lightweightFramingPolish($intent, $framing, $userMessage),
                    $intent,
                    $userMessage
                );
                $response = $this->humanizeTone(
                    $this->lightweightResponsePolish($intent, $response, $userMessage),
                    $intent,
                    $userMessage
                );

                if ($acknowledgement === '') {
                    $acknowledgement = match ($intent) {
                        self::INTENT_OUT_OF_SCOPE => "That's an interesting question.",
                        self::INTENT_UNCLEAR => "I didn't quite catch that yet.",
                        default => 'I hear you.',
                    };
                }

                if ($framing === '') {
                    $framing = match ($intent) {
                        self::INTENT_OUT_OF_SCOPE => "That request is outside task planning, so I'll keep us focused on what I can help with.",
                        self::INTENT_UNCLEAR => "Your message isn't clear enough yet for me to identify a task request.",
                        default => "You're asking for general support before we choose a specific planning flow.",
                    };
                }

                if ($response === '') {
                    $response = match ($intent) {
                        self::INTENT_OUT_OF_SCOPE => "I'm focused on helping you manage tasks and take action. I can't help with that unrelated topic, but I can help you get organized right now.",
                        self::INTENT_UNCLEAR => "I'm here to help with tasks and next steps. Rephrase what you need in one short sentence, and I'll help you move forward.",
                        default => "Let's keep this simple and actionable. We can pick one clear next step and build momentum from there.",
                    };
                }

                $acknowledgement = $this->clampString($acknowledgement, 220);
                $framing = $this->clampString($framing, 320);
                $response = $this->clampString($response, self::MAX_GENERAL_GUIDANCE_MESSAGE_CHARS);

                $suggestedNextActions = $this->enforceSuggestedNextActions($suggestedNextActions, $intent);

                return [
                    'intent' => $intent,
                    'acknowledgement' => $acknowledgement,
                    'framing' => $framing,
                    'response' => $response,
                    'suggested_next_actions' => $suggestedNextActions,
                ];
            } catch (\Throwable $e) {
                if ($attempt === $maxRetries) {
                    Log::warning('task-assistant.general_guidance.generate_failed', [
                        'layer' => 'llm_guidance',
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);

                    return [
                        'intent' => $resolvedIntent,
                        'acknowledgement' => 'I hear you.',
                        'framing' => $resolvedIntent === self::INTENT_UNCLEAR
                            ? "Your message isn't clear enough yet for me to identify a task request."
                            : 'You are asking for guidance before choosing the next planning step.',
                        'response' => $timeLabelForFallback !== null
                            ? "Right now, it's {$timeLabelForFallback} for you. I can help you turn this into a clear next action."
                            : 'I can help make this manageable with one clear next step.',
                        'suggested_next_actions' => $this->enforceSuggestedNextActions([], $resolvedIntent),
                    ];
                }
            }
        }

        return [
            'intent' => $resolvedIntent,
            'acknowledgement' => 'I hear you.',
            'framing' => $resolvedIntent === self::INTENT_UNCLEAR
                ? "Your message isn't clear enough yet for me to identify a task request."
                : 'You are asking for guidance before choosing the next planning step.',
            'response' => $timeLabelForFallback !== null
                ? "Right now, it's {$timeLabelForFallback} for you. I can help you turn this into a clear next action."
                : 'I can help make this feel more manageable with one clear next step.',
            'suggested_next_actions' => $this->enforceSuggestedNextActions([], $resolvedIntent),
        ];
    }

    private function resolveGuidanceMode(string $userMessage, ?string $forcedMode = null): string
    {
        if ($forcedMode !== null && $forcedMode !== '') {
            return $this->normalizeGuidanceMode($forcedMode);
        }

        $normalized = mb_strtolower(trim($userMessage));
        if ($normalized === '') {
            return self::MODE_FRIENDLY_GENERAL;
        }

        if ($this->isLikelyGibberish($userMessage)) {
            return self::MODE_GIBBERISH_UNCLEAR;
        }

        if ($this->isLikelyOffTopicPrompt($userMessage)) {
            return self::MODE_OFF_TOPIC;
        }

        if ($this->isLikelyFriendlyGeneralPrompt($userMessage) || $this->isLikelyTimeQuery($userMessage)) {
            return self::MODE_FRIENDLY_GENERAL;
        }

        // Keep classifier fallback for truly ambiguous, longer prompts only.
        if (mb_strlen($normalized) < 40) {
            return self::MODE_FRIENDLY_GENERAL;
        }

        try {
            $modeResponse = Prism::structured()
                ->using($this->resolveProvider(), $this->resolveModel())
                ->withPrompt(
                    'Classify this user message for guidance mode. '.
                    'Use one of: friendly_general, gibberish_unclear, off_topic. '.
                    'Return JSON only.'."\n\n".
                    'USER MESSAGE: '.$userMessage
                )
                ->withSchema(TaskAssistantSchemas::generalGuidanceModeSchema())
                ->withClientOptions($this->resolveClientOptionsForRoute('general_guidance_target'))
                ->asStructured();

            $structured = is_array($modeResponse->structured ?? null) ? $modeResponse->structured : [];
            $confidence = is_numeric($structured['confidence'] ?? null) ? (float) $structured['confidence'] : 0.0;
            $mode = $this->normalizeGuidanceMode((string) ($structured['guidance_mode'] ?? ''));

            return $confidence >= 0.6 ? $mode : self::MODE_FRIENDLY_GENERAL;
        } catch (\Throwable) {
            return self::MODE_FRIENDLY_GENERAL;
        }
    }

    private function isLikelyFriendlyGeneralPrompt(string $userMessage): bool
    {
        $msg = mb_strtolower(trim($userMessage));
        if ($msg === '') {
            return true;
        }

        return preg_match('/^(hi|hello|hey|yo|help|i need help|assist me|overwhelmed|hello brother)([!?.]|\s)*$/u', $msg) === 1;
    }

    private function normalizeGuidanceMode(string $mode): string
    {
        $normalized = mb_strtolower(trim($mode));

        return match ($normalized) {
            self::MODE_GIBBERISH_UNCLEAR, 'gibberish', 'unclear' => self::MODE_GIBBERISH_UNCLEAR,
            self::MODE_OFF_TOPIC, 'offtopic' => self::MODE_OFF_TOPIC,
            default => self::MODE_FRIENDLY_GENERAL,
        };
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

    private function buildAcknowledgement(string $userMessage): string
    {
        $normalized = trim($userMessage);
        if ($normalized === '') {
            return 'Thanks for your message.';
        }

        if ($this->isLikelyGibberish($userMessage)) {
            return "Thanks for reaching out. I couldn't fully understand that message yet.";
        }

        if ($this->isLikelyTimeQuery($userMessage)) {
            return 'Thanks for asking about your current time.';
        }

        $topic = trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);
        $topic = mb_substr($topic, 0, 80);

        return "Thanks for sharing what you need help with: \"{$topic}\".";
    }

    private function defaultNextStepGuidance(string $redirectTarget, string $seed = ''): string
    {
        $variants = [
            'I can help you prioritize your tasks or schedule time blocks for them. Which one do you want to start with first?',
            "If you're ready, we can either prioritize your tasks or schedule time blocks. Which should we do first?",
            'We can take the next step by prioritizing your tasks or scheduling time blocks. Which one would you like first?',
        ];
        $pick = $this->pickVariantIndex($variants, $seed !== '' ? $seed : $redirectTarget);

        return match ($redirectTarget) {
            'prioritize' => 'Nice progress so far. I can prioritize your tasks first or schedule time blocks for them next. Which one do you want to start with?',
            'schedule' => 'Nice progress so far. I can schedule time blocks first or prioritize your tasks first. Which one do you want to start with?',
            default => $variants[$pick],
        };
    }

    private function sanitizeUserFacingLanguage(string $value): string
    {
        $sanitized = trim($value);
        if ($sanitized === '') {
            return '';
        }

        $replacements = [
            '/\bsnapshot\b/iu' => 'your list',
            '/\bsnapshot data\b/iu' => 'your list',
            '/\bjson\b/iu' => 'details',
            '/\bbackend\b/iu' => 'assistant side',
            '/\bdatabase\b/iu' => 'your list',
            '/\bschema\b/iu' => 'format',
            '/\bfunction signature(s)?\b/iu' => 'internal details',
            '/\btool call(s)?\b/iu' => 'assistant steps',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $sanitized = preg_replace($pattern, $replacement, $sanitized) ?? $sanitized;
        }

        return trim(preg_replace('/\s+/u', ' ', $sanitized) ?? $sanitized);
    }

    private function enforceFriendlyGeneralHighLevelGuidance(string $guidance): string
    {
        $normalized = trim($guidance);
        if ($normalized === '') {
            return $this->defaultNextStepGuidance('', $guidance);
        }

        $looksSpecific = preg_match('/\b(task|todo)\s*#?\d+\b/ui', $normalized) === 1
            || preg_match('/\bexactly\s+\d+\b/ui', $normalized) === 1
            || str_contains($normalized, '"');

        if ($looksSpecific) {
            return $this->defaultNextStepGuidance('', $guidance);
        }

        return trim($normalized);
    }

    private function enforceUnifiedNextStepGuidance(string $guidance, string $seed = ''): string
    {
        $normalized = trim($guidance);
        if ($normalized === '') {
            return $this->defaultNextStepGuidance('either', $seed);
        }

        $lower = mb_strtolower($normalized);
        $mentionsPrioritize = str_contains($lower, 'priorit');
        $mentionsSchedule = str_contains($lower, 'schedule') || str_contains($lower, 'time block');
        $hasChoicePrompt = str_contains($lower, 'which') && str_contains($lower, 'first');

        if (! $mentionsPrioritize || ! $mentionsSchedule || ! $hasChoicePrompt) {
            return $this->defaultNextStepGuidance('either', $seed !== '' ? $seed : $guidance);
        }

        return $normalized;
    }

    private function normalizeIntent(string $intent): string
    {
        $normalized = mb_strtolower(trim($intent));

        return match ($normalized) {
            self::INTENT_OUT_OF_SCOPE, 'off_topic', 'offtopic' => self::INTENT_OUT_OF_SCOPE,
            self::INTENT_UNCLEAR, 'gibberish_unclear', 'gibberish' => self::INTENT_UNCLEAR,
            default => self::INTENT_TASK,
        };
    }

    private function intentFromMode(string $mode): string
    {
        return match ($mode) {
            self::MODE_OFF_TOPIC => self::INTENT_OUT_OF_SCOPE,
            self::MODE_GIBBERISH_UNCLEAR => self::INTENT_UNCLEAR,
            default => self::INTENT_TASK,
        };
    }

    /**
     * @return list<string>|null
     */
    private function normalizeSuggestedNextActions(mixed $suggestedNextActions): ?array
    {
        if (! is_array($suggestedNextActions)) {
            return null;
        }

        $clean = array_values(array_filter(array_map(static function (mixed $v): string {
            return trim((string) $v);
        }, $suggestedNextActions), static fn (string $v): bool => $v !== ''));

        if ($clean === []) {
            return null;
        }

        $clean = array_slice($clean, 0, 3);
        $clean = array_values(array_map(fn (string $s): string => $this->clampString($s, self::MAX_GENERAL_GUIDANCE_SUGGESTED_REPLY_CHARS), $clean));
        $clean = array_values(array_filter($clean, static fn (string $s): bool => mb_strlen($s) >= 1));

        return $clean === [] ? null : $clean;
    }

    /**
     * @param  list<string>|null  $actions
     * @return list<string>
     */
    private function enforceSuggestedNextActions(?array $actions, string $intent): array
    {
        $clean = $actions ?? [];
        $clean = array_values(array_filter(array_map(
            fn (string $line): string => $this->sanitizeUserFacingLanguage($this->normalizeGeneralGuidanceText($line)),
            $clean
        ), static fn (string $line): bool => $line !== ''));

        $mustInclude = [
            'Prioritize my tasks.',
            'Schedule time blocks for my tasks.',
        ];

        $intentSpecific = match ($intent) {
            self::INTENT_OUT_OF_SCOPE => ['Tell me one real task I need to do today.'],
            self::INTENT_UNCLEAR => ['Rephrase what you need help with in one short sentence.'],
            default => ['Tell me what to do first today.'],
        };

        $contextual = null;
        foreach ($clean as $line) {
            $lower = mb_strtolower($line);
            $isPrioritize = str_contains($lower, 'priorit');
            $isSchedule = str_contains($lower, 'schedule') || str_contains($lower, 'time block');
            if (! $isPrioritize && ! $isSchedule) {
                $contextual = $line;
                break;
            }
        }

        if ($contextual === null && $intentSpecific !== []) {
            $contextual = $intentSpecific[0];
        }

        $ordered = [];
        if ($contextual !== null && trim($contextual) !== '') {
            $ordered[] = $contextual;
        }
        $ordered = array_merge($ordered, $mustInclude);

        return array_slice(array_values(array_unique($ordered)), 0, 3);
    }

    private function lightweightAcknowledgementPolish(string $intent, string $acknowledgement): string
    {
        $ack = trim($acknowledgement);
        if ($ack !== '') {
            return $ack;
        }

        return match ($intent) {
            self::INTENT_OUT_OF_SCOPE => "That's an interesting question.",
            self::INTENT_UNCLEAR => "I didn't quite catch that yet.",
            default => 'I hear you.',
        };
    }

    private function lightweightFramingPolish(string $intent, string $framing, string $userMessage): string
    {
        $normalized = trim($framing);
        if ($normalized !== '') {
            return $normalized;
        }
        $topicLabel = $this->inferTopicLabel($userMessage);

        return match ($intent) {
            self::INTENT_OUT_OF_SCOPE => $topicLabel !== ''
                ? "You're asking about {$topicLabel}, which falls outside task planning."
                : "You're asking about a topic outside task planning.",
            self::INTENT_UNCLEAR => "Your message doesn't form a clear request yet.",
            default => "You're asking for guidance before choosing a concrete planning action.",
        };
    }

    private function lightweightResponsePolish(string $intent, string $response, string $userMessage): string
    {
        $normalized = trim($response);
        $ack = 'I hear you.';

        if ($normalized === '') {
            $normalized = $ack;
        }

        if ($intent === self::INTENT_OUT_OF_SCOPE) {
            if (mb_stripos($normalized, 'i hear you') === false
                && mb_stripos($normalized, "i'm sorry") === false
                && mb_stripos($normalized, "that's an interesting") === false) {
                $normalized = $ack.' '.$normalized;
            }
            if (mb_stripos($normalized, "can't help") === false && mb_stripos($normalized, 'cannot help') === false) {
                $normalized .= " I can't help with that topic.";
            }
            if (mb_stripos($normalized, 'task assistant') === false) {
                $normalized .= " I'm a task assistant focused on task planning.";
            }
        } elseif ($intent === self::INTENT_UNCLEAR) {
            if ($normalized === '') {
                $normalized = 'I did not fully understand what you meant yet.';
            }
            $needsRephraseCue = mb_stripos($normalized, 'rephrase') === false
                && mb_stripos($normalized, 'clarify') === false
                && mb_stripos($normalized, 'say that again') === false;
            if ($needsRephraseCue) {
                $normalized .= ' Please rephrase it in one short sentence.';
            }
        } else {
            if ($normalized === '') {
                $normalized = 'Let us start with one manageable step and build from there.';
            }
        }

        $normalized = $this->sanitizeUserFacingLanguage($normalized);
        $normalized = $this->normalizeGeneralGuidanceText($normalized);
        $normalized = rtrim($normalized, " \t\n\r\0\x0B");

        return $this->clampString($normalized, self::MAX_GENERAL_GUIDANCE_MESSAGE_CHARS);
    }

    private function humanizeTone(string $text, string $intent, string $userMessage): string
    {
        $value = $this->normalizeGeneralGuidanceText($text);
        if ($value === '') {
            return '';
        }

        // Avoid robotic lead-ins and overly formal assistant language.
        $value = preg_replace('/\b(task planning|planning action)\b/iu', 'your next step', $value) ?? $value;
        $value = preg_replace('/\bI can assist you\b/iu', 'I can help', $value) ?? $value;
        $value = preg_replace('/\bYou are asking\b/iu', "You're asking", $value) ?? $value;
        $value = preg_replace('/\bLet us\b/iu', "Let's", $value) ?? $value;

        // If output is too generic, add a short contextual anchor (paraphrased).
        if ($this->looksGeneric($value)) {
            $anchor = $this->inferTopicLabel($userMessage);
            if ($anchor !== '') {
                $value = rtrim($value, '.').". We can focus on {$anchor} first.";
            }
        }

        // Keep out-of-scope tone boundary gentle and student-oriented.
        if ($intent === self::INTENT_OUT_OF_SCOPE && mb_stripos($value, "can't help") === false) {
            $value .= " I can't help with that topic, but I can help you move forward with your tasks.";
        }

        return $this->clampString($this->normalizeGeneralGuidanceText($value), self::MAX_GENERAL_GUIDANCE_MESSAGE_CHARS);
    }

    private function looksGeneric(string $text): bool
    {
        $lower = mb_strtolower($text);
        $genericMarkers = [
            'i hear you',
            'i can help',
            'one clear next step',
            'your next step',
            'task assistant',
        ];

        $hits = 0;
        foreach ($genericMarkers as $marker) {
            if (str_contains($lower, $marker)) {
                $hits++;
            }
        }

        return $hits >= 2 && mb_strlen($lower) < 220;
    }

    private function inferTopicLabel(string $userMessage): string
    {
        $message = mb_strtolower($this->extractUserTopicSnippet($userMessage));
        if ($message === '') {
            return '';
        }

        $map = [
            'time management' => ['time', 'schedule', 'day plan'],
            'feeling overwhelmed' => ['overwhelmed', 'stressed', 'stress', 'burnout'],
            'an unclear request' => ['asd', 'qwe', 'dwad', 'bkwajh'],
            'an unrelated topic' => ['ufc', 'fighter', 'president', 'politics', 'relationship', 'partner'],
            'task planning' => ['task', 'prioritize', 'next step', 'to do', 'todo'],
        ];

        foreach ($map as $label => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($message, $needle)) {
                    return $label;
                }
            }
        }

        return '';
    }

    private function extractUserTopicSnippet(string $userMessage): string
    {
        $source = (string) preg_replace('/\s*OFF_TOPIC_GUARDRAIL:.*$/s', '', $userMessage);
        $normalized = trim(preg_replace('/\s+/u', ' ', $source) ?? $source);
        if ($normalized === '') {
            return '';
        }

        $snippet = mb_substr($normalized, 0, 80);
        $snippet = trim($snippet, " \t\n\r\0\x0B\"'?!.");

        return $snippet;
    }

    /**
     * @param  list<string>  $variants
     */
    private function pickVariantIndex(array $variants, string $seed): int
    {
        if ($variants === []) {
            return 0;
        }

        $hash = crc32($seed);
        if (! is_int($hash)) {
            return 0;
        }

        return abs($hash) % count($variants);
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

    private function isLikelyOffTopicPrompt(string $userMessage): bool
    {
        $msg = mb_strtolower($userMessage);
        if ($msg === '') {
            return false;
        }

        $taskKeywords = [
            'task', 'tasks', 'prioritize', 'priority', 'schedule', 'time block', 'time blocks',
            'calendar', 'study', 'deadline', 'project', 'focus', 'plan my day', 'to do', 'todo',
        ];
        foreach ($taskKeywords as $keyword) {
            if (mb_stripos($msg, $keyword) !== false) {
                return false;
            }
        }

        $offTopicMarkers = [
            'best ', 'who is', 'why he', 'why she', 'relationship', 'politics', 'president',
            'shoes', 'cook', 'martial artist', 'love me',
        ];
        foreach ($offTopicMarkers as $marker) {
            if (mb_stripos($msg, $marker) !== false) {
                return true;
            }
        }

        return false;
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

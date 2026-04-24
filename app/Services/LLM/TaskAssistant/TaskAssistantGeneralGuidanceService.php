<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\User;
use App\Support\LLM\TaskAssistantPrioritizeOutputDefaults;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;

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

    private const MAX_GENERAL_GUIDANCE_SUGGESTED_REPLY_CHARS = 140;

    public function __construct(
        private readonly TaskAssistantPromptData $promptData,
        private readonly TaskAssistantQuickChipResolver $quickChipResolver,
    ) {}

    /**
     * @return array{
     *   intent: string,
     *   acknowledgement: string,
     *   message: string,
     *   suggested_next_actions: list<string>,
     *   next_options: string,
     *   next_options_chip_texts: list<string>
     * }
     */
    public function generateGeneralGuidance(
        User $user,
        string $userMessage,
        ?string $forcedMode = null,
    ): array {
        $timeContext = '';
        $timeLabelForFallback = null;
        if ($this->isLikelyTimeQuery($userMessage)) {
            $promptData = $this->promptData->forUser($user);
            $timezone = (string) ($promptData['userContext']['timezone'] ?? config('app.timezone', 'UTC'));
            $now = CarbonImmutable::now($timezone);
            $timeLabel = $now->format('g:i A');
            $timeLabelForFallback = $timeLabel;
            $timeContext = " Right now, it's {$timeLabel} for you.";
        }

        $resolvedMode = $this->resolveGuidanceMode($userMessage, $forcedMode);
        $resolvedIntent = $this->intentFromMode($resolvedMode);
        $intent = $this->isLikelyEmotionalOffDomain($userMessage)
            ? self::INTENT_OUT_OF_SCOPE
            : $resolvedIntent;
        $acknowledgement = match ($intent) {
            self::INTENT_OUT_OF_SCOPE => "That's outside what I can help with directly.",
            self::INTENT_UNCLEAR => "I didn't quite catch that yet.",
            default => 'I hear you.',
        };
        $message = match ($intent) {
            self::INTENT_OUT_OF_SCOPE => 'I can still help with school tasks by deciding what to do first or planning time blocks.',
            self::INTENT_UNCLEAR => 'Say what you want in one short sentence, and I will turn it into a clear next step.',
            default => $timeContext !== ''
                ? trim($timeContext).' I can help you turn this into a clear next action.'
                : 'Tell me what feels most urgent, and I will help you pick the next move.',
        };

        if ($timeLabelForFallback !== null && $intent !== self::INTENT_OUT_OF_SCOPE) {
            $message = "Right now, it's {$timeLabelForFallback} for you. I can help you turn this into a clear next action.";
        }

        $suggestedNextActions = $this->enforceSuggestedNextActions([], $intent);
        $nextOptions = $this->finalizeNextOptionsString('');

        Log::info('task-assistant.general_guidance.generate_deterministic', [
            'layer' => 'llm_guidance',
            'user_id' => $user->id,
            'resolved_mode' => $resolvedMode,
            'resolved_intent' => $intent,
        ]);

        return [
            'intent' => $intent,
            'acknowledgement' => $this->clampAtSentenceBoundary($acknowledgement, 220),
            'message' => $this->clampAtSentenceBoundary($message, self::MAX_GENERAL_GUIDANCE_MESSAGE_CHARS),
            'suggested_next_actions' => $suggestedNextActions,
            'next_options' => $nextOptions,
            'next_options_chip_texts' => $this->deterministicGeneralGuidanceChipTexts($user),
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

        if ($this->isLikelyNoisyUnclearPrompt($userMessage)) {
            return self::MODE_GIBBERISH_UNCLEAR;
        }

        if ($this->isLikelyOffTopicPrompt($userMessage)) {
            return self::MODE_OFF_TOPIC;
        }

        if ($this->isLikelyFriendlyGeneralPrompt($userMessage) || $this->isLikelyTimeQuery($userMessage)) {
            return self::MODE_FRIENDLY_GENERAL;
        }

        return self::MODE_FRIENDLY_GENERAL;
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

    private function enforceOutOfScopeNoAdvice(string $message, string $userMessage): string
    {
        $value = $this->normalizeGeneralGuidanceText($message);
        if ($value === '') {
            return "I can't help with that topic, but I can help you move forward with your tasks.";
        }

        $lower = mb_strtolower($value);
        $looksLikeRecommendation = preg_match(
            '/\b(i recommend|recommend|best (choice|option)|best\s+[a-z0-9_-]+|top (choice|pick)|stands out|you should buy|buy|purchase|go with|get the|cooledown)\b/u',
            $lower
        ) === 1;

        if (! $looksLikeRecommendation) {
            return $value;
        }

        $topic = $this->inferTopicLabel($userMessage);
        $topicLine = $topic !== '' ? "I can't help with {$topic}, but I can help you move forward with your tasks." : "I can't help with that topic, but I can help you move forward with your tasks.";

        return $topicLine;
    }

    private function enforceOutOfScopeSafeAcknowledgement(string $acknowledgement): string
    {
        $value = $this->normalizeGeneralGuidanceText($acknowledgement);
        if ($value === '') {
            return "That's an interesting question.";
        }

        $lower = mb_strtolower($value);
        $looksLikeRecommendation = preg_match(
            '/\b(i recommend|recommend|best (choice|option)|top (choice|pick)|stands out|you should buy|buy|purchase|go with|get the|cooledown)\b/u',
            $lower
        ) === 1;

        if (! $looksLikeRecommendation) {
            return $value;
        }

        return "That's an interesting question.";
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
        $clean = array_values(array_map(fn (string $s): string => $this->clampAtSentenceBoundary($s, self::MAX_GENERAL_GUIDANCE_SUGGESTED_REPLY_CHARS), $clean));
        $clean = array_values(array_filter($clean, static fn (string $s): bool => mb_strlen($s) >= 1));

        return $clean === [] ? null : $clean;
    }

    /**
     * @param  list<string>|null  $actions
     * @return list<string>
     */
    private function enforceSuggestedNextActions(?array $actions, string $intent): array
    {
        $mustInclude = [
            'Prioritize my tasks.',
            'Schedule time blocks for my tasks.',
        ];

        if ($intent === self::INTENT_UNCLEAR) {
            return $mustInclude;
        }

        $clean = $actions ?? [];
        $clean = array_values(array_filter(array_map(
            fn (string $line): string => $this->sanitizeUserFacingLanguage($this->normalizeGeneralGuidanceText($line)),
            $clean
        ), static fn (string $line): bool => $line !== ''));

        $intentSpecific = match ($intent) {
            self::INTENT_OUT_OF_SCOPE => ['Tell me one real task I need to do today.'],
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

        if ($contextual !== null && ! $this->isVerbLedAction($contextual)) {
            $contextual = $intentSpecific[0] ?? 'Tell me one thing you need to get done today.';
        }

        $ordered = [];
        if ($contextual !== null && trim($contextual) !== '') {
            $ordered[] = $contextual;
        }
        $ordered = array_merge($ordered, $mustInclude);

        return array_slice(array_values(array_unique($ordered)), 0, 3);
    }

    private function isVerbLedAction(string $line): bool
    {
        $value = trim($line);
        if ($value === '') {
            return false;
        }

        return preg_match('/^(tell|share|list|pick|ask|show|help|rephrase|schedule|prioritize)\b/i', $value) === 1;
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

    private function lightweightMessagePolish(string $intent, string $message, string $userMessage): string
    {
        $normalized = trim($message);
        if ($normalized === '') {
            return '';
        }

        if ($intent === self::INTENT_OUT_OF_SCOPE) {
            if (mb_stripos($normalized, "can't help") === false && mb_stripos($normalized, 'cannot help') === false) {
                $normalized = "I can't help with that topic. ".$normalized;
            }
        } elseif ($intent === self::INTENT_UNCLEAR) {
            $needsRephraseCue = mb_stripos($normalized, 'rephrase') === false
                && mb_stripos($normalized, 'clarify') === false
                && mb_stripos($normalized, 'say that again') === false;
            if ($needsRephraseCue) {
                $normalized .= ' Please rephrase it in one short sentence.';
            }
        }

        $normalized = $this->sanitizeUserFacingLanguage($normalized);
        $normalized = $this->normalizeGeneralGuidanceText($normalized);

        return $this->clampString($normalized, self::MAX_GENERAL_GUIDANCE_MESSAGE_CHARS);
    }

    private function humanizeTone(string $text, string $intent, string $userMessage): string
    {
        $value = $this->normalizeGeneralGuidanceText($text);
        if ($value === '') {
            return '';
        }

        // Avoid robotic lead-ins and overly formal assistant language.
        $value = preg_replace('/\bI can assist you\b/iu', 'I can help', $value) ?? $value;
        $value = preg_replace('/\bYou are asking\b/iu', "You're asking", $value) ?? $value;
        $value = preg_replace('/\bLet us\b/iu', "Let's", $value) ?? $value;
        $value = preg_replace('/\bconcrete\s+your next step\b/iu', 'concrete next step', $value) ?? $value;
        $value = preg_replace('/\bthe your\b/iu', 'your', $value) ?? $value;

        // If output is too generic, add a short contextual anchor (paraphrased).
        if ($this->looksGeneric($value)) {
            $anchor = $this->inferTopicLabel($userMessage);
            if ($anchor !== '') {
                $value = rtrim($value, '.').". We can focus on {$anchor} first.";
            }
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

    private function isLikelyNoisyUnclearPrompt(string $userMessage): bool
    {
        $msg = mb_strtolower(trim($userMessage));
        if ($msg === '') {
            return false;
        }

        $msg = (string) preg_replace('/\s*GREETING_GUARDRAIL:.*$/s', '', $msg);

        if ($this->isLikelyTimeQuery($msg)) {
            return false;
        }

        $letters = preg_match_all('/\p{L}/u', $msg);
        $spaces = substr_count($msg, ' ');
        $hasProfanity = preg_match('/\b(tangina|putangina|gago|ulol|bwisit|fuck|shit)\b/u', $msg) === 1;
        $hasClearOffTopicEntity = preg_match('/\b(president|ufc|fighter|politics|election|nba|movie|celebrity)\b/u', $msg) === 1;
        $hasTaskKeyword = preg_match('/\b(task|tasks|prioritize|schedule|time block|todo|to do|plan)\b/u', $msg) === 1;

        if ($hasTaskKeyword || $hasClearOffTopicEntity) {
            return false;
        }

        // Multi-word noisy strings and profanity-only messages should prefer unclear.
        if (($spaces >= 1 && $letters <= 28) || $hasProfanity) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<string>|null  $suggestedNextActions
     * @return array{0: string, 1: string, 2: string, 3: list<string>|null}
     */
    private function enforceGreetingOnlyOutput(
        string $intent,
        string $acknowledgement,
        string $message,
        ?array $suggestedNextActions,
        string $nextOptionsForBlob = '',
    ): array {
        $intent = self::INTENT_TASK;

        $cleanAck = $this->normalizeGeneralGuidanceText($acknowledgement);
        if ($cleanAck === '' || mb_stripos($cleanAck, 'tasklyst') === false) {
            $cleanAck = "Hi, I'm TaskLyst—your task assistant.";
        }

        $forbidden = [
            'based on your list',
            'your list data',
            'due today',
            'due tomorrow',
            'high-priority',
            'highest-priority',
            'priority tasks',
            'review meeting notes',
            'submit expense',
            'create a new task',
        ];

        $blob = mb_strtolower($message.' '.implode(' ', $suggestedNextActions ?? [])."\n".$nextOptionsForBlob);
        $hasForbidden = false;
        foreach ($forbidden as $needle) {
            if (str_contains($blob, $needle)) {
                $hasForbidden = true;
                break;
            }
        }

        if ($hasForbidden) {
            $message = "If you tell me what you want to do, I can help you prioritize tasks or schedule time blocks. If you're not sure yet, share one thing you need to get done today, and I'll help you pick a clear next step.";
        }

        $neutralContextual = 'Tell me one thing you need to get done today.';
        $suggestedNextActions = $suggestedNextActions ?? [];
        $suggestedNextActions = array_values(array_filter(array_map(
            fn (mixed $line): string => $this->normalizeGeneralGuidanceText((string) $line),
            $suggestedNextActions
        ), static fn (string $line): bool => $line !== ''));

        $suggestedNextActions = array_values(array_filter($suggestedNextActions, static function (string $line): bool {
            $lower = mb_strtolower($line);

            return ! str_contains($lower, 'create a new task')
                && ! str_contains($lower, 'based on your list')
                && ! str_contains($lower, 'your list data');
        }));

        array_unshift($suggestedNextActions, $neutralContextual);
        $suggestedNextActions = array_slice(array_values(array_unique($suggestedNextActions)), 0, 3);

        return [$intent, $cleanAck, $message, $suggestedNextActions];
    }

    private function stripBoundaryFromAcknowledgement(string $acknowledgement): string
    {
        $value = $this->normalizeGeneralGuidanceText($acknowledgement);
        if ($value === '') {
            return '';
        }

        $patterns = [
            '/\\b(can\\x27?t help|cannot help)\\b/iu',
            '/\\bout of scope\\b/iu',
            '/\\boff[- ]topic\\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                // If the acknowledgement contains boundary language, keep only the first sentence fragment before it.
                $value = preg_replace($pattern, '', $value) ?? $value;
                break;
            }
        }

        $value = trim(rtrim($value, " \t\n\r\0\x0B-:;,."));
        if ($value === '') {
            return 'I hear you.';
        }

        return $value;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function enforceUnclearQuality(string $acknowledgement, string $message): array
    {
        $ack = $this->normalizeGeneralGuidanceText($acknowledgement);
        $msg = $this->normalizeGeneralGuidanceText($message);

        $lower = mb_strtolower($ack.' '.$msg);
        $forbidden = [
            'based on your list',
            'your list data',
            'high-priority',
            'highest-priority',
            'due today',
            'due tomorrow',
            'overdue',
        ];
        foreach ($forbidden as $needle) {
            if (str_contains($lower, $needle)) {
                $ack = "I didn't quite catch that yet.";
                $msg = 'Rephrase what you mean in one short sentence, and I’ll help you take the next step.';
                break;
            }
        }

        // Ensure the message actually asks for a rephrase.
        if (mb_stripos($msg, 'rephrase') === false && mb_stripos($msg, 'say') === false && mb_stripos($msg, 'one short sentence') === false) {
            $msg = rtrim($msg, '.').' Please rephrase it in one short sentence.';
        }

        return [$ack, $msg];
    }

    /**
     * @param  list<string>|null  $actions
     * @return list<string>|null
     */
    private function normalizeClausalSuggestedNextActions(?array $actions, string $intent): ?array
    {
        if ($actions === null) {
            return null;
        }

        $clean = array_values(array_filter(array_map(
            fn (string $line): string => $this->normalizeGeneralGuidanceText($line),
            $actions
        ), static fn (string $line): bool => $line !== ''));

        $rewritten = [];
        foreach ($clean as $line) {
            $lower = mb_strtolower($line);

            // Skip required actions; enforceSuggestedNextActions will add them.
            if (str_contains($lower, 'priorit') || str_contains($lower, 'schedule') || str_contains($lower, 'time block')) {
                continue;
            }

            $looksNominal = preg_match('/^(review|overview|prioritization|scheduling|tasks|task list|deadlines)\\b/iu', $line) !== 1
                && preg_match('/\\b(please|tell|share|list|pick|ask)\\b/iu', $line) !== 1;

            if ($looksNominal && mb_strlen($line) <= 90) {
                $line = match ($intent) {
                    self::INTENT_UNCLEAR => 'Rephrase what you mean in one short sentence.',
                    self::INTENT_OUT_OF_SCOPE => 'Tell me one real task you need to do today.',
                    default => 'Tell me one thing you need to get done today.',
                };
            }

            $rewritten[] = $line;
        }

        return $rewritten;
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

    private function clampAtSentenceBoundary(string $value, int $maxChars): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (mb_strlen($value) <= $maxChars) {
            return $value;
        }

        $slice = mb_substr($value, 0, $maxChars);
        $lastPunctuation = max(
            (int) (mb_strrpos($slice, '.') ?: 0),
            (int) (mb_strrpos($slice, '!') ?: 0),
            (int) (mb_strrpos($slice, '?') ?: 0)
        );

        if ($lastPunctuation > 20) {
            return trim(mb_substr($slice, 0, $lastPunctuation + 1));
        }

        // Fall back to the last safe whitespace so we don't cut mid-word.
        $lastSpace = mb_strrpos($slice, ' ');
        if ($lastSpace !== false && $lastSpace > 20) {
            return rtrim(mb_substr($slice, 0, $lastSpace), " \t\n\r\0\x0B.,;:-");
        }

        return rtrim($slice, " \t\n\r\0\x0B.,;:-");
    }

    /**
     * @return array{target: string, confidence: float, rationale?: string|null}
     */
    public function resolveTargetFromAnswer(
        User $user,
        string $clarifyingQuestion,
        string $userAnswer
    ): array {
        $normalized = mb_strtolower(trim($userAnswer));
        $scheduleSignals = preg_match('/\b(schedule|calendar|time block|time slot|when|later|today|tomorrow|this week|at\s+\d{1,2}(:\d{2})?\s*(am|pm)?)\b/u', $normalized) === 1;
        $prioritizeSignals = preg_match('/\b(priorit(?:y|ize)|top|first|next|urgent|important|rank|what should i do first)\b/u', $normalized) === 1;

        if ($scheduleSignals && ! $prioritizeSignals) {
            return [
                'target' => 'schedule',
                'confidence' => 0.92,
                'rationale' => 'deterministic_schedule_keywords',
            ];
        }
        if ($prioritizeSignals && ! $scheduleSignals) {
            return [
                'target' => 'prioritize',
                'confidence' => 0.92,
                'rationale' => 'deterministic_prioritize_keywords',
            ];
        }

        return [
            'target' => 'either',
            'confidence' => 0.45,
            'rationale' => 'deterministic_ambiguous_answer',
        ];
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

    private function finalizeNextOptionsString(string $nextOptions): string
    {
        $t = trim($this->normalizeGeneralGuidanceText($nextOptions));
        if ($t === '') {
            $t = (string) config(
                'task-assistant.general_guidance.default_next_options',
                'If you want, I can help you decide what to tackle first, or block time on your calendar for what matters most.'
            );
        }
        $t = $this->sanitizeUserFacingLanguage($t);

        return TaskAssistantPrioritizeOutputDefaults::clampNextField($t);
    }

    /**
     * @return list<string>
     */
    private function deterministicGeneralGuidanceChipTexts(User $user): array
    {
        $chips = $this->quickChipResolver->resolveForEmptyState(
            user: $user,
            thread: null,
            limit: 4,
        );
        $chips = $this->quickChipResolver->filterContinueStyleQuickChips($chips);
        $chips = array_values(array_slice($chips, 0, 3));

        if (count($chips) === 3) {
            return $chips;
        }

        $fallbacks = [
            TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('What should I do first'),
            TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule my most important task'),
            TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Create a plan for today'),
        ];
        while (count($chips) < 3) {
            $chips[] = $fallbacks[count($chips)];
        }

        return array_slice($chips, 0, 3);
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

    private function isLatencyBudgetExceeded(): bool
    {
        $budgetMs = max(0, (int) config('task-assistant.performance.latency_budget_ms', 0));
        if ($budgetMs <= 0 || ! app()->bound('task_assistant.run_started_at_ms')) {
            return false;
        }

        $startedAtMs = (int) app('task_assistant.run_started_at_ms');
        $elapsedMs = (int) round(microtime(true) * 1000) - $startedAtMs;

        return $elapsedMs >= $budgetMs;
    }
}

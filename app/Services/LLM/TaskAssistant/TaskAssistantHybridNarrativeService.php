<?php

namespace App\Services\LLM\TaskAssistant;

use App\Support\LLM\TaskAssistantListingDefaults;
use App\Support\LLM\TaskAssistantSchemas;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Structured LLM calls that only narrate already-computed plans (prioritize items or schedule blocks).
 */
final class TaskAssistantHybridNarrativeService
{
    /**
     * Prevent over-claiming assistant visibility in narrative fields.
     */
    private function containsVisibilityOverclaim(string $text): bool
    {
        $t = mb_strtolower($text);

        return (bool) preg_match(
            '/\b(i\s+(have\s+)?(reviewed|looked|seen|checked)|i\'?ve\s+(reviewed|looked)|i\s+see\s+you\s+have|i\s+see\s+that\s+you\s+have|taken\s+a\s+look|your\s+(current\s+)?to-?do\s+list)\b/u',
            $t
        );
    }

    /**
     * When framing must be discarded, pick first-person wording that varies by request (stable per diversifier).
     */
    private function firstPersonFramingFallback(int $listedTaskCount, string $diversifier): string
    {
        $n = abs(crc32($diversifier)) % 4;

        if ($listedTaskCount <= 1) {
            return match ($n) {
                0 => "I'd start with what's below—it's in an order that keeps things straightforward.",
                1 => 'I recommend tackling the item below first; you can adjust if something more urgent appears.',
                2 => "I suggest beginning with the next step shown here so you don't have to guess where to start.",
                default => "Here's what I'd do first: use the order below and take it one step at a time.",
            };
        }

        return match ($n) {
            0 => "I'd work through these in the order below—it balances urgency with what you said matters.",
            1 => 'I recommend moving from top to bottom; the sequence is there to cut down decision fatigue.',
            2 => 'I suggest treating this list as your default path—you can swap a step if you need to.',
            default => "Here's how I'd approach this: start at the top, then keep going down as you finish each piece.",
        };
    }

    private function sanitizeFraming(string $framing, int $listedTaskCount, string $diversifier = ''): string
    {
        $framing = trim($framing);
        $seed = $diversifier !== '' ? $diversifier : $framing;

        if ($framing === '' || $this->containsVisibilityOverclaim($framing)) {
            return $this->firstPersonFramingFallback($listedTaskCount, $seed);
        }

        // Soften "based on your whole list" openers into first-person assistant voice; keep the model's rest of the sentence.
        $rewritten = preg_replace('/^based on your current priorities,?\s+/iu', "I'd suggest you ", $framing, 1);
        $rewritten = is_string($rewritten) ? $rewritten : $framing;
        $rewritten = preg_replace('/^based on your upcoming tasks,?\s+/iu', "I'd suggest you ", $rewritten, 1);
        $rewritten = is_string($rewritten) ? $rewritten : $framing;
        $rewritten = preg_replace('/^based on your tasks,?\s+/iu', "For this request, I'd suggest you ", $rewritten, 1);
        $framing = trim(is_string($rewritten) ? $rewritten : $framing);

        if ($this->containsVisibilityOverclaim($framing)) {
            return $this->firstPersonFramingFallback($listedTaskCount, $seed);
        }

        // Replace impersonal brochure-style openers (models often echo old templates).
        if (preg_match('/^here (is|are) your top priorit(?:y|ies) in a simple order\b/iu', $framing) === 1) {
            return $this->firstPersonFramingFallback($listedTaskCount, $seed.'|brochure_opener');
        }

        // Keep count references coherent with the actual listed items count.
        if ($listedTaskCount <= 1) {
            $framing = preg_replace('/\btop\s+\d+\b/iu', 'top priority', $framing) ?? $framing;
            $framing = preg_replace('/\b(top\s+three|top\s+3)\b/iu', 'top priority', $framing) ?? $framing;
        }

        return $framing;
    }

    /**
     * Detect the common redundancy pattern where both acknowledgment and framing
     * say essentially the same "I understand you're overwhelmed" sentence.
     */
    private function containsOverwhelmedEmotion(string $text): bool
    {
        $t = mb_strtolower($text);

        return (bool) preg_match(
            '/\b(overwhelmed|anxious|stressed|worried|panicked|frustrated|stuck|nervous|swamped)\b/u',
            $t
        );
    }

    private function startsWithIUnderstand(string $text): bool
    {
        return (bool) preg_match('/^\s*i\s+understand\b/iu', $text);
    }

    /**
     * If acknowledgment and framing overlap heavily, rewrite framing into a
     * more actionable sentence (while staying student-safe and grounded).
     */
    private function dedupeAcknowledgmentAndFraming(?string $acknowledgment, string $framing, array $items): string
    {
        if ($acknowledgment === null) {
            return $framing;
        }

        $ack = trim($acknowledgment);
        if ($ack === '') {
            return $framing;
        }

        if (! $this->startsWithIUnderstand($ack) || ! $this->startsWithIUnderstand($framing)) {
            return $framing;
        }

        if (! $this->containsOverwhelmedEmotion($ack) || ! $this->containsOverwhelmedEmotion($framing)) {
            return $framing;
        }

        $hasOverdue = false;
        $hasHighPriority = false;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $duePhrase = mb_strtolower(trim((string) ($item['due_phrase'] ?? '')));
            if ($duePhrase !== '' && mb_stripos($duePhrase, 'overdue') !== false) {
                $hasOverdue = true;
            }

            $priority = mb_strtolower(trim((string) ($item['priority'] ?? '')));
            if ($priority === 'high') {
                $hasHighPriority = true;
            }
        }

        if ($hasOverdue || $hasHighPriority) {
            return 'Start with what\'s most overdue or high priority, so you feel caught up sooner.';
        }

        return 'Start with your top priority, so you feel caught up sooner.';
    }

    /**
     * Detect “bundling” language even when titles are not mentioned.
     */
    private function actionImpliesMultipleTasks(string $action): bool
    {
        $t = mb_strtolower($action);

        return (bool) preg_match(
            '/\b(both|the\s+first\s+two|first\s+two|first\s*2|two\s+tasks|two\s+of\s+them|the\s+first\s+couple|first\s+couple|first\s+few)\b/u',
            $t
        );
    }

    private function sanitizeNextOptions(string $nextOptions, int $itemsCount): string
    {
        $text = trim($nextOptions);
        if ($text === '') {
            return $itemsCount === 1
                ? 'If you want, I can schedule this for later.'
                : 'If you want, I can schedule these steps for later.';
        }

        // Strip bracketed artifacts (e.g. "[...]" from some model outputs).
        if (preg_match('/^\[(.+)\]$/us', $text, $m) === 1) {
            $text = trim((string) ($m[1] ?? $text));
        }

        // Avoid awkward “schedule this time” phrasing.
        $text = preg_replace('/\bschedule\s+this\s+time\b/iu', 'schedule this task', $text) ?? $text;

        // Avoid rescheduling tasks that were just completed.
        if (preg_match('/\bonce\b.*\b(completed|done)\b.*\breschedul(e|ing)\b/iu', $text) === 1) {
            return $itemsCount === 1
                ? 'If you want, I can schedule this for later when you have more energy.'
                : 'If you want, I can help you schedule the remaining tasks for later.';
        }

        return $text;
    }

    private function normalizeForSimilarity(string $text): string
    {
        $t = trim($text);
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        return mb_strtolower($t);
    }

    private function isEmpatheticAcknowledgment(string $text): bool
    {
        $t = $this->normalizeForSimilarity($text);
        if ($t === '') {
            return false;
        }

        return (bool) preg_match(
            '/\b(i\s+understand|i\s+hear\s+you|i\s+get\s+it|that\s+sounds|it\'?s\s+okay|you\'?re\s+not\s+alone|i\'?m\s+sorry)\b/u',
            $t
        );
    }

    private function buildDefaultEmpatheticAcknowledgment(string $userMessage): string
    {
        $t = mb_strtolower($userMessage);

        if (preg_match('/\b(stress(ed)?|stressed\s+out)\b/u', $t) === 1) {
            return 'I hear you—being stressed makes it hard to start. Let’s pick one small thing first.';
        }

        if (preg_match('/\b(overwhelmed|swamped)\b/u', $t) === 1) {
            return 'I get it—this feels overwhelming. Let’s pick one small thing first.';
        }

        if (preg_match('/\b(anxious|panic(ked)?|panicked)\b/u', $t) === 1) {
            return 'I hear you—this feels heavy. Let’s start with one small step.';
        }

        return 'I hear you—this is a lot. Let’s start with one small step.';
    }

    /**
     * Narrative refinement for daily schedule (deterministic proposals already fixed).
     *
     * @param  Collection<int, \Prism\Prism\ValueObjects\Messages\UserMessage|\Prism\Prism\ValueObjects\Messages\AssistantMessage>  $historyMessages
     * @param  array<string, mixed>  $promptData
     * @return array{
     *   summary: string,
     *   assistant_note: string|null,
     *   reasoning: string|null,
     *   strategy_points: list<string>,
     *   suggested_next_steps: list<string>,
     *   assumptions: list<string>
     * }
     */
    public function refineDailySchedule(
        Collection $historyMessages,
        array $promptData,
        string $userMessageContent,
        string $blocksJson,
        string $deterministicSummary,
        int $threadId,
        int $userId,
    ): array {
        $maxRetries = max(0, (int) config('task-assistant.retry.max_retries', 2));
        $refinementSchema = TaskAssistantSchemas::scheduleNarrativeRefinementSchema();

        $horizonHint = '';
        $h = $promptData['schedule_horizon'] ?? null;
        if (is_array($h) && isset($h['start_date'], $h['end_date'], $h['label'])) {
            $horizonHint = ' The placement window is '.$h['label'].' ('.$h['start_date'].' to '.$h['end_date'].'). ';
        }

        $messages = $historyMessages->values();
        $messages->push(new UserMessage($userMessageContent));
        $messages->push(new UserMessage(
            'Here are the proposed schedule blocks (task_id/event_id values are internal and must not be mentioned). '.
            'Refine narrative fields to sound natural, supportive, and practical. Return JSON only.'.$horizonHint."\n\n".
            'Write concise, human-sounding guidance:'."\n".
            '- summary: clear overview of the suggested timing'."\n".
            '- reasoning: why this order and timing fit the requested scheduling window'."\n".
            '- strategy_points: 2-4 practical rationale points (do not mention exact times/dates)'."\n".
            '- suggested_next_steps: 2-4 actionable execution steps (do not mention exact times/dates)'."\n".
            '- assumptions: optional, only if relevant (do not mention exact times/dates)'."\n".
            '- assistant_note: friendly one-liner with encouraging tone'."\n\n".
            'BLOCKS_JSON: '.$blocksJson
        ));

        $parsedBlocks = $this->decodeBlocksJson($blocksJson);
        $deterministicNarrative = $this->buildDeterministicDailyScheduleNarrative(
            blocks: $parsedBlocks,
            promptData: $promptData,
            deterministicSummary: $deterministicSummary
        );

        // Always anchor summary/reasoning to the actual planned blocks to prevent
        // the LLM from drifting (e.g. "to 20:00" when the blocks end at 19:30).
        $summary = $deterministicNarrative['summary'];
        $assistantNote = $deterministicNarrative['assistant_note'];
        $reasoning = $deterministicNarrative['reasoning'];

        $strategyPoints = [];
        $suggestedNextSteps = [];
        $assumptions = [];

        try {
            $structuredResponse = $this->attemptStructured(
                $messages,
                $promptData,
                $refinementSchema,
                $maxRetries,
                'schedule'
            );

            $payload = $structuredResponse->structured ?? [];
            $payload = is_array($payload) ? $payload : [];

            // Summary/reasoning/assistant_note are deterministic to guarantee
            // consistency with the "listed items" (blocks) and the actual time range.
            if (is_array($payload['strategy_points'] ?? null)) {
                $strategyPoints = array_values(array_filter(
                    array_map(static fn (mixed $value): string => trim((string) $value), $payload['strategy_points']),
                    static fn (string $value): bool => $value !== ''
                ));
            }
            if (is_array($payload['suggested_next_steps'] ?? null)) {
                $suggestedNextSteps = array_values(array_filter(
                    array_map(static fn (mixed $value): string => trim((string) $value), $payload['suggested_next_steps']),
                    static fn (string $value): bool => $value !== ''
                ));
            }
            if (is_array($payload['assumptions'] ?? null)) {
                $assumptions = array_values(array_filter(
                    array_map(static fn (mixed $value): string => trim((string) $value), $payload['assumptions']),
                    static fn (string $value): bool => $value !== ''
                ));
            }
        } catch (\Throwable $e) {
            Log::warning('task-assistant.daily-schedule.refinement_failed', [
                'layer' => 'llm_narrative',
                'user_id' => $userId,
                'thread_id' => $threadId,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'summary' => $summary,
            'assistant_note' => $assistantNote,
            'reasoning' => $reasoning,
            'strategy_points' => $strategyPoints,
            'suggested_next_steps' => $suggestedNextSteps,
            'assumptions' => $assumptions,
        ];
    }

    /**
     * @return list<array{start_time?:string,end_time?:string,label?:string}>
     */
    private function decodeBlocksJson(string $blocksJson): array
    {
        $decoded = json_decode($blocksJson, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, static fn (mixed $b): bool => is_array($b)));
    }

    /**
     * Compute exact narrative strings from actual planned blocks.
     *
     * @param  array<int, array{start_time?:string,end_time?:string,label?:string}>  $blocks
     * @param  array<string, mixed>  $promptData
     * @return array{summary: string, assistant_note: ?string, reasoning: string}
     */
    private function buildDeterministicDailyScheduleNarrative(
        array $blocks,
        array $promptData,
        string $deterministicSummary,
    ): array {
        $firstLabel = '';
        foreach ($blocks as $b) {
            $lbl = trim((string) ($b['label'] ?? ''));
            if ($lbl !== '') {
                $firstLabel = $lbl;
                break;
            }
        }

        $timeRange = '';
        $durationMinutes = null;

        $startMin = null;
        $endMinAdjMax = null;
        $earliestStartStr = null;
        $latestEndStr = null;

        $sumMinutes = 0;

        foreach ($blocks as $b) {
            $startTime = trim((string) ($b['start_time'] ?? ''));
            $endTime = trim((string) ($b['end_time'] ?? ''));

            if ($startTime === '' || $endTime === '') {
                continue;
            }

            $startM = $this->timeToMinutes($startTime);
            $endM = $this->timeToMinutes($endTime);
            if ($startM === null || $endM === null) {
                continue;
            }

            $endAdj = $endM >= $startM ? $endM : $endM + 1440;
            $blockMinutes = max(0, $endAdj - $startM);
            $sumMinutes += $blockMinutes;

            if ($startMin === null || $startM < $startMin) {
                $startMin = $startM;
                $earliestStartStr = $startTime;
            }

            if ($endMinAdjMax === null || $endAdj > $endMinAdjMax) {
                $endMinAdjMax = $endAdj;
                $latestEndStr = $endTime;
            }
        }

        $timeRange = '';
        if ($earliestStartStr !== null && $latestEndStr !== null) {
            $startLabel = $this->formatHhmmLabel($earliestStartStr);
            $endLabel = $this->formatHhmmLabel($latestEndStr);
            $timeRange = ($startLabel !== '' && $endLabel !== '')
                ? $startLabel.'–'.$endLabel
                : $earliestStartStr.'–'.$latestEndStr;
        }

        $durationMinutes = $sumMinutes > 0 ? $sumMinutes : null;

        $taskLabel = $firstLabel !== '' ? $firstLabel : 'your selected task';

        $windowPhrase = $this->windowPhraseFromBlocks($earliestStartStr, $latestEndStr);

        $durationPart = $durationMinutes !== null ? ' for '.$durationMinutes.' minutes' : '';

        $summary = $deterministicSummary;
        if ($timeRange !== '') {
            $summary = "For best results, set aside{$durationPart} in the {$windowPhrase} ({$timeRange}) for {$taskLabel}.";
        }

        $reasoning = $timeRange !== ''
            ? "During your {$windowPhrase}, the plan schedules {$taskLabel} in the {$timeRange} block so you can stay focused without bouncing between tasks."
            : 'This schedule sets aside focused time for your selected task so it fits your requested window.';

        $assistantNote = $timeRange !== ''
            ? "When you're ready, start with the first planned block and keep distractions low."
            : null;

        return [
            'summary' => $summary,
            'assistant_note' => $assistantNote,
            'reasoning' => $reasoning,
        ];
    }

    private function timeToMinutes(string $time): ?int
    {
        // Accept "HH:MM" only. The planner generates block times in this format.
        if (! preg_match('/^\s*(\d{1,2}):(\d{2})\s*$/', $time, $m)) {
            return null;
        }

        $h = (int) ($m[1] ?? 0);
        $min = (int) ($m[2] ?? 0);
        if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
            return null;
        }

        return $h * 60 + $min;
    }

    private function formatHhmmLabel(string $hhmm): string
    {
        $hhmm = trim($hhmm);
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) {
            return '';
        }

        $hour24 = (int) ($m[1] ?? 0);
        $minute = (int) ($m[2] ?? 0);

        if ($hour24 < 0 || $hour24 > 23 || $minute < 0 || $minute > 59) {
            return '';
        }

        $ampm = $hour24 >= 12 ? 'PM' : 'AM';
        $hour12 = $hour24 % 12;
        if ($hour12 === 0) {
            $hour12 = 12;
        }

        return $hour12.':'.str_pad((string) $minute, 2, '0', STR_PAD_LEFT).' '.$ampm;
    }

    private function windowPhraseFromBlocks(?string $startTime, ?string $endTime): string
    {
        if ($startTime === null || $endTime === null) {
            return 'requested window';
        }

        $startM = $this->timeToMinutes($startTime);
        $endM = $this->timeToMinutes($endTime);
        if ($startM === null || $endM === null) {
            return 'requested window';
        }

        if ($startM >= 18 * 60) {
            return 'later evening';
        }

        if ($startM >= 15 * 60) {
            return 'later afternoon';
        }

        if ($startM >= 8 * 60 && $endM <= 12 * 60) {
            return 'morning';
        }

        return 'requested window';
    }

    /**
     * Natural-language explanation for a deterministic prioritize result.
     *
     * @param  array<string, mixed>  $promptData
     * @param  list<array<string, mixed>>  $items
     * @return array{
     *   reasoning: string,
     *   suggested_guidance: string,
     *   items: list<array<string, mixed>>
     * }
     */
    public function refinePrioritizeListing(
        array $promptData,
        string $userMessage,
        array $items,
        string $deterministicSummary,
        string $filterContextForPrompt,
        bool $ambiguous,
        int $threadId,
        int $userId,
    ): array {
        $maxRetries = max(0, (int) config('task-assistant.retry.max_retries', 2));
        $refinementSchema = TaskAssistantSchemas::prioritizeNarrativeSchema();
        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $listedTaskCount = count($items);

        $uxInclude = $this->detectPrioritizeUxIncludes($userMessage, $items);
        $includeAcknowledgment = $uxInclude['acknowledgment'];

        $listLabel = $ambiguous
            ? 'The user asked for a general list; use the provided rows as a short prioritized slice.'
            : 'The user asked with filters; use the provided rows as the prioritized results.';

        $firstRowEntity = 'task';
        if ($listedTaskCount >= 1 && isset($items[0]) && is_array($items[0])) {
            $entityGuess = strtolower(trim((string) ($items[0]['entity_type'] ?? 'task')));
            if (in_array($entityGuess, ['task', 'event', 'project'], true)) {
                $firstRowEntity = $entityGuess;
            }
        }

        $firstRowPlural = match ($firstRowEntity) {
            'event' => 'events',
            'project' => 'projects',
            default => 'tasks',
        };

        $listedCountInstruction = $listedTaskCount === 1
            ? 'LISTED_ITEM_COUNT: 1. The student sees exactly ONE prioritized row. Use strictly singular grammar in framing, reasoning, acknowledgment (if any), and next_options: say "this '.$firstRowEntity.'" or "that '.$firstRowEntity.'"—never "these '.$firstRowPlural.'" or "those '.$firstRowPlural.'"; "priority" not "priorities"; use it/this '.$firstRowEntity.' (not they/them) when referring to that row. Do not describe one row as multiple items. '
            : 'LISTED_ITEM_COUNT: '.$listedTaskCount.'. Multiple rows: plural wording is fine when referring to the set. ';

        $messages = collect([
            new UserMessage($userMessage),
            new UserMessage(
                'Use the following rows as the prioritized list for this request (tasks, events, or projects). '.
                'Do NOT change ordering or membership. Write only the user-facing narrative fields (acknowledgment, framing, reasoning, and options). '."\n\n".
                $listLabel."\n\n".
                $listedCountInstruction.
                'You are the task assistant speaking to the student. '.
                'Use a calm, reassuring, empathetic tone. When UX_INCLUDE_ACK is true, the acknowledgment should validate the user\'s feelings briefly and smoothly transition into action, and the rest of the message should stay supportive and steady. '.
                'Do not claim extra visibility into the student\'s full life or full list. Avoid phrases like "I reviewed your tasks", "I looked at your list", "I see you have", or "I\'ve taken a look at your to-do list". '.
                'In acknowledgment, framing, reasoning, and next_options: NEVER mention snapshot, "snapshot data", JSON, ITEMS_JSON, FILTER_CONTEXT, backend, database, or internal technical terms—the student only sees plain English. '.
                'framing is REQUIRED: open in natural assistant voice (I recommend, I suggest, Let\'s, We could, Here\'s what I\'d do—vary openings across turns). Sound human and supportive, not like a fixed template. Explain how this ranking helps the student take the next step, without sounding technical or inventing dates. Do not use impersonal brochure openers like "Here is your top priority in a simple order". '.
                'acknowledgment is OPTIONAL: include only when UX_INCLUDE_ACK is true; otherwise set it to null. When included, it must be exactly one short empathetic sentence. '.
                'reasoning is REQUIRED: speak to the student directly (I/You/Let\'s/We are all fine). Do not use third-person phrasing like "the user ...", "they match ...", or "this list matches ...". For a single-item list you may use "it" clearly tied to that task. Ground every claim in ITEMS_JSON (titles, due_phrase, priority). '.
                'Always mention the exact title of the first row in ITEMS_JSON at least once, and explain why that row is first using its fields—do not add stiff meta lines about "ordered list", "first on this list", or "when you are ready" boilerplate. '.
                'next_options is REQUIRED: 1-2 sentences offering a follow-up option (e.g., scheduling these steps later). Do not suggest rescheduling tasks that were already completed; if you mention rescheduling, it should be about the remaining tasks. '.
                'next_options_chip_texts is REQUIRED: array of 1-2 short chip strings to let the student trigger that follow-up (no question marks). '.
                'UX_INCLUDE_ACK: '.($includeAcknowledgment ? 'true' : 'false').
                "\n".
                'DUE-TIME SAFETY: Do not paraphrase due-time. If you mention "due today", "due tomorrow", "overdue", or "due this week", it MUST match the exact wording present in at least one items[].due_phrase. Never mention due-time phrasing that is not present in items due_phrase values. '.
                'Do not invent items, deadlines, durations, or priorities. '.
                'Do not invent subjects, courses, or domains (for example a specific class or math) unless they appear in item titles or FILTER_CONTEXT. '.
                'Each task row may have a priority field: only describe priority if it matches that row—never mislabel. '."\n\n".
                'FILTER_CONTEXT: '.$filterContextForPrompt."\n\n".
                'ITEMS_JSON: '.$itemsJson
            ),
        ]);

        $acknowledgment = null;
        $framing = null;
        $reasoning = null;
        $nextOptions = null;
        $nextOptionsChipTexts = [];
        $cleanItems = $this->copyPrioritizeItemsWithoutPlacementBlurbs($items);

        try {
            $structuredResponse = $this->attemptStructured(
                $messages,
                $promptData,
                $refinementSchema,
                $maxRetries,
                'prioritize_narrative'
            );

            $payload = $structuredResponse->structured ?? [];
            $payload = is_array($payload) ? $payload : [];

            if (isset($payload['acknowledgment']) && is_string($payload['acknowledgment'])) {
                $acknowledgment = trim($payload['acknowledgment']) !== ''
                    ? trim($payload['acknowledgment'])
                    : null;
            }

            if (isset($payload['framing']) && is_string($payload['framing'])) {
                $framing = trim($payload['framing']) !== ''
                    ? trim($payload['framing'])
                    : null;
            }

            if (isset($payload['reasoning']) && is_string($payload['reasoning'])) {
                $reasoning = trim($payload['reasoning']) !== ''
                    ? trim($payload['reasoning'])
                    : null;
            }

            if (isset($payload['next_options']) && is_string($payload['next_options'])) {
                $nextOptions = trim($payload['next_options']) !== ''
                    ? trim($payload['next_options'])
                    : null;
            }

            if (isset($payload['next_options_chip_texts']) && is_array($payload['next_options_chip_texts'])) {
                $nextOptionsChipTexts = array_values(array_filter(
                    array_map(static fn (mixed $v): string => trim((string) $v), $payload['next_options_chip_texts']),
                    static fn (string $v): bool => $v !== '' && mb_strlen($v) >= 2 && ! str_contains($v, '?')
                ));
            }
        } catch (\Throwable $e) {
            Log::warning('task-assistant.prioritize.narrative_failed', [
                'layer' => 'llm_narrative',
                'user_id' => $userId,
                'thread_id' => $threadId,
                'error' => $e->getMessage(),
            ]);
        }

        if (! is_string($framing) || trim($framing) === '') {
            $framing = trim($deterministicSummary) !== ''
                ? trim($deterministicSummary)
                : $this->firstPersonFramingFallback(max(1, $listedTaskCount), $userMessage.'|'.$threadId);
        }
        $framing = $this->sanitizeFraming((string) $framing, $listedTaskCount, $userMessage.'|'.$threadId);

        if ($nextOptions === null || trim($nextOptions) === '') {
            $nextOptions = __('If you want, I can schedule these steps for later.');
        }

        if (mb_strlen((string) $nextOptions) < 5) {
            $nextOptions = __('If you want, I can schedule these steps for later.');
        }
        $nextOptions = $this->sanitizeNextOptions((string) $nextOptions, is_array($cleanItems) ? count($cleanItems) : 0);

        if ($nextOptionsChipTexts === []) {
            $nextOptionsChipTexts = [
                'Schedule these for later',
                'Schedule these tasks for a specific time',
            ];
        }

        // Post-process enforcement: optional UX narrative fields should only be present
        // when the deterministic UX include flags allow it.
        if (! $includeAcknowledgment) {
            $acknowledgment = null;
        }

        // Remove redundancy between acknowledgment and framing for stress prompts.
        $framing = $this->dedupeAcknowledgmentAndFraming($acknowledgment, (string) $framing, $cleanItems);

        // Safety net against LLM due-date drift (e.g. "tomorrow" vs items[].due_phrase="due today").
        $allowedDuePhrases = $this->extractTaskDuePhrases($cleanItems);
        $framing = $this->rewriteFramingWhenDueSoonConflictsWithOverdue((string) $framing, $cleanItems);
        $framingConflict = $this->hasConflictingDueTiming((string) $framing, $allowedDuePhrases);
        if ($framingConflict) {
            $framing = $this->firstPersonFramingFallback(max(1, $listedTaskCount), $userMessage.'|'.$threadId.'|due_time');
        }

        // Safety net for due-time drift in next options and chip texts.
        $nextOptionsConflict = $this->hasConflictingDueTiming((string) $nextOptions, $allowedDuePhrases);
        if ($nextOptionsConflict) {
            $nextOptions = __('If you want, I can schedule these steps for later.');
            $nextOptionsChipTexts = ['Schedule these for later', 'Schedule these tasks for a specific time'];
        }

        $chipConflict = false;
        foreach ($nextOptionsChipTexts as $chipText) {
            if ($this->hasConflictingDueTiming((string) $chipText, $allowedDuePhrases)) {
                $chipConflict = true;
                break;
            }
        }

        if ($chipConflict) {
            $nextOptionsChipTexts = ['Schedule these for later', 'Schedule these tasks for a specific time'];
        }

        $nextOptionsChipTexts = array_values(array_slice($nextOptionsChipTexts, 0, 3));

        // Enforce required reasoning field (schema expects non-null).
        if ($reasoning === null || trim($reasoning) === '') {
            $reasoning = TaskAssistantListingDefaults::reasoningWhenEmpty();
        }

        // Enforce student-directed POV (avoid third-person phrasing).
        $reasoning = TaskAssistantListingDefaults::normalizePrioritizeReasoningVoice((string) $reasoning, $cleanItems);

        // Ensure reasoning stays anchored to the ranked list (especially item #1).
        // Models sometimes explain a secondary item, which feels inconsistent even when schema-valid.
        $reasoning = $this->enforceReasoningAnchorsTopItem((string) $reasoning, $cleanItems);
        $reasoning = $this->normalizeReasoningOverdueGrammar((string) $reasoning, $cleanItems);

        // If UX includes acknowledgment, ensure it's actually empathetic and not just generic framing.
        if ($includeAcknowledgment) {
            $ackNorm = $acknowledgment !== null ? $this->normalizeForSimilarity((string) $acknowledgment) : '';
            $framingNorm = $this->normalizeForSimilarity((string) $framing);

            $ackLooksGeneric = $ackNorm !== ''
                && (str_contains($ackNorm, 'focused starting point') || str_contains($ackNorm, 'get momentum'));
            $ackDuplicatesFraming = $ackNorm !== '' && $framingNorm !== '' && $ackNorm === $framingNorm;

            if ($acknowledgment === null || trim((string) $acknowledgment) === '' || $ackDuplicatesFraming || $ackLooksGeneric) {
                $acknowledgment = $this->buildDefaultEmpatheticAcknowledgment($userMessage);
            }

            if (! $this->isEmpatheticAcknowledgment((string) $acknowledgment)) {
                $acknowledgment = $this->buildDefaultEmpatheticAcknowledgment($userMessage);
            }
        }

        // Force acknowledgment non-null when the user intent heuristic triggers it.
        if ($includeAcknowledgment && ($acknowledgment === null || trim($acknowledgment) === '')) {
            $framingText = trim((string) $framing);
            if (preg_match('/^(.+?[.!?])\s*(.*)$/us', $framingText, $matches) === 1) {
                $ackFromFraming = trim((string) ($matches[1] ?? ''));
                $remaining = trim((string) ($matches[2] ?? ''));

                if ($ackFromFraming !== '') {
                    $acknowledgment = $ackFromFraming;
                    if ($remaining !== '') {
                        $framing = $remaining;
                    }
                }
            }

            if ($acknowledgment === null || trim($acknowledgment) === '') {
                $acknowledgment = __('I get it; this is a lot to handle—let\'s start with your top priority.');
            }
        }

        $focus = [
            'main_task' => 'No matching items found',
            'secondary_tasks' => [],
        ];
        if ($cleanItems !== [] && is_array($cleanItems[0] ?? null)) {
            $main = trim((string) (($cleanItems[0] ?? [])['title'] ?? ''));
            if ($main !== '') {
                $focus['main_task'] = $main;
            }

            for ($i = 1; $i < count($cleanItems); $i++) {
                $row = $cleanItems[$i] ?? null;
                if (! is_array($row)) {
                    continue;
                }
                $t = trim((string) ($row['title'] ?? ''));
                if ($t !== '') {
                    $focus['secondary_tasks'][] = $t;
                }
            }
        }

        $singularCoerceCount = count($cleanItems);

        return [
            'items' => $cleanItems,
            'focus' => $focus,
            'acknowledgment' => $acknowledgment !== null
                ? TaskAssistantListingDefaults::clampFraming(
                    TaskAssistantListingDefaults::coerceSingularPrioritizeNarrative((string) $acknowledgment, $singularCoerceCount, $cleanItems)
                )
                : null,
            'framing' => TaskAssistantListingDefaults::clampFraming(
                TaskAssistantListingDefaults::coerceSingularPrioritizeNarrative((string) $framing, $singularCoerceCount, $cleanItems)
            ),
            'reasoning' => TaskAssistantListingDefaults::clampBrowseReasoning(
                TaskAssistantListingDefaults::coerceSingularPrioritizeNarrative((string) $reasoning, $singularCoerceCount, $cleanItems)
            ),
            'next_options' => TaskAssistantListingDefaults::clampNextField(
                TaskAssistantListingDefaults::coerceSingularPrioritizeNarrative((string) $nextOptions, $singularCoerceCount, $cleanItems)
            ),
            'next_options_chip_texts' => array_values(array_map(
                static fn (mixed $t): string => TaskAssistantListingDefaults::clampNextOptionChipText(
                    TaskAssistantListingDefaults::coerceSingularPrioritizeNarrative((string) $t, $singularCoerceCount, $cleanItems)
                ),
                $nextOptionsChipTexts
            )),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function enforceReasoningAnchorsTopItem(string $reasoning, array $items): string
    {
        $text = trim(TaskAssistantListingDefaults::stripRoboticPrioritizeReasoningTail($reasoning));
        if ($items === []) {
            return $reasoning;
        }
        if ($text === '') {
            return TaskAssistantListingDefaults::reasoningWhenEmpty();
        }

        $first = is_array($items[0] ?? null) ? $items[0] : null;
        if (! is_array($first)) {
            return $reasoning;
        }

        $title = trim((string) ($first['title'] ?? ''));
        if ($title === '') {
            return $reasoning;
        }

        $lower = mb_strtolower($text);
        $titleLower = mb_strtolower($title);

        // If it already references the top item title, keep it.
        if ($titleLower !== '' && mb_stripos($lower, $titleLower) !== false) {
            return $text;
        }

        // Substantial model copy: keep voice even if it omits the top title (the numbered list shows order).
        // Short blurbs without the title still get a soft anchor so single-line fluff is not left vague.
        $minCharsToTrustWithoutTopTitle = 50;
        if (mb_strlen($text) >= $minCharsToTrustWithoutTopTitle) {
            return $text;
        }

        return $this->softReasoningAnchorWhenTopTitleMissing($title, $text);
    }

    /**
     * When reasoning is short and never names the top row, substitute varied copy (no "ordered list" tail).
     */
    private function softReasoningAnchorWhenTopTitleMissing(string $title, string $originalSnippet): string
    {
        $seed = $title.'|'.hash('sha256', $originalSnippet);

        return match (abs(crc32($seed)) % 4) {
            0 => "I put {$title} first because it's the most time-sensitive or urgent in this slice—then you can work down from there.",
            1 => "I'd start with {$title}; it lines up best with the deadlines and priorities shown for the top row.",
            2 => "Leading with {$title} matches what's ranked first here—tackle it before the other rows when you can.",
            default => "I chose {$title} at the top because of its timing and priority relative to the rest of this short list.",
        };
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function extractTaskDuePhrases(array $items): array
    {
        $out = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (strtolower(trim((string) ($item['entity_type'] ?? 'task'))) !== 'task') {
                continue;
            }

            $duePhrase = trim((string) ($item['due_phrase'] ?? ''));
            if ($duePhrase === '') {
                continue;
            }

            $out[] = $duePhrase;
        }

        return array_values(array_unique($out));
    }

    /**
     * Detect relative timing words that contradict the allowed due_phrase set.
     *
     * @param  list<string>  $allowedDuePhrases
     */
    private function hasConflictingDueTiming(string $text, array $allowedDuePhrases): bool
    {
        $lower = mb_strtolower($text);
        $allowedLower = array_map(static fn (string $p): string => mb_strtolower($p), $allowedDuePhrases);

        if (mb_stripos($lower, 'due soon') !== false && ! in_array('due soon', $allowedLower, true)) {
            return true;
        }

        // Only trigger on due-phrase tokens (not standalone "tomorrow"/"today"),
        // so task titles like "tomorrow's ..." do not cause false positives.
        $duePhraseTokens = [
            'due tomorrow',
            'due today',
            'due yesterday',
            'due this week',
            'overdue',
        ];

        foreach ($duePhraseTokens as $token) {
            if (mb_stripos($lower, $token) === false) {
                continue;
            }

            if (! in_array($token, $allowedLower, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count task rows whose due_phrase indicates overdue (used for singular/plural copy).
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function countTaskRowsWithOverdueDuePhrase(array $items): int
    {
        $n = 0;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (strtolower(trim((string) ($item['entity_type'] ?? 'task'))) !== 'task') {
                continue;
            }
            $phrase = mb_strtolower(trim((string) ($item['due_phrase'] ?? '')));
            if ($phrase === '' || ! str_contains($phrase, 'overdue')) {
                continue;
            }
            $n++;
        }

        return $n;
    }

    /**
     * Replace vague "due soon" framing when the slice includes overdue tasks and no calendar
     * "due today"/"due tomorrow" rows—wording matches how many overdue rows exist.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function rewriteFramingWhenDueSoonConflictsWithOverdue(string $framing, array $items): string
    {
        if (mb_stripos($framing, 'due soon') === false) {
            return $framing;
        }

        $allowedLower = array_map(
            static fn (string $p): string => mb_strtolower($p),
            $this->extractTaskDuePhrases($items)
        );

        if (! in_array('overdue', $allowedLower, true)) {
            return $framing;
        }

        if (in_array('due today', $allowedLower, true) || in_array('due tomorrow', $allowedLower, true)) {
            return $framing;
        }

        $overdueCount = $this->countTaskRowsWithOverdueDuePhrase($items);
        if ($overdueCount <= 0) {
            return $framing;
        }

        return $this->framingLineForOverdueFirstFocus($overdueCount);
    }

    private function framingLineForOverdueFirstFocus(int $overdueTaskCount): string
    {
        if ($overdueTaskCount <= 1) {
            return 'Start with the overdue item first so you can feel caught up and less stressed.';
        }

        return 'Start with the overdue items first so you can feel caught up and less stressed.';
    }

    /**
     * Fix common model mistakes such as "These 'One title' tasks are overdue" when only one task is overdue.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function normalizeReasoningOverdueGrammar(string $reasoning, array $items): string
    {
        $text = $reasoning;
        $overdueCount = $this->countTaskRowsWithOverdueDuePhrase($items);

        if ($overdueCount === 1) {
            $replaced = preg_replace_callback(
                '/\b(these|those)\s+\'([^\']+)\'\s+tasks\s+are\b/iu',
                static function (array $m): string {
                    $lead = strcasecmp((string) ($m[1] ?? ''), 'those') === 0 ? 'That' : 'This';

                    return $lead.' \''.($m[2] ?? '').'\' task is';
                },
                $text
            );
            $text = is_string($replaced) ? $replaced : $text;

            $text = preg_replace('/\bthese\s+tasks\s+are\s+overdue\b/iu', 'This task is overdue', $text, 1) ?? $text;
            $text = preg_replace('/\bthose\s+tasks\s+are\s+overdue\b/iu', 'That task is overdue', $text, 1) ?? $text;
        }

        return $text;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function regenerateSuggestedNextActionsFromItems(array $items, int $maxCount = 2): array
    {
        $out = [];

        foreach (array_values($items) as $i => $item) {
            if ($i >= $maxCount) {
                break;
            }
            if (! is_array($item)) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            if ($out === []) {
                $out[] = 'Start with '.$title.'.';
            } else {
                $out[] = 'Next, '.$title.'.';
            }
        }

        if ($out === []) {
            return ['Tell me what you want to focus on so I can refine the list.'];
        }

        return array_slice($out, 0, $maxCount);
    }

    /**
     * Heuristically decide which optional UX fields should be included.
     *
     * This keeps “simple prompts” from producing noisy optional fields while
     * still allowing richer output when the situation is likely non-obvious.
     *
     * @param  array<string, mixed>  $userMessage
     * @param  list<array<string, mixed>>  $items
     * @return array{acknowledgment: bool}
     */
    private function detectPrioritizeUxIncludes(string $userMessage, array $items): array
    {
        $content = mb_strtolower($userMessage);

        $includeAcknowledgment = (bool) preg_match(
            '/\b(overwhelmed|anxious|worried|stressed|panicked|frustrated|stuck|nervous|excited|i\s+feel|i\'m)\b/u',
            $content
        );

        return [
            'acknowledgment' => $includeAcknowledgment,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function copyPrioritizeItemsWithoutPlacementBlurbs(array $items): array
    {
        $out = [];
        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            $copy = $row;
            unset($copy['placement_blurb']);
            $out[] = $copy;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function mergePlacementNotesOntoItems(
        array $items,
        mixed $rawNotes,
        int $threadId,
        int $userId,
    ): array {
        if (! is_array($rawNotes)) {
            Log::warning('task-assistant.prioritize.notes_mismatch', [
                'layer' => 'llm_narrative',
                'reason' => 'item_placement_notes_not_array',
                'user_id' => $userId,
                'thread_id' => $threadId,
                'items_count' => count($items),
            ]);

            return $this->copyPrioritizeItemsWithoutPlacementBlurbs($items);
        }

        $notes = [];
        foreach ($rawNotes as $note) {
            $notes[] = is_string($note) ? trim($note) : '';
        }

        if (count($notes) !== count($items)) {
            Log::warning('task-assistant.prioritize.notes_mismatch', [
                'layer' => 'llm_narrative',
                'reason' => 'length_mismatch',
                'user_id' => $userId,
                'thread_id' => $threadId,
                'items_count' => count($items),
                'notes_count' => count($notes),
            ]);

            return $this->copyPrioritizeItemsWithoutPlacementBlurbs($items);
        }

        $out = [];
        for ($i = 0; $i < count($items); $i++) {
            $row = $items[$i] ?? null;
            if (! is_array($row)) {
                continue;
            }
            $copy = [...$row];
            unset($copy['placement_blurb']);
            $line = $notes[$i] ?? '';
            if ($line !== '') {
                $copy['placement_blurb'] = TaskAssistantListingDefaults::clampItemPlacementBlurb($line);
            }
            $out[] = $copy;
        }

        return $out;
    }

    /**
     * @return array{suggested_guidance: string}
     */
    public static function prioritizeListingNarrativeFallbacks(): array
    {
        return [
            'suggested_guidance' => TaskAssistantListingDefaults::defaultSuggestedGuidance(),
        ];
    }

    /**
     * @param  Collection<int, mixed>  $messages
     */
    private function attemptStructured(
        Collection $messages,
        array $promptData,
        mixed $refinementSchema,
        int $maxRetries,
        string $generationRoute,
    ): mixed {
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                return Prism::structured()
                    ->using($this->resolveProvider(), $this->resolveModel())
                    ->withSystemPrompt(view('prompts.task-assistant-system', $promptData))
                    ->withMessages($messages->all())
                    ->withTools([])
                    ->withSchema($refinementSchema)
                    ->withClientOptions($this->resolveClientOptionsForRoute($generationRoute))
                    ->asStructured();
            } catch (\Throwable $exception) {
                if ($attempt === $maxRetries) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException('Unreachable hybrid narrative retry state.');
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
            'layer' => 'llm_narrative',
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
     * @return array<string, int|float>
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

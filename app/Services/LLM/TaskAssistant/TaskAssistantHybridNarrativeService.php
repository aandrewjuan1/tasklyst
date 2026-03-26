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
        $includeInsight = $uxInclude['insight'];

        $listLabel = $ambiguous
            ? 'The user asked for a general list; use the provided rows as a short prioritized slice.'
            : 'The user asked with filters; use the provided rows as the prioritized results.';

        $messages = collect([
            new UserMessage($userMessage),
            new UserMessage(
                'Use the following rows as the prioritized list for this request (tasks, events, or projects). '.
                'Do NOT change ordering or membership. Write only the user-facing narrative fields (acknowledgment, framing, insight, reasoning, next actions, and options). '."\n\n".
                $listLabel."\n\n".
                'You are the task assistant speaking to the student. '.
                'Use a calm, reassuring, empathetic tone. When UX_INCLUDE_ACK is true, the acknowledgment should validate the user\'s feelings briefly and smoothly transition into action, and the rest of the message should stay supportive and steady. '.
                'In acknowledgment, framing, insight, reasoning, suggested_next_actions, next_actions_intro, and next_options: NEVER mention snapshot, "snapshot data", JSON, ITEMS_JSON, FILTER_CONTEXT, backend, database, or internal technical terms—the student only sees plain English. '.
                'framing is REQUIRED: write 1-2 sentences explaining how this list/focus helps, without sounding technical or inventing dates. '.
                'acknowledgment is OPTIONAL: include only when UX_INCLUDE_ACK is true; otherwise set it to null. When included, it must be exactly one short empathetic sentence. '.
                'insight is OPTIONAL: include only when UX_INCLUDE_INSIGHT is true; otherwise set it to null. '.
                'reasoning is REQUIRED: short (1-2 sentences) explanation of why this ordering matches the request. '.
                'suggested_next_actions is REQUIRED: array of 1-2 action strings. Each must be distinct, start with a verb, has no question marks, and has no bullet characters inside the string. Tie actions to the provided row titles/order when helpful. '.
                'next_actions_intro is REQUIRED: a lead-in sentence that starts with "I recommend …" and then points to the numbered steps. '.
                'next_options is REQUIRED: 1-2 sentences offering a follow-up option (e.g., scheduling these steps later). '.
                'next_options_chip_texts is REQUIRED: array of 1-2 short chip strings to let the student trigger that follow-up (no question marks). '.
                'UX_INCLUDE_ACK: '.($includeAcknowledgment ? 'true' : 'false').
                '; UX_INCLUDE_INSIGHT: '.($includeInsight ? 'true' : 'false').
                "\n".
                'DUE-TIME SAFETY: Do not paraphrase due-time. If you mention "due today", "due tomorrow", "overdue", or "due this week", it MUST match the exact wording present in at least one items[].due_phrase. Never mention due-time phrasing that is not present in items due_phrase values. '.
                'Do not invent items, deadlines, durations, or priorities. '.
                'Each task row may have a priority field: only describe priority if it matches that row—never mislabel. '."\n\n".
                'FILTER_CONTEXT: '.$filterContextForPrompt."\n\n".
                'ITEMS_JSON: '.$itemsJson
            ),
        ]);

        $acknowledgment = null;
        $framing = null;
        $insight = null;
        $reasoning = null;
        $suggestedNextActions = [];
        $nextActionsIntro = null;
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

            if (isset($payload['insight']) && is_string($payload['insight'])) {
                $insight = trim($payload['insight']) !== ''
                    ? trim($payload['insight'])
                    : null;
            }

            if (isset($payload['reasoning']) && is_string($payload['reasoning'])) {
                $reasoning = trim($payload['reasoning']) !== ''
                    ? trim($payload['reasoning'])
                    : null;
            }

            if (isset($payload['suggested_next_actions']) && is_array($payload['suggested_next_actions'])) {
                $suggestedNextActions = array_values(array_filter(
                    array_map(static fn (mixed $v): string => trim((string) $v), $payload['suggested_next_actions']),
                    static fn (string $v): bool => $v !== '' && mb_strlen($v) >= 3 && ! str_contains($v, '?')
                ));
            }

            if (isset($payload['next_actions_intro']) && is_string($payload['next_actions_intro'])) {
                $nextActionsIntro = trim($payload['next_actions_intro']) !== ''
                    ? trim($payload['next_actions_intro'])
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
                : 'Here is a focused list you can act on right away.';
        }

        if ($suggestedNextActions === []) {
            $topTitle = '';
            if ($cleanItems !== [] && is_array($cleanItems[0] ?? null)) {
                $topTitle = trim((string) (($cleanItems[0] ?? [])['title'] ?? ''));
            }

            $suggestedNextActions = $topTitle !== ''
                ? [
                    'Start with '.$topTitle.' and complete one small step.',
                    'Then move to the next item and work for a short focused session.',
                ]
                : ['Tell me what you want to focus on so I can refine the list.'];
        }

        if ($nextActionsIntro === null || trim($nextActionsIntro) === '') {
            $nextActionsIntro = __('I recommend you take these next steps.');
        }

        // If the model outputs a lead-in without the required prefix, fix it deterministically.
        if (mb_stripos($nextActionsIntro, 'I recommend') !== 0) {
            $nextActionsIntro = __('I recommend you take these next steps.');
        }

        if (mb_strlen((string) $nextActionsIntro) < 10) {
            $nextActionsIntro = __('I recommend you take these next steps.');
        }

        if ($nextOptions === null || trim($nextOptions) === '') {
            $nextOptions = __('If you want, I can schedule these steps for later.');
        }

        if (mb_strlen((string) $nextOptions) < 5) {
            $nextOptions = __('If you want, I can schedule these steps for later.');
        }

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
        if (! $includeInsight) {
            $insight = null;
        }

        // Safety net against LLM due-date drift (e.g. "tomorrow" vs items[].due_phrase="due today").
        $allowedDuePhrases = $this->extractTaskDuePhrases($cleanItems);
        $framingConflict = $this->hasConflictingDueTiming((string) $framing, $allowedDuePhrases);
        if ($framingConflict) {
            $framing = 'Here is a focused list you can act on right away.';
        }

        $hasActionConflict = false;
        foreach ($suggestedNextActions as $action) {
            if ($this->hasConflictingDueTiming((string) $action, $allowedDuePhrases)) {
                $hasActionConflict = true;
                break;
            }
        }

        if ($hasActionConflict) {
            $suggestedNextActions = $this->regenerateSuggestedNextActionsFromItems($cleanItems, maxCount: 2);
        }

        // Enforce contract quality: at most 2 actions overall.
        $suggestedNextActions = array_values(array_slice($suggestedNextActions, 0, 2));

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

        $nextOptionsChipTexts = array_values(array_slice($nextOptionsChipTexts, 0, 2));

        // Enforce required reasoning field (schema expects non-null).
        if ($reasoning === null || trim($reasoning) === '') {
            $reasoning = TaskAssistantListingDefaults::reasoningWhenEmpty();
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

        return [
            'items' => $cleanItems,
            'focus' => $focus,
            'acknowledgment' => $acknowledgment !== null
                ? TaskAssistantListingDefaults::clampFraming((string) $acknowledgment)
                : null,
            'framing' => TaskAssistantListingDefaults::clampFraming((string) $framing),
            'insight' => $insight !== null ? TaskAssistantListingDefaults::clampBrowseReasoning($insight) : null,
            'reasoning' => TaskAssistantListingDefaults::clampBrowseReasoning((string) $reasoning),
            'suggested_next_actions' => array_values(array_map(
                static fn (mixed $a): string => TaskAssistantListingDefaults::clampSuggestedNextAction((string) $a),
                $suggestedNextActions
            )),
            'next_actions_intro' => TaskAssistantListingDefaults::clampNextField((string) $nextActionsIntro),
            'next_options' => TaskAssistantListingDefaults::clampNextField((string) $nextOptions),
            'next_options_chip_texts' => array_values(array_map(
                static fn (mixed $t): string => TaskAssistantListingDefaults::clampNextOptionChipText((string) $t),
                $nextOptionsChipTexts
            )),
        ];
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

        // Only trigger on due-phrase tokens (not standalone "tomorrow"/"today"),
        // so task titles like "tomorrow's ..." do not cause false positives.
        $duePhraseTokens = [
            'due tomorrow',
            'due today',
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
     * @return array{acknowledgment: bool, insight: bool}
     */
    private function detectPrioritizeUxIncludes(string $userMessage, array $items): array
    {
        $content = mb_strtolower($userMessage);

        $includeAcknowledgment = (bool) preg_match(
            '/\b(overwhelmed|anxious|worried|stressed|panicked|frustrated|stuck|nervous|excited|i\s+feel|i\'m)\b/u',
            $content
        );

        $topItems = array_values(array_slice($items, 0, 3));

        $nonTaskInTop = false;
        $topTasks = [];
        foreach ($topItems as $item) {
            if (! is_array($item)) {
                continue;
            }
            $entityType = strtolower(trim((string) ($item['entity_type'] ?? 'task')));
            if ($entityType !== 'task') {
                $nonTaskInTop = true;

                continue;
            }
            $topTasks[] = $item;
        }

        $top1 = $topTasks[0] ?? [];
        $top2 = $topTasks[1] ?? [];

        $due1 = (string) ($top1['due_bucket'] ?? '');
        $due2 = (string) ($top2['due_bucket'] ?? '');
        if ($due1 === '' && is_string($top1['due_phrase'] ?? null)) {
            $due1 = $this->mapDuePhraseToBucket((string) ($top1['due_phrase'] ?? ''));
        }
        if ($due2 === '' && is_string($top2['due_phrase'] ?? null)) {
            $due2 = $this->mapDuePhraseToBucket((string) ($top2['due_phrase'] ?? ''));
        }

        $prio1 = (string) ($top1['priority'] ?? '');
        $prio2 = (string) ($top2['priority'] ?? '');

        $dueDiff = $due1 !== '' && $due2 !== '' && $due1 !== $due2;
        $prioDiff = $prio1 !== '' && $prio2 !== '' && $prio1 !== $prio2;

        $includeInsight = $nonTaskInTop || $dueDiff || $prioDiff;

        return [
            'acknowledgment' => $includeAcknowledgment,
            'insight' => $includeInsight,
        ];
    }

    private function mapDuePhraseToBucket(string $duePhrase): string
    {
        $p = mb_strtolower(trim($duePhrase));

        if ($p === '') {
            return '';
        }

        if (str_contains($p, 'overdue')) {
            return 'overdue';
        }

        if (str_contains($p, 'due today')) {
            return 'due_today';
        }

        if (str_contains($p, 'tomorrow')) {
            return 'due_tomorrow';
        }

        if (str_contains($p, 'this week')) {
            return 'due_this_week';
        }

        return '';
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

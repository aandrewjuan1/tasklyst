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

        $listLabel = $ambiguous
            ? 'The user asked for a general list; the backend returned a short ranked slice (see FILTER_CONTEXT).'
            : 'The user asked with filters; the backend applied FILTER_CONTEXT and ranking.';

        $messages = collect([
            new UserMessage($userMessage),
            new UserMessage(
                'The following rows were selected by backend filtering and ranking (tasks, events, or projects). '.
                'Do NOT change ordering or membership. Only fill narrative JSON fields in the schema.'."\n\n".
                $listLabel."\n\n".
                'You are the task assistant speaking to the student. '.
                'In acknowledgment, framing, insight, reasoning, tradeoffs, and suggested_next_actions: NEVER mention snapshot, "snapshot data", JSON, ITEMS_JSON, FILTER_CONTEXT, backend, database, or internal technical terms—the student only sees plain English. '.
                'framing is REQUIRED: write 1-2 sentences explaining how this list/focus helps, without sounding technical or inventing dates. '.
                'acknowledgment is OPTIONAL: include only when the user input sounds conversational/emotional; use 1 short sentence. '.
                'insight is OPTIONAL: 0-1 short sentence about something non-obvious grounded in visible row info. '.
                'reasoning is OPTIONAL: 0-3 sentences about why this selected set matches the request (do not invent). If you mention counts, LISTED_TASK_COUNT MUST match exactly. '.
                'tradeoffs is OPTIONAL: 0-3 short strings only when choices are non-obvious. '.
                'suggested_next_actions is REQUIRED: array of 1-4 action strings. Each starts with a verb, has no question marks, and has no bullet characters inside the string. Tie actions to the provided row titles/order when helpful. '.
                'DUE-TIME SAFETY: Do not paraphrase relative timing. If you mention "today", "tomorrow", "overdue", or "this week", it MUST match the exact relative wording present in at least one items[].due_phrase. Never mention relative timing that is not present in items due_phrase values. '.
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
        $tradeoffs = null;
        $suggestedNextActions = [];
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

            if (isset($payload['tradeoffs']) && is_array($payload['tradeoffs'])) {
                $tradeoffs = array_values(array_filter(
                    array_map(static fn (mixed $v): string => trim((string) $v), $payload['tradeoffs']),
                    static fn (string $v): bool => $v !== ''
                ));

                $tradeoffs = $tradeoffs !== [] ? $tradeoffs : null;
            }

            if (isset($payload['suggested_next_actions']) && is_array($payload['suggested_next_actions'])) {
                $suggestedNextActions = array_values(array_filter(
                    array_map(static fn (mixed $v): string => trim((string) $v), $payload['suggested_next_actions']),
                    static fn (string $v): bool => $v !== '' && ! str_contains($v, '?')
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
            $suggestedNextActions = $this->regenerateSuggestedNextActionsFromItems($cleanItems, maxCount: 4);
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
            'acknowledgment' => $acknowledgment,
            'framing' => TaskAssistantListingDefaults::clampBrowseReasoning((string) $framing),
            'insight' => $insight !== null ? TaskAssistantListingDefaults::clampBrowseReasoning($insight) : null,
            'reasoning' => $reasoning !== null ? TaskAssistantListingDefaults::clampBrowseReasoning($reasoning) : null,
            'tradeoffs' => $tradeoffs,
            'suggested_next_actions' => $suggestedNextActions,
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

        $mentionsTomorrow = mb_stripos($lower, 'tomorrow') !== false;
        $mentionsToday = mb_stripos($lower, 'today') !== false;
        $mentionsOverdue = mb_stripos($lower, 'overdue') !== false;
        $mentionsThisWeek = mb_stripos($lower, 'this week') !== false;

        if ($mentionsTomorrow) {
            $matches = false;
            foreach ($allowedLower as $p) {
                if (mb_stripos($p, 'tomorrow') !== false) {
                    $matches = true;
                    break;
                }
            }

            if (! $matches) {
                return true;
            }
        }

        if ($mentionsToday) {
            $matches = false;
            foreach ($allowedLower as $p) {
                if (mb_stripos($p, 'today') !== false) {
                    $matches = true;
                    break;
                }
            }

            if (! $matches) {
                return true;
            }
        }

        if ($mentionsOverdue) {
            $matches = in_array('overdue', $allowedLower, true);
            if (! $matches) {
                return true;
            }
        }

        if ($mentionsThisWeek) {
            $matches = false;
            foreach ($allowedLower as $p) {
                if (mb_stripos($p, 'this week') !== false) {
                    $matches = true;
                    break;
                }
            }

            if (! $matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function regenerateSuggestedNextActionsFromItems(array $items, int $maxCount = 4): array
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

            $isFirst = $out === [];
            $prefix = $isFirst ? 'Start with completing' : 'Then continue with';
            $out[] = $prefix.' '.$title.'.';
        }

        if ($out === []) {
            return ['Tell me what you want to focus on so I can refine the list.'];
        }

        return array_slice($out, 0, 4);
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

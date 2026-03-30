<?php

namespace App\Services\LLM\TaskAssistant;

use App\Support\LLM\PrioritizeNarrativeConnectionFallback;
use App\Support\LLM\TaskAssistantPrioritizeOutputDefaults;
use App\Support\LLM\TaskAssistantSchemas;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Structured\PendingRequest as StructuredPendingRequest;
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

        if ($listedTaskCount === 0) {
            return match ($n) {
                0 => TaskAssistantPrioritizeOutputDefaults::framingWhenRankSliceHasNoTodoButDoing(),
                1 => (string) __('Nothing new landed in this slice yet—wrapping up what you already started is the clearest next move.'),
                2 => (string) __('Filters might be tight, or what’s left is already in motion—lean on finishing what you’ve opened first.'),
                default => (string) __('No ranked tasks here for now—your in-progress work is still the main story.'),
            };
        }

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

        $framing = TaskAssistantPrioritizeOutputDefaults::sanitizePrioritizeFramingMetaVoice($framing, $listedTaskCount);
        if ($framing === '' || $this->containsVisibilityOverclaim($framing)) {
            return $this->firstPersonFramingFallback($listedTaskCount, $seed.'|meta_voice');
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
    private function dedupeAcknowledgmentAndFraming(?string $acknowledgment, string $framing, array $items, bool $doingCoachRequired = false): string
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
            if ($priority === 'high' || $priority === 'urgent') {
                $hasHighPriority = true;
            }
        }

        if ($doingCoachRequired) {
            return TaskAssistantPrioritizeOutputDefaults::clampFraming(
                TaskAssistantPrioritizeOutputDefaults::buildPrioritizeFramingDoingFirstIntroFallback(
                    'overwhelmed_ack_dedupe|'.($hasOverdue || $hasHighPriority ? 'hot' : 'calm')
                )
            );
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

    private function sanitizeNextOptions(string $nextOptions, int $itemsCount, bool $emptyRankedSlice = false): string
    {
        $text = trim($nextOptions);
        if ($text === '') {
            if ($emptyRankedSlice) {
                return (string) __('If you want, we can widen filters or find time to focus on what you already started.');
            }

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
     * @return array{framing: string, reasoning: string, confirmation: string}
     */
    public function refineDailySchedule(
        Collection $historyMessages,
        array $promptData,
        string $userMessageContent,
        string $blocksJson,
        string $deterministicSummary,
        int $threadId,
        int $userId,
        bool $isEmptyPlacement,
        int $schedulableProposalCount,
        string $generationRoute = 'schedule_narrative',
        ?string $placementDigestJson = null,
    ): array {
        $maxRetries = max(0, (int) config('task-assistant.retry.max_retries', 2));
        $refinementSchema = TaskAssistantSchemas::scheduleNarrativeRefinementSchema();

        $parsedBlocks = $this->decodeBlocksJson($blocksJson);
        $blockCount = count($parsedBlocks);

        $deterministicNarrative = $this->buildDeterministicDailyScheduleNarrative(
            blocks: $parsedBlocks,
            promptData: $promptData,
            deterministicSummary: $deterministicSummary
        );

        $deterministicReasoning = $deterministicNarrative['reasoning'];

        if ($isEmptyPlacement) {
            return $this->buildEmptyPlacementScheduleNarrative(
                deterministicReasoning: $deterministicReasoning,
            );
        }

        $horizonHint = '';
        $h = $promptData['schedule_horizon'] ?? null;
        if (is_array($h) && isset($h['start_date'], $h['end_date'], $h['label'])) {
            $horizonHint = ' The placement window is '.$h['label'].' ('.$h['start_date'].' to '.$h['end_date'].'). ';
        }

        $digestBlock = '';
        $trimDigest = $placementDigestJson !== null ? trim($placementDigestJson) : '';
        if ($trimDigest !== '' && $trimDigest !== '{}') {
            $digestBlock = "\n\nPLACEMENT_DIGEST_JSON (server truth for multi-day spill, proposal limit, or unplaced segments—reference plainly if relevant; do not invent times beyond BLOCKS_JSON):\n".$trimDigest;
        }

        $messages = $historyMessages->values();
        $messages->push(new UserMessage($userMessageContent));
        $messages->push(new UserMessage(
            'BLOCKS_JSON is the authoritative schedule (order and implied timing) computed server-side. The app shows each row with exact start, end, and duration right after your framing.'.$horizonHint."\n\n".
            'Return JSON only: framing, reasoning, confirmation. Voice: warm, concise coach.'."\n".
            '- framing: 1–2 sentence hand-off to the list. Do not repeat per-item clock times or lengths (the UI prints them next).'."\n".
            '- reasoning: why this order and window fit the student (focus, deadlines, calendar pressure)—without quoting specific times or durations that duplicate the list.'."\n".
            '- confirmation: clear check-in—do these times and block lengths feel workable? Invite them to describe tweaks in chat (earlier/later/longer/shorter/different order) and that nothing is final until they save. 1–3 sentences. Do not mention Accept all or UI buttons.'."\n\n".
            'Do not invent times other than BLOCKS_JSON implies. No task IDs, JSON, snapshot, or backend terms.'."\n\n".
            'BLOCKS_JSON: '.$blocksJson.$digestBlock
        ));

        $framing = '';
        $reasoning = $deterministicReasoning;
        $confirmation = '';

        try {
            $structuredResponse = $this->attemptStructured(
                $messages,
                $promptData,
                $refinementSchema,
                $maxRetries,
                $generationRoute
            );

            $payload = $structuredResponse->structured ?? [];
            $payload = is_array($payload) ? $payload : [];

            $framing = $this->sanitizeFraming(
                trim((string) ($payload['framing'] ?? '')),
                max(1, $blockCount),
                'schedule|'.$threadId
            );

            $llmReasoning = trim((string) ($payload['reasoning'] ?? ''));
            $reasoning = $llmReasoning !== ''
                ? TaskAssistantPrioritizeOutputDefaults::clampPrioritizeReasoning($llmReasoning)
                : TaskAssistantPrioritizeOutputDefaults::clampPrioritizeReasoning($deterministicReasoning);

            $confirmation = TaskAssistantPrioritizeOutputDefaults::clampNextField(
                trim((string) ($payload['confirmation'] ?? ''))
            );
        } catch (\Throwable $e) {
            Log::warning('task-assistant.daily-schedule.refinement_failed', [
                'layer' => 'llm_narrative',
                'user_id' => $userId,
                'thread_id' => $threadId,
                'error' => $e->getMessage(),
            ]);

            $fallback = $this->scheduleNarrativeFallback(
                blockCount: max(1, $blockCount),
                schedulableProposalCount: $schedulableProposalCount,
                deterministicReasoning: $deterministicReasoning,
                seed: 'fallback|'.$threadId
            );
            $framing = $fallback['framing'];
            $reasoning = $fallback['reasoning'];
            $confirmation = $fallback['confirmation'];
        }

        if ($framing === '') {
            $framing = $this->scheduleNarrativeFallback(
                blockCount: max(1, $blockCount),
                schedulableProposalCount: $schedulableProposalCount,
                deterministicReasoning: $deterministicReasoning,
                seed: 'empty_framing|'.$threadId
            )['framing'];
        }
        if ($confirmation === '') {
            $confirmation = $this->scheduleNarrativeFallback(
                blockCount: max(1, $blockCount),
                schedulableProposalCount: $schedulableProposalCount,
                deterministicReasoning: $deterministicReasoning,
                seed: 'empty_confirmation|'.$threadId
            )['confirmation'];
        }

        return [
            'framing' => TaskAssistantPrioritizeOutputDefaults::clampFraming($framing),
            'reasoning' => $reasoning,
            'confirmation' => $confirmation,
        ];
    }

    /**
     * @return array{framing: string, reasoning: string, confirmation: string}
     */
    private function buildEmptyPlacementScheduleNarrative(
        string $deterministicReasoning,
    ): array {
        $cfg = config('task-assistant.schedule.empty_placement', []);
        $cfg = is_array($cfg) ? $cfg : [];

        $framing = TaskAssistantPrioritizeOutputDefaults::clampFraming(trim((string) ($cfg['framing'] ?? '')));
        if ($framing === '') {
            $framing = TaskAssistantPrioritizeOutputDefaults::clampFraming(
                'Nothing in this slice could be placed cleanly in open time. We can still pick a next step together.'
            );
        }

        $reasoning = TaskAssistantPrioritizeOutputDefaults::clampPrioritizeReasoning(trim((string) ($cfg['reasoning'] ?? '')));
        if ($reasoning === '') {
            $reasoning = TaskAssistantPrioritizeOutputDefaults::clampPrioritizeReasoning($deterministicReasoning);
        }

        $confirmation = TaskAssistantPrioritizeOutputDefaults::clampNextField(trim((string) ($cfg['confirmation'] ?? '')));
        if ($confirmation === '') {
            $legacy = trim((string) ($cfg['next_options'] ?? ''));
            $confirmation = $legacy !== ''
                ? TaskAssistantPrioritizeOutputDefaults::clampNextField($legacy)
                : TaskAssistantPrioritizeOutputDefaults::clampNextField(
                    'Does trying a wider time window or prioritizing what to tackle first sound good, or would you rather adjust something specific?'
                );
        }

        return [
            'framing' => $framing,
            'reasoning' => $reasoning,
            'confirmation' => $confirmation,
        ];
    }

    /**
     * @return array{framing: string, reasoning: string, confirmation: string}
     */
    private function scheduleNarrativeFallback(
        int $blockCount,
        int $schedulableProposalCount,
        string $deterministicReasoning,
        string $seed,
    ): array {
        $framing = $this->sanitizeFraming(
            $this->firstPersonFramingFallback(max(1, $blockCount), $seed.'|schedule'),
            max(1, $blockCount),
            $seed
        );

        $confirm = 'Do these times and how long each block runs feel okay? If not, say what you would like to change in chat and we can fix it before you save.';
        if ($schedulableProposalCount > 1) {
            $confirm = 'Do these times and durations line up with what you want? Tell me in chat if anything should move or resize—we can adjust the plan before you save.';
        }

        return [
            'framing' => $framing,
            'reasoning' => TaskAssistantPrioritizeOutputDefaults::clampPrioritizeReasoning($deterministicReasoning),
            'confirmation' => TaskAssistantPrioritizeOutputDefaults::clampNextField($confirm),
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
     *   items: list<array<string, mixed>>,
     *   focus: array{main_task: string, secondary_tasks: list<string>},
     *   acknowledgment: string|null,
     *   framing: string,
     *   reasoning: string,
     *   next_options: string,
     *   next_options_chip_texts: list<string>,
     *   filter_interpretation: string|null,
     *   assumptions: list<string>|null,
     *   doing_progress_coach: string|null
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

        $prioritizeVariant = trim((string) ($promptData['prioritize_variant'] ?? 'rank'));
        if ($prioritizeVariant === '') {
            $prioritizeVariant = 'rank';
        }

        $doingContext = is_array($promptData['doing_context'] ?? null) ? $promptData['doing_context'] : null;
        $doingCountForPrompt = is_array($doingContext) ? (int) ($doingContext['doing_count'] ?? 0) : 0;
        $hasDoingContext = is_array($doingContext)
            && ($doingContext['has_doing_tasks'] ?? false)
            && $doingCountForPrompt > 0;

        $doingCoachRequired = $hasDoingContext;
        $emptyRankedSlice = $listedTaskCount === 0;

        $doingTitleLines = is_array($doingContext) && is_array($doingContext['doing_titles'] ?? null)
            ? $doingContext['doing_titles']
            : [];
        $titleBlob = implode('; ', array_values(array_filter(
            array_map(static fn (mixed $t): string => trim((string) $t), $doingTitleLines),
            static fn (string $s): bool => $s !== ''
        )));
        $doingTitlesSanitize = array_values(array_filter(
            array_map(static fn (mixed $t): string => trim((string) $t), $doingTitleLines),
            static fn (string $s): bool => $s !== ''
        ));

        $outputFieldOrder = <<<'TXT'
OUTPUT_FIELD_ORDER (student-visible; the app assembles the final message in this order—write each field for its slot):
1. acknowledgment (optional) — brief empathy when required
2. When DOING_COACH_REQUIRED: doing_progress_coach first, then the in-progress titles (from Doing status; rendered by the app), then framing, then a short bridge, then the numbered ITEMS_JSON list—do NOT tell the student to “start with” the top ranked row in framing when Doing exists; orient to what is already in motion, then hand off to the ranked list. ITEMS_JSON rows are not Doing; never say the student has “started” or is “already working on” the top ranked item in framing (that falsely implies in-progress status).
3. When DOING_COACH_REQUIRED is false: framing (short intro) before the numbered list.
4. numbered list from ITEMS_JSON (rendered by the app; do not paste it as a second enumerated list in other fields)
5. filter_interpretation — optional; appears after that list
6. reasoning — coach paragraph before scheduling: why row #1 is first when LISTED_ITEM_COUNT >= 1; only describe the task using words grounded in that row’s title (and fields)—never invent a subject/domain (e.g. “web design”) that the title does not support
7. next_options — LAST paragraph only: scheduling / follow-up offers (keep coaching light here)

NARRATIVE_COACH_DISTRIBUTION: Spread motivation, empathy, and tips across acknowledgment (optional), framing, doing_progress_coach, filter_interpretation, and reasoning—do not save all warmth for a single field. next_options must be last and is mainly scheduling/chips setup, not the main coaching beat.

TXT;

        $variantInstruction = $emptyRankedSlice
            ? 'PRIORITIZE_VARIANT: '.$prioritizeVariant.'. ITEMS_JSON is empty: there are zero ranked rows in this slice (non-Doing tasks may be absent or filtered out).'
            : 'PRIORITIZE_VARIANT: '.$prioritizeVariant.'. Rows are urgency-ranked. framing is intro only (see OUTPUT_FIELD_ORDER). When LISTED_ITEM_COUNT >= 1, put why row #1 is first in reasoning (before next_options), not in framing.';

        $coachContextBlock = TaskAssistantPrioritizeOutputDefaults::buildPrioritizeNarrativeCoachContextBlock(
            $items,
            $prioritizeVariant,
            $doingCoachRequired
        );

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

        $listedCountInstruction = $emptyRankedSlice
            ? 'LISTED_ITEM_COUNT: 0. ITEMS_JSON is empty. Do not invent ranked tasks or a first-row title from ITEMS_JSON. '
            : ($listedTaskCount === 1
                ? 'LISTED_ITEM_COUNT: 1. The student sees exactly ONE prioritized row. Use strictly singular grammar in framing, reasoning, acknowledgment (if any), and next_options: say "this '.$firstRowEntity.'" or "that '.$firstRowEntity.'"—never "these '.$firstRowPlural.'" or "those '.$firstRowPlural.'"; "priority" not "priorities"; use it/this '.$firstRowEntity.' (not they/them) when referring to that row. Do not describe one row as multiple items. '
                : 'LISTED_ITEM_COUNT: '.$listedTaskCount.'. Multiple rows: plural wording is fine when referring to the set. ');

        $doingProgressPromptBlock = '';
        if ($hasDoingContext) {
            $doingProgressPromptBlock = $emptyRankedSlice
                ? "\n\nDOING_PROGRESS_CONTEXT: The student has {$doingCountForPrompt} task(s) marked in progress (Doing). DOING_TITLES_FOR_UI (the app shows these as a separate numbered list—reference only): {$titleBlob}. CRITICAL: doing_progress_coach must NOT name, quote, or paraphrase any title from ITEMS_JSON (there are no ranked rows here—still do not invent slice titles). Use generic language only (e.g. what you have in motion, what you already started). If you cannot comply, output one short generic motivational sentence with zero task or event titles. framing and reasoning should address the empty ranked slice and/or focusing on what is already underway; do not claim a first ranked row exists in ITEMS_JSON.\n"
                : "\n\nDOING_PROGRESS_CONTEXT: The student has {$doingCountForPrompt} task(s) marked in progress (Doing). DOING_TITLES_FOR_UI (shown separately in the UI—reference only): {$titleBlob}. CRITICAL: doing_progress_coach must NOT name, quote, or paraphrase any title that appears in ITEMS_JSON (ranked slice). Those rows are not Doing status—only DOING_TITLES_FOR_UI are in-progress tasks. Motivation must be generic (in motion, already underway, what you started) or pronouns—no quoted task/event titles. CRITICAL: doing_progress_coach must NOT borrow subjects from ITEMS_JSON (no quizzes, exams, lecture notes, readings, responses, homework, problem sets, or “overdue notes”) unless that exact word already appears inside DOING_TITLES_FOR_UI when you join them case-insensitively—this field is only about momentum on what they already started, not about ranked next steps. If you cannot comply, output one short generic sentence with zero titles. In reasoning, anchor only to the first row in ITEMS_JSON when LISTED_ITEM_COUNT >= 1. Do not argue that a Doing task should outrank ITEMS_JSON row #1. Do not repeat the Doing title list in acknowledgment, framing, filter_interpretation, or reasoning as an enumerated list.\n";
        }

        $narrativeFieldRoles = $emptyRankedSlice
            ? 'NARRATIVE_FIELD_ROLES (each field has ONE job; do not reuse the same sentences or stock phrases across fields): '.
            'acknowledgment = brief empathy only when required; no task titles or list summary. '.
            'doing_progress_coach = REQUIRED when DOING_COACH_REQUIRED is true: motivation only—no Doing task title list (titles appear separately). '.
            'framing = intro only: orient for empty ranked slice and/or leaning on in-progress work; 1–3 sentences. When DOING_COACH_REQUIRED and LISTED_ITEM_COUNT >= 1, do NOT name any ITEMS_JSON title in framing (enforced server-side)—hand off to in-progress, then ranked list appears below. '.
            'filter_interpretation = the student sees this after the list area; filters/wording only; use null if it would repeat framing. '.
            'reasoning = before next_options (not last): why the empty slice still makes sense to address in-progress work first, or how to widen filters—do NOT reference a first row in ITEMS_JSON (there is none). '.
            'next_options = LAST in the message; scheduling or widening filters only; do not invent ranked tasks. '
            : 'NARRATIVE_FIELD_ROLES (each field has ONE job; do not reuse the same sentences or stock phrases across fields): '.
            'acknowledgment = brief empathy only when required; no task titles or list summary. '.
            'doing_progress_coach = REQUIRED when DOING_COACH_REQUIRED is true: motivation only—no Doing task title list (titles appear separately). '.
            'framing = intro only—1–3 sentences; when DOING_COACH_REQUIRED, orient toward what is already in motion; do NOT name any ITEMS_JSON title or paraphrase the top ranked row (that belongs in reasoning before next_options; server-side enforcement strips leaks). Framing prints before the numbered ranked list appears—avoid "starting with this/these" for a row the student has not seen yet; do not recycle the same stress/quiz-prep lines as acknowledgment. When there is no Doing, keep framing light—save the top-row urgency story for reasoning. You may include light encouragement or one short tip here if it fits. '.
            'filter_interpretation = the student sees this after the numbered list; filters/wording only; use null if not helpful or if it would repeat framing. '.
            'reasoning = before next_options: why the first ITEMS_JSON row is first when LISTED_ITEM_COUNT >= 1; include that row\'s exact title once; add empathy, motivation, or a grounded micro-tip when helpful. Describe work types using words from row #1\'s title (not a different task\'s course or lab). When LISTED_ITEM_COUNT is 1 and DOING_COACH_REQUIRED is true, do NOT reference Doing-only task titles or course codes from DOING_TITLES_FOR_UI—those are not ITEMS_JSON. When LISTED_ITEM_COUNT > 1, do NOT mention row 2+ titles or their distinctive topics (for example lecture notes, a reading response) and do NOT invent worksheets, practice/problem sets, or mock exams—only describe work using words grounded in row #1\'s title plus its priority and due fields. Do not repeat overdue/complex boilerplate already covered in framing or filter_interpretation. '.
            'next_options = LAST in the message; scheduling/follow-up only; do not re-summarize the full list or repeat the coaching paragraph. '.
            'COACHING: Across framing+reasoning, the student should get at least one helpful coach element (motivation, overload reframing, or a concrete study/task habit) tied to the listed titles/dates—never generic platitudes. Put the main tip in framing OR reasoning, not both. '.
            'Vary openings a little, but keep the same calm supportive voice everywhere. ';

        $firstRowReasoningRule = $emptyRankedSlice
            ? 'reasoning is REQUIRED (before next_options, which is last): speak to the student directly. Do not reference a first-row title from ITEMS_JSON (ITEMS_JSON may be empty). Explain why focusing on in-progress work and/or adjusting filters is a sensible next step. '
            : 'reasoning is REQUIRED (before next_options, which is last): speak to the student directly (I/You/Let\'s/We are all fine). Do not use third-person phrasing like "the user ...", "they match ...", or "this list matches ...". For a single-item list you may use "it" clearly tied to that task. Ground every claim in ITEMS_JSON (titles, due_phrase, priority). '.
            'Always mention the exact title of the first row in ITEMS_JSON at least once, and explain why that row is first using row #1 only—when LISTED_ITEM_COUNT > 1, do not fold in row 2+ subjects (notes, readings, other classes) or invented assignments. '.
            'Do not add stiff meta lines about "ordered list", "first on this list", or "when you are ready" boilerplate. ';

        $dueTimeSafetyBlock = $emptyRankedSlice
            ? 'DUE-TIME SAFETY: If ITEMS_JSON is empty, do not claim due dates for ranked tasks. '
            : 'DUE-TIME SAFETY: Do not paraphrase due-time. If you mention "due today", "due tomorrow", "overdue", or "due this week", it MUST match the exact wording present in at least one items[].due_phrase. Never mention due-time phrasing that is not present in items due_phrase values. ';

        $listingIntro = $emptyRankedSlice
            ? 'ITEMS_JSON may be empty. There are no ranked rows to reorder. Write the user-facing narrative fields (including doing_progress_coach when required). '
            : 'Use the following rows as the prioritized list for this request (tasks, events, or projects). '.
            'Do NOT change ordering or membership. Write only the user-facing narrative fields (acknowledgment, doing_progress_coach when required, framing, reasoning, optional filter_interpretation and assumptions, and options). ';

        $prioritizeNarrativeRoleAnchor = 'PRIORITIZE_NARRATIVE_ROLE: You are the student\'s task assistant—coach and motivator—in every JSON string you output. Sound supportive, clear, and practical; guide without scolding. For small/local models (e.g. Hermes 3:3B): short sentences, one main idea per field, follow OUTPUT_FIELD_ORDER exactly; never dry list-speak or bureaucratic tone.'."\n\n";

        $messages = collect([
            new UserMessage($userMessage),
            new UserMessage(
                $prioritizeNarrativeRoleAnchor.
                $listingIntro."\n\n".
                $outputFieldOrder."\n".
                $variantInstruction."\n\n".
                $coachContextBlock."\n\n".
                $listLabel."\n\n".
                $listedCountInstruction.
                $doingProgressPromptBlock.
                $narrativeFieldRoles."\n".
                'You are the task assistant speaking to the student as a supportive coach. '.
                'Use a calm, reassuring, empathetic tone with light motivation when it fits—students should feel guided, not scolded. When UX_INCLUDE_ACK is true, the acknowledgment should validate the user\'s feelings briefly and smoothly transition into action, and the rest of the message should stay supportive and steady. '.
                'Do not claim extra visibility into the student\'s full life or full list. Avoid phrases like "I reviewed your tasks", "I looked at your list", "I see you have", or "I\'ve taken a look at your to-do list". '.
                'In acknowledgment, framing, reasoning, and next_options: NEVER mention snapshot, "snapshot data", JSON, ITEMS_JSON, FILTER_CONTEXT, backend, database, or internal technical terms—the student only sees plain English. '.
                'doing_progress_coach is REQUIRED (non-null, non-empty) when DOING_COACH_REQUIRED is true—motivation only; must NOT contain any title from ITEMS_JSON. Keep it about staying steady on what they already started (Doing), and never smuggle ranked-only subjects (quizzes, lecture notes, readings, etc.) unless those words literally appear in DOING_TITLES_FOR_UI. When DOING_COACH_REQUIRED is false, doing_progress_coach MUST be null. '.
                'filter_interpretation is OPTIONAL: one short sentence; the student sees it after the numbered list—explain how filters or wording shaped this slice; null if not helpful. assumptions is OPTIONAL: prefer null. Only include if strictly needed to interpret a filter (e.g. calendar today). Never assume the user already viewed their list; never invent calendar dates. null or empty if none. '.
                'framing is REQUIRED: short intro only—open in natural assistant voice (I recommend, I suggest, Let\'s, We could, Here\'s what I\'d do—vary openings across turns). Sound human and supportive. When DOING_COACH_REQUIRED is true and LISTED_ITEM_COUNT >= 1, do NOT mention any ITEMS_JSON title in framing—orient to in-progress work or a smooth handoff to the ranked next steps below (say “the ranked list below” / “the next steps below” when LISTED_ITEM_COUNT > 1, not vague “this list” that could mean Doing). Never claim the student has “started” or is “already working on” the top ranked item in framing—those rows are To Do until marked Doing; describe “what to tackle next” only in reasoning. When LISTED_ITEM_COUNT >= 1 and there is no Doing, keep framing as a light intro—save "start with [top row]" for reasoning. Do not use impersonal brochure openers like "Here is your top priority in a simple order". '.
                'Never say the student "found", "discovered", or "has" a task "on their list" as if they unearthed it. Use "your attention" or "your focus", not "our attention". '.
                'acknowledgment is OPTIONAL: include only when UX_INCLUDE_ACK is true; otherwise set it to null. When included, it must be exactly one short empathetic sentence. '.
                $firstRowReasoningRule.
                'next_options is REQUIRED: 1-2 sentences; the student sees this LAST after reasoning. Offer follow-up (e.g., scheduling, widening filters when the slice is empty). Keep it scheduling-focused—main empathy and coaching belong earlier. Do not re-summarize the ranked list here. Do not suggest rescheduling tasks that were already completed; if you mention rescheduling, it should be about the remaining tasks. '.
                'next_options_chip_texts is REQUIRED: array of 1-2 short chip strings to let the student trigger that follow-up (no question marks). '.
                'UX_INCLUDE_ACK: '.($includeAcknowledgment ? 'true' : 'false').
                "\n".
                'DOING_COACH_REQUIRED: '.($doingCoachRequired ? 'true' : 'false').
                "\n".
                $dueTimeSafetyBlock.
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
        $filterInterpretation = null;
        $assumptionsNormalized = null;
        $doingProgressCoachNarrative = null;
        $cleanItems = $this->copyPrioritizeItemsWithoutPlacementBlurbs($items);

        $prioritizeNarrativeConnectionFailed = false;

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

            if (isset($payload['filter_interpretation']) && is_string($payload['filter_interpretation'])) {
                $fi = trim($payload['filter_interpretation']);
                if ($fi !== '') {
                    $filterInterpretation = mb_substr($fi, 0, 280);
                }
            }

            if (isset($payload['assumptions']) && is_array($payload['assumptions'])) {
                $assumptionLines = [];
                foreach ($payload['assumptions'] as $row) {
                    if (! is_string($row)) {
                        continue;
                    }
                    $line = trim($row);
                    if ($line === '') {
                        continue;
                    }
                    $assumptionLines[] = mb_substr($line, 0, 240);
                    if (count($assumptionLines) >= 4) {
                        break;
                    }
                }
                $assumptionsNormalized = $assumptionLines === [] ? null : $assumptionLines;
            }

            if (isset($payload['doing_progress_coach']) && is_string($payload['doing_progress_coach'])) {
                $dpc = trim($payload['doing_progress_coach']);
                if ($dpc !== '') {
                    $doingProgressCoachNarrative = TaskAssistantPrioritizeOutputDefaults::clampDoingProgressCoach($dpc);
                }
            }
        } catch (\Throwable $e) {
            $prioritizeNarrativeConnectionFailed = true;
            Log::warning('task-assistant.prioritize.narrative_failed', [
                'layer' => 'llm_narrative',
                'user_id' => $userId,
                'thread_id' => $threadId,
                'error' => $e->getMessage(),
            ]);
        }

        if (! is_string($framing) || trim($framing) === '') {
            if ($prioritizeNarrativeConnectionFailed && $cleanItems !== []) {
                $framing = PrioritizeNarrativeConnectionFallback::framing($cleanItems, $userMessage);
            } elseif (trim($deterministicSummary) !== '') {
                $framing = trim($deterministicSummary);
            } else {
                $framing = $this->firstPersonFramingFallback(max(1, $listedTaskCount), $userMessage.'|'.$threadId);
            }
        }
        $framing = $this->sanitizeFraming((string) $framing, $listedTaskCount, $userMessage.'|'.$threadId);

        if ($nextOptions === null || trim($nextOptions) === '') {
            $nextOptions = __('If you want, I can schedule these steps for later.');
        }

        if (mb_strlen((string) $nextOptions) < 5) {
            $nextOptions = __('If you want, I can schedule these steps for later.');
        }
        $nextOptions = $this->sanitizeNextOptions((string) $nextOptions, is_array($cleanItems) ? count($cleanItems) : 0, $emptyRankedSlice);

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

        if (! $doingCoachRequired) {
            $doingProgressCoachNarrative = null;
        } elseif ($doingProgressCoachNarrative === null || trim((string) $doingProgressCoachNarrative) === '') {
            $doingProgressCoachNarrative = TaskAssistantPrioritizeOutputDefaults::buildDoingProgressCoachMotivationFallback($doingCountForPrompt);
        }

        if ($doingCoachRequired && is_string($doingProgressCoachNarrative) && trim($doingProgressCoachNarrative) !== ''
            && TaskAssistantPrioritizeOutputDefaults::doingProgressCoachLeaksRankedSliceTitles($doingProgressCoachNarrative, $cleanItems)) {
            Log::warning('task-assistant.prioritize.doing_coach_slice_title_leak', [
                'layer' => 'llm_narrative',
                'reason' => 'doing_coach_slice_title_leak',
                'user_id' => $userId,
                'thread_id' => $threadId,
            ]);
            $doingProgressCoachNarrative = TaskAssistantPrioritizeOutputDefaults::buildDoingProgressCoachMotivationFallback($doingCountForPrompt);
        }

        if ($doingCoachRequired && is_string($doingProgressCoachNarrative) && trim($doingProgressCoachNarrative) !== '' && $cleanItems !== []) {
            $doingTitlesSanitize = array_values(array_filter(
                array_map(static fn (mixed $t): string => trim((string) $t), $doingTitleLines),
                static fn (string $s): bool => $s !== ''
            ));
            $strippedCoach = TaskAssistantPrioritizeOutputDefaults::sanitizeDoingProgressCoachAgainstRankedContentBleed(
                (string) $doingProgressCoachNarrative,
                $cleanItems,
                $doingTitlesSanitize,
            );
            $minCoach = (int) config('task-assistant.listing.prioritize_doing_coach_min_chars_after_bleed_strip', 45);
            if (mb_strlen(trim($strippedCoach)) < max(25, $minCoach)) {
                Log::warning('task-assistant.prioritize.doing_coach_ranked_bleed_strip_fallback', [
                    'layer' => 'llm_narrative',
                    'reason' => 'ranked_content_bleed_strip_short',
                    'user_id' => $userId,
                    'thread_id' => $threadId,
                ]);
                $doingProgressCoachNarrative = TaskAssistantPrioritizeOutputDefaults::buildDoingProgressCoachMotivationFallback($doingCountForPrompt);
            } else {
                $doingProgressCoachNarrative = TaskAssistantPrioritizeOutputDefaults::clampDoingProgressCoach($strippedCoach);
            }
        }

        // Remove redundancy between acknowledgment and framing for stress prompts.
        $framing = $this->dedupeAcknowledgmentAndFraming($acknowledgment, (string) $framing, $cleanItems, $doingCoachRequired);

        // Safety net against LLM due-date drift (e.g. "tomorrow" vs items[].due_phrase="due today").
        $allowedDuePhrases = $this->extractTaskDuePhrases($cleanItems);
        $framing = $this->rewriteFramingWhenDueSoonConflictsWithOverdue((string) $framing, $cleanItems);
        $framingConflict = $this->hasConflictingDueTiming((string) $framing, $allowedDuePhrases);
        if ($framingConflict) {
            $framing = $this->firstPersonFramingFallback(max(1, $listedTaskCount), $userMessage.'|'.$threadId.'|due_time');
        }

        $framingBeforeRankedTitleSanitize = (string) $framing;
        $framing = TaskAssistantPrioritizeOutputDefaults::refineFramingWhenDoingCoexistsAvoidRankedTitles(
            (string) $framing,
            $cleanItems,
            $doingCoachRequired,
            $userMessage.'|'.$threadId,
        );
        if ($doingCoachRequired && $cleanItems !== [] && $framingBeforeRankedTitleSanitize !== $framing) {
            Log::warning('task-assistant.prioritize.framing_ranked_slice_when_doing_sanitized', [
                'layer' => 'llm_narrative',
                'reason' => 'framing_ranked_slice_when_doing',
                'user_id' => $userId,
                'thread_id' => $threadId,
            ]);
        }

        $framingBeforePrematureDeictic = (string) $framing;
        $framing = TaskAssistantPrioritizeOutputDefaults::refineFramingPrematureDeicticBeforeRankedList(
            (string) $framing,
            $cleanItems,
            $doingCoachRequired,
            $userMessage.'|'.$threadId.'|deictic',
        );
        if ($framingBeforePrematureDeictic !== $framing) {
            Log::debug('task-assistant.prioritize.framing_premature_deictic_stripped', [
                'layer' => 'llm_narrative',
                'user_id' => $userId,
                'thread_id' => $threadId,
            ]);
        }

        $filterInterpretation = TaskAssistantPrioritizeOutputDefaults::dedupePrioritizeFilterVersusFraming(
            $filterInterpretation,
            (string) $framing
        );

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
            if ($prioritizeNarrativeConnectionFailed && $cleanItems !== []) {
                $reasoning = PrioritizeNarrativeConnectionFallback::reasoning($cleanItems);
            } elseif ($cleanItems === [] && $hasDoingContext) {
                $reasoning = TaskAssistantPrioritizeOutputDefaults::clampPrioritizeReasoning(
                    (string) __('When you wrap up what you\'ve started, the next priorities will show up more clearly here.')
                );
            } else {
                $reasoning = TaskAssistantPrioritizeOutputDefaults::reasoningWhenEmpty();
            }
        }

        // Enforce student-directed POV (avoid third-person phrasing).
        $reasoning = TaskAssistantPrioritizeOutputDefaults::normalizePrioritizeReasoningVoice((string) $reasoning, $cleanItems);

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

        $framingBeforeAckThematicDedupe = (string) $framing;
        $framing = TaskAssistantPrioritizeOutputDefaults::dedupePrioritizeFramingVersusAcknowledgment(
            $acknowledgment,
            (string) $framing,
            $cleanItems,
            $doingCoachRequired,
            $userMessage.'|'.$threadId.'|ack_thematic',
        );
        if ($framingBeforeAckThematicDedupe !== $framing) {
            Log::debug('task-assistant.prioritize.framing_ack_thematic_deduped', [
                'layer' => 'llm_narrative',
                'user_id' => $userId,
                'thread_id' => $threadId,
            ]);
        }

        $reasoning = TaskAssistantPrioritizeOutputDefaults::dedupePrioritizeReasoningVersusPriorFields(
            (string) $reasoning,
            $acknowledgment,
            (string) $framing,
            $filterInterpretation,
            $cleanItems,
            null,
            (string) $nextOptions,
        );

        $reasoning = TaskAssistantPrioritizeOutputDefaults::stripReasoningSentencesBleedingSecondaryRankedRows((string) $reasoning, $cleanItems);
        $reasoning = TaskAssistantPrioritizeOutputDefaults::stripReasoningSentencesWithInventedStudyArtifacts((string) $reasoning, $cleanItems);
        $reasoning = TaskAssistantPrioritizeOutputDefaults::stripReasoningSentencesWithUngroundedAboutClaims((string) $reasoning, $cleanItems);
        $reasoning = TaskAssistantPrioritizeOutputDefaults::stripReasoningSentencesEchoingDoingTitlesWhenSingleRankedRow(
            (string) $reasoning,
            $cleanItems,
            $doingTitlesSanitize,
        );

        // Ensure reasoning stays anchored to the ranked list (especially item #1)—after cross-field dedupe.
        $reasoning = $this->enforceReasoningAnchorsTopItem((string) $reasoning, $cleanItems);
        $reasoning = $this->normalizeReasoningOverdueGrammar((string) $reasoning, $cleanItems);

        $nextOptions = TaskAssistantPrioritizeOutputDefaults::dedupePrioritizeNextVersusPriorFields(
            (string) $nextOptions,
            (string) $framing,
            (string) $reasoning,
            is_array($cleanItems) ? count($cleanItems) : 0
        );

        $focus = [
            'main_task' => 'No matching items found',
            'secondary_tasks' => [],
        ];
        if ($cleanItems === [] && $hasDoingContext) {
            $focus['main_task'] = (string) __('Wrap up in-progress work first');
        }
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
                ? TaskAssistantPrioritizeOutputDefaults::clampFraming(
                    TaskAssistantPrioritizeOutputDefaults::coerceSingularPrioritizeNarrative((string) $acknowledgment, $singularCoerceCount, $cleanItems)
                )
                : null,
            'framing' => TaskAssistantPrioritizeOutputDefaults::clampFraming(
                TaskAssistantPrioritizeOutputDefaults::coerceSingularPrioritizeNarrative((string) $framing, $singularCoerceCount, $cleanItems)
            ),
            'reasoning' => TaskAssistantPrioritizeOutputDefaults::clampPrioritizeReasoning(
                TaskAssistantPrioritizeOutputDefaults::coerceSingularPrioritizeNarrative((string) $reasoning, $singularCoerceCount, $cleanItems)
            ),
            'next_options' => TaskAssistantPrioritizeOutputDefaults::clampNextField(
                TaskAssistantPrioritizeOutputDefaults::coerceSingularPrioritizeNarrative((string) $nextOptions, $singularCoerceCount, $cleanItems)
            ),
            'next_options_chip_texts' => array_values(array_map(
                static fn (mixed $t): string => TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText(
                    TaskAssistantPrioritizeOutputDefaults::coerceSingularPrioritizeNarrative((string) $t, $singularCoerceCount, $cleanItems)
                ),
                $nextOptionsChipTexts
            )),
            'filter_interpretation' => $filterInterpretation,
            'assumptions' => TaskAssistantPrioritizeOutputDefaults::filterPrioritizeAssumptions($assumptionsNormalized),
            'doing_progress_coach' => $doingProgressCoachNarrative,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function enforceReasoningAnchorsTopItem(string $reasoning, array $items): string
    {
        if ($items === []) {
            return $reasoning;
        }

        $first = is_array($items[0] ?? null) ? $items[0] : null;
        if (! is_array($first)) {
            return $reasoning;
        }

        $title = trim((string) ($first['title'] ?? ''));
        $text = trim(TaskAssistantPrioritizeOutputDefaults::stripRoboticPrioritizeReasoningTail($reasoning));

        if ($text === '') {
            return $title !== ''
                ? $this->softReasoningAnchorWhenTopTitleMissing($title, '')
                : TaskAssistantPrioritizeOutputDefaults::reasoningWhenEmpty();
        }

        if ($title === '') {
            return $text;
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
                $copy['placement_blurb'] = TaskAssistantPrioritizeOutputDefaults::clampItemPlacementBlurb($line);
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
            'suggested_guidance' => TaskAssistantPrioritizeOutputDefaults::defaultSuggestedGuidance(),
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
                $pending = Prism::structured()
                    ->using($this->resolveProvider(), $this->resolveModel())
                    ->withSystemPrompt(view('prompts.task-assistant-system', $promptData))
                    ->withMessages($messages->all())
                    ->withTools([])
                    ->withSchema($refinementSchema);

                $pending = $this->applyStructuredGenerationOptions($pending, $generationRoute);

                return $pending->asStructured();
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

    private function applyStructuredGenerationOptions(StructuredPendingRequest $pending, string $generationRoute): StructuredPendingRequest
    {
        $timeout = (int) config('prism.request_timeout', 120);
        $pending = $pending->withClientOptions(['timeout' => $timeout]);

        $base = 'task-assistant.generation';
        $routeKey = $base.'.'.$generationRoute;

        $temperature = config($routeKey.'.temperature');
        $maxTokens = config($routeKey.'.max_tokens');
        $topP = config($routeKey.'.top_p');

        if (! is_numeric($temperature)) {
            $temperature = config($base.'.temperature');
        }
        if (! is_numeric($maxTokens)) {
            $maxTokens = config($base.'.max_tokens');
        }

        if ($generationRoute === 'prioritize_narrative' || $generationRoute === 'schedule_narrative' || $generationRoute === 'schedule_narrative_followup') {
            if (! is_numeric($topP)) {
                $topP = null;
            }
        } elseif (! is_numeric($topP)) {
            $topP = config($base.'.top_p');
        }

        if (is_numeric($temperature)) {
            $pending = $pending->usingTemperature((float) $temperature);
        }
        if (is_numeric($maxTokens)) {
            $pending = $pending->withMaxTokens((int) $maxTokens);
        }
        if (is_numeric($topP)) {
            $pending = $pending->usingTopP((float) $topP);
        }

        return $pending;
    }
}

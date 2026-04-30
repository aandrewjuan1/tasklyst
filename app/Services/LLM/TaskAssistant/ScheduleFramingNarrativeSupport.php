<?php

namespace App\Services\LLM\TaskAssistant;

use Carbon\CarbonImmutable;

/**
 * Schedule-only framing sanitization and fallbacks (do not reuse prioritize "list below" voice).
 */
final class ScheduleFramingNarrativeSupport
{
    private const USER_MESSAGE_MAX_SEED_CHARS = 200;

    private const LABEL_DISPLAY_MAX_CHARS = 72;

    /**
     * @param  list<array{start_time?:string,end_time?:string,label?:string}>  $parsedBlocks
     * @param  array<string, mixed>  $promptData
     */
    public static function buildFallback(
        array $parsedBlocks,
        array $promptData,
        int $schedulableProposalCount,
        string $userMessageContent,
        string $seed,
    ): string {
        $blockCount = count($parsedBlocks);
        $windowPhrase = self::deriveWindowPhrase($promptData);
        $windowLead = self::windowPhraseForSentenceStart($windowPhrase);
        $labels = self::collectBlockLabels($parsedBlocks);
        $primaryLabel = $labels[0] ?? 'this work';
        $primaryLabel = self::truncateLabel($primaryLabel);

        $seedMaterial = $seed.'|'.mb_substr(trim($userMessageContent), 0, self::USER_MESSAGE_MAX_SEED_CHARS);

        $count = max(1, $blockCount);
        $second = isset($labels[1]) ? self::truncateLabel($labels[1]) : 'the next item';
        $templateService = app(TaskAssistantScheduleTemplateService::class);

        return $templateService->buildFramingFallbackForScheduleRows(
            $count,
            $windowPhrase,
            $windowLead,
            $primaryLabel,
            $second,
            [
                'thread_id' => app()->bound('task_assistant.thread_id') ? (int) app('task_assistant.thread_id') : 0,
                'flow_source' => 'schedule',
                'scenario_key' => $count <= 1 ? 'schedule_rows_single' : 'schedule_rows_multi',
                'placed_count' => $count,
                'requested_window_label' => $windowPhrase,
                'day_bucket' => CarbonImmutable::now((string) config('app.timezone', 'UTC'))->toDateString(),
                'prompt_key' => substr(sha1($seedMaterial), 0, 16),
                'request_bucket' => 'schedule_rows',
            ]
        );
    }

    public static function sanitizeModelFraming(string $framing): string
    {
        $framing = trim($framing);
        if ($framing === '') {
            return '';
        }

        if (self::containsVisibilityOverclaim($framing)) {
            return '';
        }

        if (self::containsPrioritizeListBoilerplate($framing)) {
            return '';
        }

        $rewritten = preg_replace('/^based on your current priorities,?\s+/iu', 'For this plan, ', $framing, 1);
        $rewritten = is_string($rewritten) ? $rewritten : $framing;
        $rewritten = preg_replace('/^based on your upcoming tasks,?\s+/iu', 'For what is coming up, ', $rewritten, 1);
        $rewritten = is_string($rewritten) ? $rewritten : $framing;
        $rewritten = preg_replace('/^based on your tasks,?\s+/iu', 'For your tasks here, ', $rewritten, 1);
        $framing = trim(is_string($rewritten) ? $rewritten : $framing);

        if (self::containsVisibilityOverclaim($framing)) {
            return '';
        }

        if (self::containsPrioritizeListBoilerplate($framing)) {
            return '';
        }

        if (preg_match('/^here (is|are) your top priorit(?:y|ies) in a simple order\b/iu', $framing) === 1) {
            return '';
        }

        return $framing;
    }

    public static function containsVisibilityOverclaim(string $text): bool
    {
        $t = mb_strtolower($text);

        return (bool) preg_match(
            '/\b(i\s+(have\s+)?(reviewed|looked|seen|checked)|i\'?ve\s+(reviewed|looked)|i\s+see\s+you\s+have|i\s+see\s+that\s+you\s+have|taken\s+a\s+look|your\s+(current\s+)?to-?do\s+list)\b/u',
            $t
        );
    }

    public static function containsPrioritizeListBoilerplate(string $text): bool
    {
        $t = mb_strtolower($text);

        $patterns = [
            '/\border below\b/u',
            '/\bthe list below\b/u',
            '/\branked list\b/u',
            '/\bnumbered list\b/u',
            '/\btop to bottom\b/u',
            '/\bfrom top to bottom\b/u',
            '/\bone step at a time\b/u',
            '/\buse the order below\b/u',
            '/\bwork through these in the order\b/u',
            '/\bstart at the top\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $t) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $promptData
     */
    private static function deriveWindowPhrase(array $promptData): string
    {
        $dayReference = self::resolveScheduleDayReference($promptData);
        $resolvedDaypart = self::resolvePlacementDaypart($promptData);
        $flags = data_get($promptData, 'user_context.schedule_intent_flags', []);
        if (! is_array($flags)) {
            $flags = [];
        }

        if ($resolvedDaypart === 'evening' || ($flags['has_evening'] ?? false) === true) {
            return match ($dayReference) {
                'tomorrow' => 'tomorrow evening',
                'today' => 'this evening',
                default => 'in the evening',
            };
        }
        if ($resolvedDaypart === 'afternoon' || ($flags['has_afternoon'] ?? false) === true) {
            return match ($dayReference) {
                'tomorrow' => 'tomorrow afternoon',
                'today' => 'this afternoon',
                default => 'in the afternoon',
            };
        }
        if ($resolvedDaypart === 'morning' || ($flags['has_morning'] ?? false) === true) {
            return match ($dayReference) {
                'tomorrow' => 'tomorrow morning',
                'today' => 'this morning',
                default => 'in the morning',
            };
        }
        if (($flags['has_later'] ?? false) === true || ($flags['has_onwards'] ?? false) === true) {
            return match ($dayReference) {
                'tomorrow' => 'later tomorrow',
                'today' => 'later today',
                default => 'later in the day',
            };
        }

        return match ($dayReference) {
            'tomorrow' => 'in your open window tomorrow',
            'today' => 'in your open window today',
            default => 'in your open window',
        };
    }

    /**
     * Resolve coarse day reference from schedule horizon.
     * Returns "today", "tomorrow", or null when it cannot be inferred safely.
     */
    private static function resolveScheduleDayReference(array $promptData): ?string
    {
        $placementDay = self::resolveFirstPlacementDay($promptData);
        if ($placementDay !== null) {
            $todayRaw = trim((string) data_get($promptData, 'snapshot.today', data_get($promptData, 'today', '')));
            if ($todayRaw !== '') {
                try {
                    $today = CarbonImmutable::parse($todayRaw)->startOfDay();
                    $target = CarbonImmutable::parse($placementDay)->startOfDay();
                    if ($target->equalTo($today)) {
                        return 'today';
                    }
                    if ($target->equalTo($today->addDay())) {
                        return 'tomorrow';
                    }
                } catch (\Throwable) {
                    // Fall back to horizon inference below.
                }
            }
        }

        $horizon = data_get($promptData, 'schedule_horizon');
        if (! is_array($horizon)) {
            return null;
        }

        $start = trim((string) ($horizon['start_date'] ?? ''));
        if ($start === '') {
            return null;
        }

        $todayRaw = trim((string) data_get($promptData, 'snapshot.today', data_get($promptData, 'today', '')));
        if ($todayRaw === '') {
            return null;
        }

        try {
            $today = CarbonImmutable::parse($todayRaw)->startOfDay();
            $target = CarbonImmutable::parse($start)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        if ($target->equalTo($today)) {
            return 'today';
        }

        if ($target->equalTo($today->addDay())) {
            return 'tomorrow';
        }

        return null;
    }

    private static function resolveFirstPlacementDay(array $promptData): ?string
    {
        $digestDay = trim((string) data_get($promptData, 'placement_digest.days_used.0', ''));
        if ($digestDay !== '') {
            return $digestDay;
        }

        $digestPlacement = trim((string) data_get($promptData, 'placement_digest.placement_dates.0', ''));
        if ($digestPlacement !== '') {
            return $digestPlacement;
        }

        return null;
    }

    private static function resolvePlacementDaypart(array $promptData): ?string
    {
        $startRaw = trim((string) data_get($promptData, 'blocks.0.start_time', ''));
        if ($startRaw === '') {
            return null;
        }

        try {
            $hour = (int) CarbonImmutable::parse('1970-01-01 '.$startRaw)->format('H');
        } catch (\Throwable) {
            return null;
        }

        if ($hour < 12) {
            return 'morning';
        }
        if ($hour < 18) {
            return 'afternoon';
        }

        return 'evening';
    }

    private static function windowPhraseForSentenceStart(string $windowPhrase): string
    {
        if ($windowPhrase === '') {
            return 'For this window';
        }

        return mb_strtoupper(mb_substr($windowPhrase, 0, 1)).mb_substr($windowPhrase, 1);
    }

    /**
     * @param  list<array{start_time?:string,end_time?:string,label?:string}>  $parsedBlocks
     * @return list<string>
     */
    private static function collectBlockLabels(array $parsedBlocks): array
    {
        $out = [];
        foreach ($parsedBlocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            $label = trim((string) ($block['label'] ?? ''));
            if ($label !== '' && $label !== 'Focus time') {
                $out[] = $label;
            }
        }

        return $out;
    }

    private static function truncateLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return 'this item';
        }
        if (mb_strlen($label) <= self::LABEL_DISPLAY_MAX_CHARS) {
            return $label;
        }

        return rtrim(mb_substr($label, 0, self::LABEL_DISPLAY_MAX_CHARS - 1)).'…';
    }
}

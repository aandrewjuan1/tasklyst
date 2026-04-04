<?php

namespace App\Services\LLM\TaskAssistant;

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
        $n = abs(crc32($seedMaterial));

        if ($blockCount <= 1) {
            $templates = [
                "For what you asked, I blocked {$windowPhrase} time for {$primaryLabel}—the row just below has the exact start and end.",
                "I lined up a focused block for {$primaryLabel} {$windowPhrase}; you will see the times in the next line.",
                "{$windowLead}, I placed {$primaryLabel} on your plan—check the block below for the clock times.",
                "Here is the {$windowPhrase} slot I set for {$primaryLabel}. The next line shows the precise window.",
                "You wanted this scheduled—I tucked {$primaryLabel} {$windowPhrase}. The row underneath has start and end.",
                "{$windowLead} I carved out time for {$primaryLabel}; details are right below so nothing feels vague.",
                "I matched your request with a single block for {$primaryLabel} {$windowPhrase}; scroll one line down for times.",
                "This is the {$windowPhrase} hold I created for {$primaryLabel}—the app row below locks in the timing.",
            ];

            return $templates[$n % count($templates)];
        }

        $count = max(2, $blockCount);
        $second = isset($labels[1]) ? self::truncateLabel($labels[1]) : 'the next item';
        $multiTemplates = [
            "I shaped {$count} blocks across {$windowPhrase}—each row below is one stretch you can nudge if you want.",
            "Here is how {$count} pieces land {$windowPhrase}: one line per block under this note.",
            "{$windowLead} you have {$count} stacked blocks; the list below walks through each time window.",
            "I fit {$count} tasks into {$windowPhrase}; every row underneath is a block we can refine in chat.",
            "Across {$windowPhrase} I sequenced {$count} blocks starting with {$primaryLabel} and {$second}—times sit in the rows below.",
            "Your {$windowPhrase} plan spans {$count} blocks; scan the lines below for start and end for each.",
            "{$windowLead} here is a {$count}-block run—each entry below is ready to tweak before you save.",
            "I queued {$count} blocks for {$windowPhrase}; the section below lists them in time order.",
        ];

        return $multiTemplates[$n % count($multiTemplates)];
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
        $flags = data_get($promptData, 'user_context.schedule_intent_flags', []);
        if (! is_array($flags)) {
            $flags = [];
        }

        if (($flags['has_evening'] ?? false) === true) {
            return 'this evening';
        }
        if (($flags['has_afternoon'] ?? false) === true) {
            return 'this afternoon';
        }
        if (($flags['has_morning'] ?? false) === true) {
            return 'this morning';
        }
        if (($flags['has_later'] ?? false) === true || ($flags['has_onwards'] ?? false) === true) {
            return 'later today';
        }

        return 'in your open window today';
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

<?php

namespace App\Support\LLM;

use Carbon\CarbonImmutable;

/**
 * Keeps schedule narrative prompts and student-visible copy free of internal scheduling jargon.
 */
final class TaskAssistantScheduleNarrativeSanitizer
{
    /**
     * Map internal horizon labels (see {@see \App\Services\LLM\Scheduling\TaskAssistantScheduleHorizonResolver}) to plain English.
     */
    public static function humanizeHorizonLabel(string $label): string
    {
        $key = mb_strtolower(trim($label));

        return match ($key) {
            'default_today' => 'today',
            'smart_default_spread' => 'the next few days',
            'today' => 'today',
            'tomorrow' => 'tomorrow',
            'this weekend' => 'this weekend',
            'next weekend' => 'next weekend',
            'this week' => 'this week',
            'next week' => 'next week',
            default => $label !== '' ? $label : 'today',
        };
    }

    /**
     * One short sentence for the LLM (never raw codenames like default_today).
     *
     * @param  array<string, mixed>|null  $horizon
     */
    public static function horizonContextLineForPrompt(?array $horizon): string
    {
        if (! is_array($horizon) || ! isset($horizon['start_date'], $horizon['end_date'])) {
            return '';
        }

        $labelRaw = trim((string) ($horizon['label'] ?? ''));
        $friendly = self::humanizeHorizonLabel($labelRaw);
        $start = (string) $horizon['start_date'];
        $end = (string) $horizon['end_date'];

        if ($start === $end) {
            return ' Context: these blocks are planned for '.$friendly.' ('.$start.').';
        }

        return ' Context: these blocks span '.$friendly.' ('.$start.' through '.$end.').';
    }

    /**
     * Remove or soften internal jargon accidentally echoed by the model.
     */
    public static function sanitizeStudentFacingCopy(string $text): string
    {
        $t = trim($text);
        if ($t === '') {
            return $t;
        }

        $patterns = [
            '/\bdefault\s+placement\s+window\b/iu' => 'available time',
            '/\bplacement\s+window\b/iu' => 'available time',
            '/\bplanning\s+horizon\b/iu' => 'date range',
            '/\bplacement\s+digest\b/iu' => 'planning details',
            '/\bdefault_today\b/u' => 'today',
            '/\bsmart_default_spread\b/u' => 'the next few days',
            '/\bdefault_placement\b/iu' => 'schedule',
            '/\bPLACEMENT_DIGEST_JSON\b/u' => '',
            '/\bBLOCKS_JSON\b/u' => 'the planned blocks',
            '/\bserver-?side\b/iu' => '',
            '/\bbackend\b/iu' => '',
            '/\byour\s+snapshot\b/iu' => 'your tasks',
            '/\bsnapshot\s+data\b/iu' => 'task data',
            '/\bsnapshot\b/iu' => 'task list',
            '/\brequested\s+window\b/iu' => 'the time you asked for',
            '/\brequest\s+was\s+made\s+explicitly\s+by\s+the\s+user\b/iu' => 'you asked for this plan directly',
            '/\bhorizon\s+dates?\b/iu' => 'date range',
            '/\bopen\s+time\s+slots?\b/iu' => 'open time blocks',
            '/\b0\s+of\s+\d+\s+open\s+time\s+blocks?\s+were\s+available\b/iu' => 'none of the available time blocks fit',
            '/\bconfidence\s*:\s*/iu' => '',
            '/\bright\s+after\s+(lunch|dinner)\b/iu' => 'in this time window',
            '/\bafter\s+(lunch|dinner)\b/iu' => 'in this time window',
            '/\bmain\s+chunk\b/iu' => 'main block',
            '/\bfocused\s+chunk\b/iu' => 'focused block',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $t = (string) preg_replace($pattern, $replacement, $t);
        }

        $t = trim((string) preg_replace('/\s{2,}/u', ' ', $t) ?? $t);
        $t = trim((string) preg_replace('/\s+,/u', ',', $t) ?? $t);

        return $t;
    }

    /**
     * When blocks land on a calendar day other than the student's "today", rewrite misleading
     * "later today" / "for later today" phrasing so it matches the first placement date.
     *
     * @param  non-empty-string  $studentTodayYmd
     * @param  non-empty-string  $firstPlacementDayYmd
     * @param  non-empty-string  $timezone
     */
    public static function alignLaterTodayPhrasingWithPlacementDay(
        string $text,
        string $studentTodayYmd,
        string $firstPlacementDayYmd,
        string $timezone,
    ): string {
        $t = trim($text);
        if ($t === '' || $studentTodayYmd === $firstPlacementDayYmd) {
            return $t;
        }

        try {
            $placement = CarbonImmutable::parse($firstPlacementDayYmd, $timezone)->startOfDay();
            $today = CarbonImmutable::parse($studentTodayYmd, $timezone)->startOfDay();
        } catch (\Throwable) {
            return $t;
        }

        $isTomorrow = $placement->isSameDay($today->addDay());
        $forPhrase = $isTomorrow ? 'tomorrow' : $placement->format('l, M j');
        $standalonePhrase = $isTomorrow ? 'tomorrow' : 'on '.$placement->format('l, M j');

        $out = (string) preg_replace('/\bfor\s+later\s+today\b/iu', 'for '.$forPhrase, $t);
        $out = (string) preg_replace('/\blater\s+today\b/iu', $standalonePhrase, $out);

        // Small models often say "today" for the student's availability window even when blocks land
        // on the next calendar day. Rewrite schedule-window phrases only (narrow patterns).
        $windowTodayReplacements = [
            '/\bacross in your open window today\b/iu' => 'across in your open window for '.$forPhrase,
            '/\bin your open window today\b/iu' => 'in your open window for '.$forPhrase,
            '/\bin your available window today\b/iu' => 'in your available window for '.$forPhrase,
            '/\bthis window today\b/iu' => 'this window for '.$forPhrase,
            '/\b(your|this)\s+time\s+window\s+today\b/iu' => '$1 time window for '.$forPhrase,
        ];
        foreach ($windowTodayReplacements as $pattern => $replacement) {
            $out = (string) preg_replace($pattern, $replacement, $out);
        }

        return trim((string) preg_replace('/\s{2,}/u', ' ', $out) ?? $out);
    }
}

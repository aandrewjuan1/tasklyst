<?php

namespace App\Support\LLM;

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
            '/\bdefault_placement\b/iu' => 'schedule',
            '/\bPLACEMENT_DIGEST_JSON\b/u' => '',
            '/\bBLOCKS_JSON\b/u' => 'the planned blocks',
            '/\bserver-?side\b/iu' => '',
            '/\bbackend\b/iu' => '',
            '/\byour\s+snapshot\b/iu' => 'your tasks',
            '/\bsnapshot\s+data\b/iu' => 'task data',
            '/\bsnapshot\b/iu' => 'task list',
            '/\brequested\s+window\b/iu' => 'the time you asked for',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $t = (string) preg_replace($pattern, $replacement, $t);
        }

        $t = trim((string) preg_replace('/\s{2,}/u', ' ', $t) ?? $t);
        $t = trim((string) preg_replace('/\s+,/u', ',', $t) ?? $t);

        return $t;
    }
}

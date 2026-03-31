<?php

namespace App\Services\LLM\Scheduling;

use Carbon\CarbonImmutable;

/**
 * Deterministic interpreter for schedule-specific time semantics.
 *
 * This does not decide *what* to schedule; it only resolves:
 * - day time windows (morning/evening/later)
 * - default hours constraints (08:00–22:00) and lunch break (12:00–13:00) guidance
 *
 * Horizon (today/tomorrow/this week/weekday) remains on {@see TaskAssistantScheduleHorizonResolver}.
 */
final class SchedulingIntentInterpreter
{
    /**
     * @return array{
     *   time_window: array{start: string, end: string},
     *   intent_flags: array{has_later: bool, has_morning: bool, has_afternoon: bool, has_evening: bool, has_onwards: bool, has_only: bool},
     *   strict_window: bool,
     *   reason_codes: list<string>
     * }
     */
    public function interpret(string $userMessage, string $timezone, CarbonImmutable $now): array
    {
        $lower = mb_strtolower($userMessage);
        $tz = $timezone !== '' ? $timezone : (string) config('app.timezone', 'UTC');
        $localNow = $now->setTimezone($tz);

        $hasLater = preg_match('/\blater\b/u', $lower) === 1;
        $hasMorning = preg_match('/\bmorning\b/u', $lower) === 1;
        $hasAfternoon = preg_match('/\bafternoon\b/u', $lower) === 1;
        $hasEvening = preg_match('/\b(evening|night|tonight)\b/u', $lower) === 1;
        $hasOnwards = preg_match('/\bonward(s)?\b/u', $lower) === 1;
        $hasOnly = preg_match('/\bonly\b/u', $lower) === 1;

        $reasonCodes = [];

        // Default day bounds (product decision): 08:00–22:00.
        $defaultStart = '08:00';
        $defaultEnd = '22:00';

        $explicitWindow = $this->resolveExplicitNaturalWindow($lower, $defaultStart, $defaultEnd);
        if ($explicitWindow !== null) {
            return [
                'time_window' => [
                    'start' => $explicitWindow['start'],
                    'end' => $explicitWindow['end'],
                ],
                'intent_flags' => [
                    'has_later' => $hasLater,
                    'has_morning' => $hasMorning,
                    'has_afternoon' => $hasAfternoon,
                    'has_evening' => $hasEvening,
                    'has_onwards' => $hasOnwards,
                    'has_only' => $hasOnly,
                ],
                'strict_window' => $hasOnly,
                'reason_codes' => $explicitWindow['reason_codes'],
            ];
        }

        $combinedNamedWindow = $this->resolveCombinedNamedWindow($lower, $defaultStart, $defaultEnd);
        if ($combinedNamedWindow !== null) {
            return [
                'time_window' => [
                    'start' => $combinedNamedWindow['start'],
                    'end' => $combinedNamedWindow['end'],
                ],
                'intent_flags' => [
                    'has_later' => $hasLater,
                    'has_morning' => $hasMorning,
                    'has_afternoon' => $hasAfternoon,
                    'has_evening' => $hasEvening,
                    'has_onwards' => $hasOnwards,
                    'has_only' => $hasOnly,
                ],
                'strict_window' => $hasOnly,
                'reason_codes' => $combinedNamedWindow['reason_codes'],
            ];
        }

        // Named windows override default hours.
        // Precedence: explicit evening > afternoon > morning.
        if ($hasEvening) {
            $reasonCodes[] = 'intent_time_window_evening';

            return [
                'time_window' => ['start' => '18:00', 'end' => '22:00'],
                'intent_flags' => [
                    'has_later' => $hasLater,
                    'has_morning' => $hasMorning,
                    'has_afternoon' => $hasAfternoon,
                    'has_evening' => $hasEvening,
                    'has_onwards' => $hasOnwards,
                    'has_only' => $hasOnly,
                ],
                'strict_window' => $hasOnly,
                'reason_codes' => $reasonCodes,
            ];
        }

        if ($hasAfternoon) {
            $reasonCodes[] = 'intent_time_window_afternoon';

            $start = '15:00';
            $end = '18:00';

            if ($hasOnwards) {
                $end = $defaultEnd;
                $reasonCodes[] = 'intent_time_window_onwards_to_default_end';
            }

            return [
                'time_window' => ['start' => $start, 'end' => $end],
                'intent_flags' => [
                    'has_later' => $hasLater,
                    'has_morning' => $hasMorning,
                    'has_afternoon' => $hasAfternoon,
                    'has_evening' => $hasEvening,
                    'has_onwards' => $hasOnwards,
                    'has_only' => $hasOnly,
                ],
                'strict_window' => $hasOnly,
                'reason_codes' => $reasonCodes,
            ];
        }

        if ($hasMorning) {
            $reasonCodes[] = 'intent_time_window_morning';
            $end = $hasOnwards ? $defaultEnd : '12:00';
            if ($hasOnwards) {
                $reasonCodes[] = 'intent_time_window_onwards_to_default_end';
            }

            return [
                'time_window' => ['start' => '08:00', 'end' => $end],
                'intent_flags' => [
                    'has_later' => $hasLater,
                    'has_morning' => $hasMorning,
                    'has_afternoon' => $hasAfternoon,
                    'has_evening' => $hasEvening,
                    'has_onwards' => $hasOnwards,
                    'has_only' => $hasOnly,
                ],
                'strict_window' => $hasOnly,
                'reason_codes' => $reasonCodes,
            ];
        }

        // "Later" without a named window means: today, after now (rounded up),
        // within default day bounds.
        if ($hasLater) {
            $reasonCodes[] = 'intent_time_window_later_after_now';

            $start = $this->resolveLaterStartTime($localNow, $defaultStart, $defaultEnd);

            return [
                'time_window' => ['start' => $start, 'end' => $defaultEnd],
                'intent_flags' => [
                    'has_later' => $hasLater,
                    'has_morning' => $hasMorning,
                    'has_afternoon' => $hasAfternoon,
                    'has_evening' => $hasEvening,
                    'has_onwards' => $hasOnwards,
                    'has_only' => $hasOnly,
                ],
                'strict_window' => $hasOnly,
                'reason_codes' => $reasonCodes,
            ];
        }

        // Otherwise: default day bounds.
        $reasonCodes[] = 'intent_time_window_default_daytime';

        return [
            'time_window' => ['start' => $defaultStart, 'end' => $defaultEnd],
            'intent_flags' => [
                'has_later' => $hasLater,
                'has_morning' => $hasMorning,
                'has_afternoon' => $hasAfternoon,
                'has_evening' => $hasEvening,
                'has_onwards' => $hasOnwards,
                'has_only' => $hasOnly,
            ],
            'strict_window' => $hasOnly,
            'reason_codes' => $reasonCodes,
        ];
    }

    private function resolveLaterStartTime(CarbonImmutable $localNow, string $defaultStart, string $defaultEnd): string
    {
        $hour = (int) $localNow->format('H');
        $minute = (int) $localNow->format('i');

        // Round up to next 15-minute boundary for a cleaner UX.
        $rounded = $localNow->setTime($hour, $minute, 0);
        $mod = $minute % 15;
        if ($mod !== 0) {
            $rounded = $rounded->addMinutes(15 - $mod);
        }

        $start = $rounded->format('H:i');

        // Clamp to default day bounds.
        if ($start < $defaultStart) {
            return $defaultStart;
        }
        if ($start > $defaultEnd) {
            return $defaultEnd;
        }

        // Avoid starting inside lunch break (12:00–13:00). If we land in it, bump to 13:00.
        if ($start >= '12:00' && $start < '13:00') {
            return '13:00';
        }

        return $start;
    }

    /**
     * @return array{start: string, end: string, reason_codes: list<string>}|null
     */
    private function resolveExplicitNaturalWindow(string $lower, string $defaultStart, string $defaultEnd): ?array
    {
        $reasonCodes = [];
        $start = null;
        $end = $defaultEnd;

        $anchor = $this->resolveAnchorPhraseStart($lower);
        if ($anchor !== null) {
            $start = $anchor['start'];
            $reasonCodes[] = $anchor['reason_code'];
        } elseif (preg_match('/\b(\d{1,2})(?::(\d{2}))?\s*(am|pm)\s*onward(s)?\b/u', $lower, $matches) === 1) {
            $start = $this->normalizeMeridiemTime($matches[1] ?? '', $matches[2] ?? null, $matches[3] ?? '');
            $reasonCodes[] = 'intent_time_window_explicit_onwards_time';
        } elseif (preg_match('/\b(?:after|from|starting(?:\s+at)?|later)\b[^\d]{0,24}(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/u', $lower, $matches) === 1) {
            $start = $this->normalizeMeridiemTime($matches[1] ?? '', $matches[2] ?? null, $matches[3] ?? '');
            $reasonCodes[] = 'intent_time_window_explicit_after_time';
        }

        if (! is_string($start) || $start === '') {
            return null;
        }

        if (preg_match('/\b(?:to|until|till)\s+(morning|afternoon|evening|night)\b/u', $lower, $namedEnd) === 1) {
            $named = (string) ($namedEnd[1] ?? '');
            $mapped = match ($named) {
                'morning' => '12:00',
                'afternoon' => '18:00',
                'evening', 'night' => '22:00',
                default => $defaultEnd,
            };
            $end = $mapped;
            $reasonCodes[] = 'intent_time_window_named_end_bound';
        } elseif (preg_match('/\b(?:to|until|till)\s+(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/u', $lower, $timeEnd) === 1) {
            $parsedEnd = $this->normalizeMeridiemTime($timeEnd[1] ?? '', $timeEnd[2] ?? null, $timeEnd[3] ?? '');
            if ($parsedEnd !== '') {
                $end = $parsedEnd;
                $reasonCodes[] = 'intent_time_window_explicit_end_bound';
            }
        }

        $start = max($start, $defaultStart);
        $end = min($end, $defaultEnd);
        if ($end <= $start) {
            $end = $defaultEnd;
            $reasonCodes[] = 'intent_time_window_end_bound_clamped_to_default';
        }

        return [
            'start' => $start,
            'end' => $end,
            'reason_codes' => $reasonCodes,
        ];
    }

    /**
     * @return array{start: string, reason_code: string}|null
     */
    private function resolveAnchorPhraseStart(string $lower): ?array
    {
        $anchorPatterns = [
            'lunch' => ['start' => '13:00', 'reason_code' => 'intent_time_window_after_anchor_lunch'],
            'dinner' => ['start' => '19:00', 'reason_code' => 'intent_time_window_after_anchor_dinner'],
            'breakfast' => ['start' => '09:00', 'reason_code' => 'intent_time_window_after_anchor_breakfast'],
            'class' => ['start' => '15:00', 'reason_code' => 'intent_time_window_after_anchor_class'],
            'work' => ['start' => '17:00', 'reason_code' => 'intent_time_window_after_anchor_work'],
            'school' => ['start' => '15:00', 'reason_code' => 'intent_time_window_after_anchor_school'],
            'office' => ['start' => '17:00', 'reason_code' => 'intent_time_window_after_anchor_office'],
            'home' => ['start' => '17:00', 'reason_code' => 'intent_time_window_after_anchor_home'],
            'gym' => ['start' => '20:00', 'reason_code' => 'intent_time_window_after_anchor_gym'],
        ];

        foreach ($anchorPatterns as $anchor => $cfg) {
            if (preg_match('/\bafter\s+(?:my\s+)?'.$anchor.'\b/u', $lower) === 1) {
                return [
                    'start' => $cfg['start'],
                    'reason_code' => $cfg['reason_code'],
                ];
            }
        }

        if (preg_match('/\bafter\s+i\s+(?:got|get)\s+home\b/u', $lower) === 1) {
            return [
                'start' => '17:00',
                'reason_code' => 'intent_time_window_after_anchor_get_home',
            ];
        }

        return null;
    }

    /**
     * @return array{start: string, end: string, reason_codes: list<string>}|null
     */
    private function resolveCombinedNamedWindow(string $lower, string $defaultStart, string $defaultEnd): ?array
    {
        $tokens = [
            'morning' => ['start' => '08:00', 'end' => '12:00'],
            'afternoon' => ['start' => '15:00', 'end' => '18:00'],
            'evening' => ['start' => '18:00', 'end' => '22:00'],
            'night' => ['start' => '18:00', 'end' => '22:00'],
        ];

        $matched = [];
        foreach ($tokens as $token => $bounds) {
            if (preg_match('/\b'.$token.'\b/u', $lower) === 1) {
                $matched[$token] = $bounds;
            }
        }

        if (count($matched) < 2) {
            return null;
        }

        $starts = array_map(static fn (array $b): string => $b['start'], array_values($matched));
        $ends = array_map(static fn (array $b): string => $b['end'], array_values($matched));
        $start = min($starts);
        $end = max($ends);

        $start = max($start, $defaultStart);
        $end = min($end, $defaultEnd);
        if ($end <= $start) {
            return null;
        }

        $matchedTokens = array_keys($matched);
        sort($matchedTokens);

        return [
            'start' => $start,
            'end' => $end,
            'reason_codes' => [
                'intent_time_window_combined_named',
                'intent_time_window_combined_'.implode('_', $matchedTokens),
            ],
        ];
    }

    private function normalizeMeridiemTime(string $hourRaw, ?string $minuteRaw, string $meridiemRaw): string
    {
        $hour = (int) trim($hourRaw);
        $minute = is_string($minuteRaw) && $minuteRaw !== '' ? (int) $minuteRaw : 0;
        $meridiem = mb_strtolower(trim($meridiemRaw));

        if ($hour < 1 || $hour > 12 || $minute < 0 || $minute > 59) {
            return '';
        }

        if ($meridiem === 'am') {
            $hour24 = $hour === 12 ? 0 : $hour;
        } else {
            $hour24 = $hour === 12 ? 12 : $hour + 12;
        }

        return sprintf('%02d:%02d', $hour24, $minute);
    }
}

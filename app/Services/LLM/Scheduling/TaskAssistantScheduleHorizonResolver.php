<?php

namespace App\Services\LLM\Scheduling;

use Carbon\CarbonImmutable;

/**
 * Resolves which calendar day(s) to search for free time. Task filtering (e.g. due this week)
 * stays in {@see \App\Services\LLM\Prioritization\TaskAssistantTaskChoiceConstraintsExtractor}; this
 * class only defines the placement horizon.
 *
 * Precedence (first match wins): tomorrow, today, next weekend, this weekend, next week, this week,
 * named weekday, then default snapshot day.
 */
final class TaskAssistantScheduleHorizonResolver
{
    /**
     * @return array{
     *   mode: 'single_day'|'range',
     *   start_date: string,
     *   end_date: string,
     *   label: string
     * }
     */
    public function resolve(string $userMessage, string $timezone, CarbonImmutable $now): array
    {
        $maxDays = max(1, (int) config('task-assistant.schedule.max_horizon_days', 14));
        $lower = mb_strtolower($userMessage);
        $tz = $timezone !== '' ? $timezone : (string) config('app.timezone', 'UTC');
        $local = $now->setTimezone($tz);

        $explicitDate = $this->resolveExplicitCalendarDate($userMessage, $tz, $local);
        if ($explicitDate !== null) {
            return $this->singleDay($explicitDate['date'], $explicitDate['label']);
        }

        if (preg_match('/\btomorrow\b/u', $lower) === 1) {
            return $this->singleDay($local->addDay()->startOfDay(), 'tomorrow');
        }

        if (preg_match('/\btoday\b/u', $lower) === 1) {
            return $this->singleDay($local->copy()->startOfDay(), 'today');
        }

        if (preg_match('/\bnext\s+weekend\b/u', $lower) === 1) {
            [$sat, $sun] = $this->upcomingWeekendBounds($local);

            return $this->cappedRange($sat->addWeek(), $sun->addWeek(), 'next weekend', $maxDays);
        }

        if (preg_match('/\bthis\s+weekend\b/u', $lower) === 1) {
            [$sat, $sun] = $this->upcomingWeekendBounds($local);

            return $this->cappedRange($sat, $sun, 'this weekend', $maxDays);
        }

        if (preg_match('/\bnext\s+week\b/u', $lower) === 1) {
            return $this->nextWeekRange($local, $maxDays);
        }

        if (preg_match('/\bthis\s+week\b/u', $lower) === 1) {
            return $this->thisWeekRange($local, $maxDays);
        }

        $qualifiedWeekday = $this->resolveQualifiedWeekday($lower, $local);
        if ($qualifiedWeekday !== null) {
            return $this->singleDay($qualifiedWeekday['date'], $qualifiedWeekday['label']);
        }

        $weekday = $this->matchNamedWeekday($lower);
        if ($weekday !== null) {
            $target = $this->nextOccurrenceOfWeekdayIso($local, $weekday['iso']);

            return $this->singleDay($target, $weekday['label']);
        }

        return $this->singleDay($local->copy()->startOfDay(), 'default_today');
    }

    /**
     * @return array{date: CarbonImmutable, label: string}|null
     */
    private function resolveExplicitCalendarDate(string $userMessage, string $timezone, CarbonImmutable $localNow): ?array
    {
        if (preg_match('/\b(?:in\s+)?(\d{1,2})\s+days?(?:\s+from\s+now)?\b/iu', $userMessage, $matches) === 1) {
            $days = (int) ($matches[1] ?? 0);
            if ($days > 0) {
                return [
                    'date' => $localNow->addDays($days)->startOfDay(),
                    'label' => 'relative_days_offset',
                ];
            }
        }

        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/u', $userMessage, $matches) === 1) {
            $iso = sprintf('%04d-%02d-%02d', (int) ($matches[1] ?? 0), (int) ($matches[2] ?? 0), (int) ($matches[3] ?? 0));
            try {
                return [
                    'date' => CarbonImmutable::parse($iso, $timezone)->startOfDay(),
                    'label' => 'explicit_date_iso',
                ];
            } catch (\Throwable) {
                return null;
            }
        }

        if (preg_match('/\b(0?[1-9]|1[0-2])\/(0?[1-9]|[12]\d|3[01])(?:\/(\d{4}))?\b/u', $userMessage, $matches) === 1) {
            $month = (int) ($matches[1] ?? 0);
            $day = (int) ($matches[2] ?? 0);
            $year = isset($matches[3]) && $matches[3] !== ''
                ? (int) $matches[3]
                : (int) $localNow->format('Y');
            try {
                $candidate = CarbonImmutable::create($year, $month, $day, 0, 0, 0, $timezone)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
            if (! isset($matches[3]) && $candidate->lt($localNow->startOfDay())) {
                $candidate = $candidate->addYear();
            }

            return [
                'date' => $candidate,
                'label' => 'explicit_date_numeric',
            ];
        }

        if (preg_match('/\b(january|jan|february|feb|march|mar|april|apr|may|june|jun|july|jul|august|aug|september|sep|sept|october|oct|november|nov|december|dec)\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s+(\d{4}))?\b/iu', $userMessage, $matches) === 1) {
            $monthName = (string) ($matches[1] ?? '');
            $day = (int) ($matches[2] ?? 0);
            $year = isset($matches[3]) && $matches[3] !== ''
                ? (int) $matches[3]
                : (int) $localNow->format('Y');

            try {
                $candidate = CarbonImmutable::parse($monthName.' '.$day.' '.$year, $timezone)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
            if (! isset($matches[3]) && $candidate->lt($localNow->startOfDay())) {
                $candidate = $candidate->addYear();
            }

            return [
                'date' => $candidate,
                'label' => 'explicit_date_month_day',
            ];
        }

        return null;
    }

    /**
     * @return array{date: CarbonImmutable, label: string}|null
     */
    private function resolveQualifiedWeekday(string $lower, CarbonImmutable $from): ?array
    {
        if (preg_match('/\b(next|this)\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/u', $lower, $matches) !== 1) {
            return null;
        }

        $qualifier = (string) ($matches[1] ?? '');
        $weekday = (string) ($matches[2] ?? '');
        $meta = $this->matchNamedWeekday($weekday);
        if ($meta === null) {
            return null;
        }

        $currentIso = (int) $from->isoWeekday();
        $targetIso = (int) ($meta['iso'] ?? $currentIso);
        $daysAhead = ($targetIso - $currentIso + 7) % 7;

        if ($qualifier === 'next') {
            $daysAhead = $daysAhead === 0 ? 7 : $daysAhead;
        } elseif ($qualifier === 'this' && $daysAhead === 0) {
            $daysAhead = 0;
        }

        return [
            'date' => $from->addDays($daysAhead)->startOfDay(),
            'label' => 'qualified_weekday_'.$qualifier,
        ];
    }

    /**
     * @return array{mode: 'single_day', start_date: string, end_date: string, label: string}
     */
    private function singleDay(CarbonImmutable $day, string $label): array
    {
        $start = $day->toDateString();

        return [
            'mode' => 'single_day',
            'start_date' => $start,
            'end_date' => $start,
            'label' => $label,
        ];
    }

    /**
     * @return array{mode: 'range', start_date: string, end_date: string, label: string}
     */
    private function cappedRange(CarbonImmutable $start, CarbonImmutable $end, string $label, int $maxDays): array
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->startOfDay();
        if ($end->lt($start)) {
            $end = $start->copy();
        }

        $span = (int) $start->diffInDays($end, false) + 1;
        if ($span > $maxDays) {
            $end = $start->copy()->addDays($maxDays - 1);
        }

        return [
            'mode' => 'range',
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'label' => $label,
        ];
    }

    /**
     * @return array{mode: 'range', start_date: string, end_date: string, label: string}
     */
    private function thisWeekRange(CarbonImmutable $now, int $maxDays): array
    {
        $weekStart = $now->copy()->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $today = $now->copy()->startOfDay();
        $start = $today->gt($weekStart) ? $today : $weekStart;
        $end = $start->copy()->addDays(6)->startOfDay();

        return $this->cappedRange($start, $end, 'this week', $maxDays);
    }

    /**
     * @return array{mode: 'range', start_date: string, end_date: string, label: string}
     */
    private function nextWeekRange(CarbonImmutable $now, int $maxDays): array
    {
        $thisMonday = $now->copy()->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $start = $thisMonday->addWeek();
        $end = $start->copy()->addDays(6)->startOfDay();

        return $this->cappedRange($start, $end, 'next week', $maxDays);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function upcomingWeekendBounds(CarbonImmutable $now): array
    {
        $weekStart = $now->copy()->startOfWeek(CarbonImmutable::MONDAY);
        $sat = $weekStart->copy()->addDays(5)->startOfDay();
        $sun = $weekStart->copy()->addDays(6)->startOfDay();

        if ($now->gt($sun->copy()->endOfDay())) {
            $sat = $sat->copy()->addWeek();
            $sun = $sun->copy()->addWeek();
        }

        return [$sat, $sun];
    }

    /**
     * @return array{label: string, iso: int}|null
     */
    private function matchNamedWeekday(string $lower): ?array
    {
        $map = [
            'monday' => ['label' => 'Monday', 'iso' => 1],
            'tuesday' => ['label' => 'Tuesday', 'iso' => 2],
            'wednesday' => ['label' => 'Wednesday', 'iso' => 3],
            'thursday' => ['label' => 'Thursday', 'iso' => 4],
            'friday' => ['label' => 'Friday', 'iso' => 5],
            'saturday' => ['label' => 'Saturday', 'iso' => 6],
            'sunday' => ['label' => 'Sunday', 'iso' => 7],
        ];

        foreach ($map as $word => $meta) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/u', $lower) === 1) {
                return ['label' => $meta['label'], 'iso' => $meta['iso']];
            }
        }

        return null;
    }

    private function nextOccurrenceOfWeekdayIso(CarbonImmutable $from, int $isoWeekday): CarbonImmutable
    {
        $c = $from->copy()->startOfDay();
        for ($i = 0; $i < 14; $i++) {
            if ((int) $c->isoWeekday() === $isoWeekday) {
                return $c;
            }
            $c = $c->addDay();
        }

        return $from->copy()->startOfDay();
    }
}

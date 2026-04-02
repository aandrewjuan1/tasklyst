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

        $weekday = $this->matchNamedWeekday($lower);
        if ($weekday !== null) {
            $target = $this->nextOccurrenceOfWeekdayIso($local, $weekday['iso']);

            return $this->singleDay($target, $weekday['label']);
        }

        return $this->singleDay($local->copy()->startOfDay(), 'default_today');
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

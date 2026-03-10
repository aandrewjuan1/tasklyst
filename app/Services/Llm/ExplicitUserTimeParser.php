<?php

namespace App\Services\Llm;

use Carbon\CarbonImmutable;

class ExplicitUserTimeParser
{
    /**
     * Parse an explicit user-requested start datetime (e.g. "Friday at 9am", "tomorrow 2pm")
     * anchored to the application's timezone and the Context current_time.
     *
     * Returns null when no explicit, parseable slot is found.
     *
     * @param  array<string, mixed>  $context
     */
    public function parseStartDatetime(?string $userMessage, array $context): ?CarbonImmutable
    {
        $message = is_string($userMessage) ? trim($userMessage) : '';
        if ($message === '') {
            return null;
        }

        $timezone = is_string($context['timezone'] ?? null) && trim((string) $context['timezone']) !== ''
            ? (string) $context['timezone']
            : config('app.timezone', 'Asia/Manila');

        $now = $this->nowFromContext($context, $timezone);

        $time = $this->parseClockTime($message);
        if ($time === null) {
            return null;
        }
        [$hour, $minute] = $time;

        $relative = $this->parseRelativeDay($message);
        if ($relative !== null) {
            $candidate = match ($relative) {
                'today' => $now->setTime($hour, $minute, 0),
                'tomorrow' => $now->addDay()->setTime($hour, $minute, 0),
                default => null,
            };

            if ($candidate === null || $candidate->lte($now)) {
                return null;
            }

            return $candidate;
        }

        $weekday = $this->parseWeekday($message);
        if ($weekday !== null) {
            $targetDow = $weekday; // ISO 1 (Mon) .. 7 (Sun)
            $nowDow = (int) $now->dayOfWeekIso;

            $diff = ($targetDow - $nowDow + 7) % 7;
            $candidate = $now->addDays($diff)->setTime($hour, $minute, 0);

            if ($diff === 0 && $candidate->lte($now)) {
                $candidate = $candidate->addDays(7);
            }

            if ($candidate->lte($now)) {
                return null;
            }

            return $candidate;
        }

        if ($this->looksLikeHasExplicitDate($message)) {
            try {
                $parsed = CarbonImmutable::parse($message, $timezone)->setTimezone($timezone)->setSecond(0)->setMicrosecond(0);
                if ($parsed->gt($now)) {
                    return $parsed;
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function nowFromContext(array $context, string $timezone): CarbonImmutable
    {
        $currentTime = $context['current_time'] ?? null;
        if (is_string($currentTime) && trim($currentTime) !== '') {
            try {
                return CarbonImmutable::parse($currentTime, $timezone)->setTimezone($timezone);
            } catch (\Throwable) {
                // fall through
            }
        }

        return CarbonImmutable::now($timezone);
    }

    /** @return array{0:int,1:int}|null */
    private function parseClockTime(string $message): ?array
    {
        $m = mb_strtolower($message);

        if (preg_match('/\b(2[0-3]|[01]?\d)\s*:\s*(\d{2})\b/u', $m, $matches)) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];

            return [$hour, $minute];
        }

        if (preg_match('/\b(\d{1,2})\s*:\s*(\d{2})\s*([ap])\.?\s*m\.?\b/u', $m, $matches)) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];
            $ampm = $matches[3];

            $hour = $this->hourFromAmPm($hour, $ampm);

            return [$hour, $minute];
        }

        if (preg_match('/\b(\d{1,2})\s*([ap])\.?\s*m\.?\b/u', $m, $matches)) {
            $hour = (int) $matches[1];
            $ampm = $matches[2];
            $hour = $this->hourFromAmPm($hour, $ampm);

            return [$hour, 0];
        }

        return null;
    }

    private function hourFromAmPm(int $hour, string $ampm): int
    {
        $hour = max(0, min(12, $hour));

        if ($ampm === 'p') {
            return $hour === 12 ? 12 : $hour + 12;
        }

        return $hour === 12 ? 0 : $hour;
    }

    /** @return 'today'|'tomorrow'|null */
    private function parseRelativeDay(string $message): ?string
    {
        $m = mb_strtolower($message);

        if (preg_match('/\b(today|tonight|this evening|this afternoon|this morning)\b/u', $m)) {
            return 'today';
        }

        if (preg_match('/\b(tomorrow)\b/u', $m)) {
            return 'tomorrow';
        }

        return null;
    }

    /**
     * @return int|null ISO day of week (1=Mon .. 7=Sun)
     */
    private function parseWeekday(string $message): ?int
    {
        $m = mb_strtolower($message);

        return match (true) {
            (bool) preg_match('/\b(mon(day)?)\b/u', $m) => 1,
            (bool) preg_match('/\b(tue(s(day)?)?)\b/u', $m) => 2,
            (bool) preg_match('/\b(wed(nesday)?)\b/u', $m) => 3,
            (bool) preg_match('/\b(thu(r(s(day)?)?)?)\b/u', $m) => 4,
            (bool) preg_match('/\b(fri(day)?)\b/u', $m) => 5,
            (bool) preg_match('/\b(sat(urday)?)\b/u', $m) => 6,
            (bool) preg_match('/\b(sun(day)?)\b/u', $m) => 7,
            default => null,
        };
    }

    private function looksLikeHasExplicitDate(string $message): bool
    {
        return (bool) preg_match(
            '/\b\d{4}-\d{2}-\d{2}\b|\b(?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\b/u',
            mb_strtolower($message)
        );
    }
}

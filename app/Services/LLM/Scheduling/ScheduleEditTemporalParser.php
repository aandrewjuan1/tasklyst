<?php

namespace App\Services\LLM\Scheduling;

use Carbon\CarbonImmutable;

final class ScheduleEditTemporalParser
{
    public function parseLocalTime(string $message, ?int $fallbackHour = null): ?string
    {
        if (preg_match('/\b(?:at|to|for|later)?\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/u', $message, $m) !== 1) {
            if (preg_match('/\b(?:at|to|for)?\s*(\d{1,2}):(\d{2})\b/u', $message, $m24) === 1) {
                $hour = (int) $m24[1];
                $minute = (int) $m24[2];
                if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
                    return sprintf('%02d:%02d', $hour, $minute);
                }
            }

            return null;
        }

        $hour = (int) $m[1];
        $minute = isset($m[2]) ? (int) $m[2] : 0;
        if ($hour < 1 || $hour > 12 || $minute < 0 || $minute > 59) {
            return null;
        }

        $meridiem = strtolower((string) $m[3]);
        if ($meridiem === 'pm' && $hour < 12) {
            $hour += 12;
        }
        if ($meridiem === 'am' && $hour === 12) {
            $hour = 0;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    public function parseLocalDateYmd(string $message, string $timezone): ?string
    {
        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/u', $message, $m) === 1) {
            return (string) $m[1];
        }

        $now = CarbonImmutable::now($timezone !== '' ? $timezone : 'UTC');
        if (preg_match('/\btoday\b/u', $message) === 1) {
            return $now->format('Y-m-d');
        }
        if (preg_match('/\btomorrow\b/u', $message) === 1) {
            return $now->addDay()->format('Y-m-d');
        }
        if (preg_match('/\bnext week\b/u', $message) === 1) {
            return $now->addWeek()->startOfWeek()->format('Y-m-d');
        }

        if (preg_match('/\bnext\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/u', $message, $mDay) === 1) {
            return $now->next((string) $mDay[1])->format('Y-m-d');
        }

        if (preg_match('/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/u', $message, $mDay) === 1) {
            return $now->next((string) $mDay[1])->format('Y-m-d');
        }

        return null;
    }
}

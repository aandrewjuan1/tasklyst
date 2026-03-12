<?php

namespace App\Services\Llm;

use App\Enums\LlmOperationMode;

class ContextOverlayComposer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function apply(string $userMessage, LlmOperationMode $operationMode, array $payload): array
    {
        return match ($operationMode) {
            LlmOperationMode::Schedule => $this->applyScheduleOverlay($userMessage, $payload),
            LlmOperationMode::Prioritize => $this->applyPrioritizeOverlay($userMessage, $payload),
            default => $payload,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyScheduleOverlay(string $userMessage, array $payload): array
    {
        $payload['availability'] = $this->buildAvailabilityOverlay($payload);
        $payload['user_scheduling_request'] = trim($userMessage);
        $payload['context_authority'] = 'Only use entities present in tasks/events/projects arrays. Never invent names or IDs.';

        $window = $this->parseWindow($userMessage, (string) ($payload['current_date'] ?? now()->toDateString()), (string) ($payload['timezone'] ?? config('app.timezone', 'Asia/Manila')));
        if ($window !== null) {
            [$start, $end] = $window;
            $payload['requested_window_start'] = $start;
            $payload['requested_window_end'] = $end;
        }

        $cap = $this->parseFocusedCap($userMessage);
        if ($cap !== null) {
            $payload['focused_work_cap_minutes'] = $cap;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyPrioritizeOverlay(string $userMessage, array $payload): array
    {
        $requestedTopN = $this->extractRequestedTopN($userMessage);
        if ($requestedTopN !== null) {
            $payload['requested_top_n'] = $requestedTopN;
        }

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildAvailabilityOverlay(array $payload): array
    {
        $days = (int) config('tasklyst.context.availability_days', 7);
        $currentDate = is_string($payload['current_date'] ?? null) ? (string) $payload['current_date'] : now()->toDateString();
        $timezone = is_string($payload['timezone'] ?? null) ? (string) $payload['timezone'] : (string) config('app.timezone', 'Asia/Manila');

        try {
            $start = \Carbon\CarbonImmutable::parse($currentDate, $timezone)->startOfDay();
        } catch (\Throwable) {
            $start = \Carbon\CarbonImmutable::now($timezone)->startOfDay();
        }
        $end = $start->addDays($days)->endOfDay();

        $daysMap = [];
        for ($i = 0; $i <= $days; $i++) {
            $date = $start->addDays($i)->toDateString();
            $daysMap[$date] = [
                'date' => $date,
                'busy_windows' => [],
            ];
        }

        $tasks = isset($payload['tasks']) && is_array($payload['tasks']) ? $payload['tasks'] : [];
        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            $startRaw = isset($task['start_datetime']) && is_string($task['start_datetime']) ? trim($task['start_datetime']) : '';
            $endRaw = isset($task['end_datetime']) && is_string($task['end_datetime']) ? trim($task['end_datetime']) : '';
            if ($startRaw === '' || $endRaw === '') {
                continue;
            }
            try {
                $s = \Carbon\CarbonImmutable::parse($startRaw, $timezone)->setTimezone($timezone);
                $e = \Carbon\CarbonImmutable::parse($endRaw, $timezone)->setTimezone($timezone);
            } catch (\Throwable) {
                continue;
            }
            if ($e->lt($start) || $s->gt($end)) {
                continue;
            }
            $date = $s->toDateString();
            if (! isset($daysMap[$date])) {
                continue;
            }
            $daysMap[$date]['busy_windows'][] = [
                'start' => $s->toIso8601String(),
                'end' => $e->toIso8601String(),
                'label' => isset($task['title']) ? (string) $task['title'] : 'Task',
                'entity_type' => 'task',
            ];
        }

        $events = isset($payload['events']) && is_array($payload['events']) ? $payload['events'] : [];
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            $startRaw = isset($event['start_datetime']) && is_string($event['start_datetime']) ? trim($event['start_datetime']) : '';
            $endRaw = isset($event['end_datetime']) && is_string($event['end_datetime']) ? trim($event['end_datetime']) : '';
            if ($startRaw === '' || $endRaw === '') {
                continue;
            }
            try {
                $s = \Carbon\CarbonImmutable::parse($startRaw, $timezone)->setTimezone($timezone);
                $e = \Carbon\CarbonImmutable::parse($endRaw, $timezone)->setTimezone($timezone);
            } catch (\Throwable) {
                continue;
            }
            if ($e->lt($start) || $s->gt($end)) {
                continue;
            }
            $date = $s->toDateString();
            if (! isset($daysMap[$date])) {
                continue;
            }
            $daysMap[$date]['busy_windows'][] = [
                'start' => $s->toIso8601String(),
                'end' => $e->toIso8601String(),
                'label' => isset($event['title']) ? (string) $event['title'] : 'Event',
                'entity_type' => 'event',
            ];
        }

        foreach ($daysMap as &$day) {
            usort($day['busy_windows'], static fn (array $a, array $b): int => strcmp((string) ($a['start'] ?? ''), (string) ($b['start'] ?? '')));
            $day['busy_windows'] = array_slice($day['busy_windows'], 0, 12);
        }
        unset($day);

        return array_values($daysMap);
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private function parseWindow(string $message, string $currentDate, string $timezone): ?array
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            return null;
        }

        // Support natural language windows like “tomorrow morning to afternoon”
        // for schedule_tasks followups, so the backend can build a deterministic
        // multi-task schedule within a concrete window.
        if (str_contains($normalized, 'tomorrow')) {
            $morning = str_contains($normalized, 'morning');
            $afternoon = str_contains($normalized, 'afternoon');

            if ($morning || $afternoon) {
                try {
                    $base = \Carbon\CarbonImmutable::parse($currentDate, $timezone)->addDay()->startOfDay();
                } catch (\Throwable) {
                    $base = \Carbon\CarbonImmutable::now($timezone)->addDay()->startOfDay();
                }

                if ($morning && $afternoon) {
                    $start = $base->setTime(8, 0);
                    $end = $base->setTime(17, 0);
                } elseif ($morning) {
                    $start = $base->setTime(8, 0);
                    $end = $base->setTime(12, 0);
                } else {
                    $start = $base->setTime(13, 0);
                    $end = $base->setTime(17, 0);
                }

                if ($end->lte($start)) {
                    return null;
                }

                return [$start->toIso8601String(), $end->toIso8601String()];
            }
        }

        $time = '(2[0-3]|[01]?\d)(?::(\d{2}))?\s*([ap])?\.?\s*m?\.?';
        $matches = [];
        $matched = preg_match('/\bfrom\s+'.$time.'\s+to\s+'.$time.'\b/u', $normalized, $matches) === 1
            || preg_match('/\bbetween\s+'.$time.'\s+and\s+'.$time.'\b/u', $normalized, $matches) === 1;

        if (! $matched) {
            return null;
        }

        $fromHour = $this->hourFromOptionalAmPm((int) ($matches[1] ?? 0), $matches[3] ?? null);
        $fromMin = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0;
        $toHour = $this->hourFromOptionalAmPm((int) ($matches[4] ?? 0), $matches[6] ?? null);
        $toMin = isset($matches[5]) && $matches[5] !== '' ? (int) $matches[5] : 0;

        try {
            $start = \Carbon\CarbonImmutable::parse($currentDate.' '.$fromHour.':'.str_pad((string) $fromMin, 2, '0', STR_PAD_LEFT).':00', $timezone);
            $end = \Carbon\CarbonImmutable::parse($currentDate.' '.$toHour.':'.str_pad((string) $toMin, 2, '0', STR_PAD_LEFT).':00', $timezone);
        } catch (\Throwable) {
            return null;
        }

        if ($end->lte($start)) {
            return null;
        }

        return [$start->toIso8601String(), $end->toIso8601String()];
    }

    private function hourFromOptionalAmPm(int $hour, ?string $ampm): int
    {
        $hour = max(0, min(23, $hour));
        $a = is_string($ampm) ? trim(mb_strtolower($ampm)) : '';

        if ($a === 'a' || $a === 'p') {
            $h12 = max(0, min(12, $hour));
            if ($a === 'p') {
                return $h12 === 12 ? 12 : $h12 + 12;
            }

            return $h12 === 12 ? 0 : $h12;
        }

        return $hour;
    }

    private function parseFocusedCap(string $message): ?int
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            return null;
        }

        $matches = [];
        if (preg_match('/\b(?:don[’\'`]?t|do not)\s+schedule\s+more\s+than\s+(\d+(?:\.\d+)?)\s*(hours?|hrs?)\b/u', $normalized, $matches) === 1) {
            $minutes = (int) round(((float) $matches[1]) * 60);

            return $minutes > 0 ? $minutes : null;
        }

        if (preg_match('/\b(?:don[’\'`]?t|do not)\s+schedule\s+more\s+than\s+(\d+)\s*(minutes?|mins?)\b/u', $normalized, $matches) === 1) {
            $minutes = (int) $matches[1];

            return $minutes > 0 ? $minutes : null;
        }

        return null;
    }

    private function extractRequestedTopN(string $message): ?int
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/\btop\s+(\d{1,2})\b/', $normalized, $m)) {
            $n = (int) $m[1];

            return $n > 0 ? min($n, 20) : null;
        }

        return null;
    }
}

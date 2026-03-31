<?php

namespace App\Services\LLM\Scheduling;

use App\Support\LLM\TaskAssistantSchemas;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Structured\PendingRequest as StructuredPendingRequest;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Heuristic-first parsing of multiturn schedule edits; optional LLM structured fallback.
 */
final class ScheduleRefinementIntentResolver
{
    /**
     * @param  array<int, array<string, mixed>>  $proposals
     * @return list<array<string, mixed>>
     */
    public function resolve(string $userMessage, array $proposals, string $userTimezone): array
    {
        $trimmed = trim($userMessage);
        $normalized = mb_strtolower($trimmed);
        $count = count($proposals);
        if ($count < 1 || $trimmed === '') {
            return [];
        }

        $heuristic = $this->heuristicOperations($normalized, $count, $proposals);
        if ($heuristic !== []) {
            return $heuristic;
        }

        if (! (bool) config('task-assistant.schedule_refinement.use_llm', true)) {
            return [];
        }

        if (mb_strlen($trimmed) < 4) {
            return [];
        }

        return $this->interpretWithLlm($trimmed, $proposals, $userTimezone);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function heuristicOperations(string $msg, int $count, array $proposals): array
    {
        $idx = $this->ordinalIndex($msg, $count);

        if (preg_match('/\b(?:move|push|shift)\b[^.]*\b(\d+)\s*(?:min|minutes)\b[^.]*\b(?:later|after|forward)\b/i', $msg, $m)) {
            return [['op' => 'shift_minutes', 'proposal_index' => $idx, 'delta_minutes' => (int) $m[1]]];
        }

        if (preg_match('/\b(\d+)\s*(?:min|minutes)\b\s*(?:later|after)\b/i', $msg, $m)) {
            return [['op' => 'shift_minutes', 'proposal_index' => $idx, 'delta_minutes' => (int) $m[1]]];
        }

        if (preg_match('/\b(?:make|set)\b[^.]*\b(?:the\s+)?(?:first|second|third|last|1st|2nd|3rd)\b[^.]*\b(\d+)\s*(?:min|minutes)\b/i', $msg, $m)
            || (preg_match('/\b(\d+)\s*(?:min|minutes)\b/i', $msg, $m) && preg_match('/\b(first|second|third|last|1st|2nd|3rd)\b/', $msg))) {
            $dur = (int) $m[1];

            return [['op' => 'set_duration_minutes', 'proposal_index' => $idx, 'duration_minutes' => $dur]];
        }

        if (preg_match('/\b(?:on|to)\s+(\d{4}-\d{2}-\d{2})\b/i', $msg, $m)
            || preg_match('/\b(\d{4}-\d{2}-\d{2})\b/i', $msg, $m)) {
            return [['op' => 'set_local_date_ymd', 'proposal_index' => $idx, 'local_date_ymd' => (string) $m[1]]];
        }

        if (preg_match('/\bat\s+(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/i', $msg, $m)) {
            $hhmm = $this->twelveHourToHhmm((int) $m[1], isset($m[2]) ? (int) $m[2] : 0, strtolower((string) $m[3]));

            return [['op' => 'set_local_time_hhmm', 'proposal_index' => $idx, 'local_time_hhmm' => $hhmm]];
        }

        if (preg_match('/\bat\s+(\d{1,2}):(\d{2})\b/i', $msg, $m)) {
            $hInput = (int) $m[1];
            $min = (int) $m[2];
            if ($hInput < 0 || $hInput > 23 || $min < 0 || $min > 59) {
                return [];
            }

            // If AM/PM is omitted (e.g. "at 9:30"), infer 12-hour clock meaning from
            // the existing block’s time-of-day. This avoids interpreting "9:30"
            // as 09:30 AM when the user actually meant the evening.
            if ($hInput <= 12) {
                $priorStart = null;
                if (isset($proposals[$idx]) && is_array($proposals[$idx])) {
                    $startRaw = (string) ($proposals[$idx]['start_datetime'] ?? '');
                    if (trim($startRaw) !== '') {
                        try {
                            $priorStart = new \DateTimeImmutable($startRaw);
                        } catch (\Throwable) {
                            $priorStart = null;
                        }
                    }
                }

                if ($priorStart !== null) {
                    $priorHour24 = (int) $priorStart->format('H');
                    $priorIsPm = $priorHour24 >= 12;

                    if ($hInput === 12) {
                        $hour24 = $priorIsPm ? 12 : 0;
                    } elseif ($hInput === 0) {
                        $hour24 = 0;
                    } else {
                        $hour24 = $priorIsPm ? $hInput + 12 : $hInput;
                    }

                    return [[
                        'op' => 'set_local_time_hhmm',
                        'proposal_index' => $idx,
                        'local_time_hhmm' => sprintf('%02d:%02d', $hour24, $min),
                    ]];
                }
            }

            // Fallback: treat as 24-hour clock if >12, or if we cannot infer.
            return [[
                'op' => 'set_local_time_hhmm',
                'proposal_index' => $idx,
                'local_time_hhmm' => sprintf('%02d:%02d', $hInput, $min),
            ]];
        }

        return [];
    }

    private function ordinalIndex(string $msg, int $count): int
    {
        if ($count < 1) {
            return 0;
        }
        if (preg_match('/\b(last)\b/', $msg)) {
            return max(0, $count - 1);
        }
        if (preg_match('/\b(third|3rd|#3)\b/', $msg)) {
            return min(2, $count - 1);
        }
        if (preg_match('/\b(second|2nd|#2)\b/', $msg)) {
            return min(1, $count - 1);
        }

        return 0;
    }

    private function twelveHourToHhmm(int $hour12, int $minute, string $meridiem): string
    {
        $h = $hour12;
        if ($meridiem === 'pm' && $h < 12) {
            $h += 12;
        }
        if ($meridiem === 'am' && $h === 12) {
            $h = 0;
        }

        return sprintf('%02d:%02d', $h, max(0, min(59, $minute)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     * @return list<array<string, mixed>>
     */
    private function interpretWithLlm(string $userMessage, array $proposals, string $userTimezone): array
    {
        $lines = [];
        foreach ($proposals as $i => $p) {
            if (! is_array($p)) {
                continue;
            }
            $title = (string) ($p['title'] ?? 'item');
            $lines[] = $i.': '.$title.' — start '.(string) ($p['start_datetime'] ?? '');
        }
        $catalog = implode("\n", $lines);

        $body = "Student timezone: {$userTimezone}\nDraft rows (0-based index):\n{$catalog}\n\nStudent edit request:\n{$userMessage}\n\nOutput structured operations; use proposal_index from the list. If there is no concrete time or duration change, use op \"none\".";

        try {
            $pending = Prism::structured()
                ->using($this->resolveProvider(), $this->resolveModel())
                ->withSystemPrompt('You translate short natural-language schedule tweaks into strict edit operations. Never add new tasks. Use proposal_index exactly as given.')
                ->withMessages([new UserMessage($body)])
                ->withSchema(TaskAssistantSchemas::scheduleRefinementOperationsSchema())
                ->withTools([]);

            $pending = $this->applyStructuredOptions($pending, 'schedule_refinement_ops');

            $response = $pending->asStructured();
            $payload = $response->structured ?? [];
            $payload = is_array($payload) ? $payload : [];
            $rawOps = $payload['operations'] ?? [];
            if (! is_array($rawOps)) {
                return [];
            }

            $out = [];
            foreach ($rawOps as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $out[] = [
                    'op' => (string) ($row['op'] ?? 'none'),
                    'proposal_index' => isset($row['proposal_index']) ? (int) $row['proposal_index'] : null,
                    'delta_minutes' => isset($row['delta_minutes']) ? (int) $row['delta_minutes'] : null,
                    'duration_minutes' => isset($row['duration_minutes']) ? (int) $row['duration_minutes'] : null,
                    'local_time_hhmm' => isset($row['local_time_hhmm']) ? (string) $row['local_time_hhmm'] : null,
                    'local_date_ymd' => isset($row['local_date_ymd']) ? (string) $row['local_date_ymd'] : null,
                ];
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('task-assistant.schedule_refinement.llm_parse_failed', [
                'layer' => 'schedule_refinement',
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function resolveProvider(): Provider
    {
        $provider = strtolower((string) config('task-assistant.provider', 'ollama'));

        return match ($provider) {
            'ollama' => Provider::Ollama,
            default => Provider::Ollama,
        };
    }

    private function resolveModel(): string
    {
        return (string) config('task-assistant.model', 'hermes3:3b');
    }

    private function applyStructuredOptions(StructuredPendingRequest $pending, string $generationRoute): StructuredPendingRequest
    {
        $timeout = (int) config('prism.request_timeout', 120);
        $pending = $pending->withClientOptions(['timeout' => $timeout]);

        $base = 'task-assistant.generation';
        $routeKey = $base.'.'.$generationRoute;

        $temperature = config($routeKey.'.temperature');
        $maxTokens = config($routeKey.'.max_tokens');
        $topP = config($routeKey.'.top_p');

        if (! is_numeric($temperature)) {
            $temperature = config($base.'.temperature');
        }
        if (! is_numeric($maxTokens)) {
            $maxTokens = config($base.'.max_tokens');
        }
        if (! is_numeric($topP)) {
            $topP = config($base.'.top_p');
        }

        if (is_numeric($temperature)) {
            $pending = $pending->usingTemperature((float) $temperature);
        }
        if (is_numeric($maxTokens)) {
            $pending = $pending->withMaxTokens((int) $maxTokens);
        }
        if (is_numeric($topP)) {
            $pending = $pending->usingTopP((float) $topP);
        }

        return $pending;
    }
}

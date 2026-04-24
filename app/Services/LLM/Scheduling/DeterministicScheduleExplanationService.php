<?php

namespace App\Services\LLM\Scheduling;

final class DeterministicScheduleExplanationService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *   framing:string,
     *   reasoning:string,
     *   confirmation:string,
     *   explanation_meta:array<string,mixed>
     * }
     */
    public function composeNormal(array $payload): array
    {
        $flowSource = trim((string) ($payload['flow_source'] ?? 'schedule'));
        $scheduleScope = trim((string) ($payload['schedule_scope'] ?? 'all_entities'));
        $requestedWindowLabel = trim((string) ($payload['requested_window_label'] ?? 'your requested window'));
        if ($requestedWindowLabel === '') {
            $requestedWindowLabel = 'your requested window';
        }

        $triggerList = is_array($payload['trigger_list'] ?? null) ? $payload['trigger_list'] : [];
        $triggerList = array_values(array_filter(array_map(
            static fn (mixed $trigger): string => trim((string) $trigger),
            $triggerList
        ), static fn (string $trigger): bool => $trigger !== ''));

        $requestedCount = max(0, (int) ($payload['requested_count'] ?? 0));
        $placedCount = max(0, (int) ($payload['placed_count'] ?? 0));
        $unplacedCount = max(0, (int) ($payload['unplaced_count'] ?? 0));
        $strictWindowRequested = (bool) ($payload['strict_window_requested'] ?? false);
        $autoRollToTomorrow = (bool) ($payload['auto_roll_to_tomorrow'] ?? false);
        $explicitRequestedWindow = (bool) ($payload['explicit_requested_window'] ?? false);
        $requestedWindowHonored = (bool) ($payload['requested_window_honored'] ?? false);
        $fallbackMode = trim((string) ($payload['fallback_mode'] ?? ''));
        $nearestLabel = trim((string) ($payload['nearest_window_label'] ?? ''));
        $chosenTimeLabel = trim((string) ($payload['chosen_time_label'] ?? ''));
        $chosenDaypart = trim((string) ($payload['chosen_daypart'] ?? ''));
        if ($chosenDaypart === '') {
            $chosenDaypart = $this->resolveDaypartFromChosenTimeLabel($chosenTimeLabel);
        }

        $selectedBlockers = $this->selectBlockers(
            is_array($payload['blocking_reasons'] ?? null) ? $payload['blocking_reasons'] : []
        );
        $blockersText = $this->joinBlockersWithWindows($selectedBlockers);
        $blockerTitles = array_values(array_map(
            static fn (array $row): string => (string) ($row['title'] ?? ''),
            $selectedBlockers
        ));

        $scenarioKey = $this->resolveScenarioKey(
            triggerList: $triggerList,
            strictWindowRequested: $strictWindowRequested,
            autoRollToTomorrow: $autoRollToTomorrow,
            requestedCount: $requestedCount,
            placedCount: $placedCount,
            unplacedCount: $unplacedCount,
            explicitRequestedWindow: $explicitRequestedWindow,
            requestedWindowHonored: $requestedWindowHonored,
            flowSource: $flowSource,
            scheduleScope: $scheduleScope,
            hasBlockerTitles: $blockerTitles !== [],
        );

        $framing = $this->buildFraming(
            scenarioKey: $scenarioKey,
            flowSource: $flowSource,
            scheduleScope: $scheduleScope,
            requestedWindowLabel: $requestedWindowLabel,
            chosenDaypart: $chosenDaypart,
        );
        $reasoning = $this->buildReasoning(
            scenarioKey: $scenarioKey,
            requestedWindowLabel: $requestedWindowLabel,
            chosenTimeLabel: $chosenTimeLabel,
            blockersText: $blockersText,
            requestedCount: $requestedCount,
            placedCount: $placedCount,
            unplacedCount: $unplacedCount,
            fallbackMode: $fallbackMode,
            nearestLabel: $nearestLabel,
            hasBlockerTitles: $blockerTitles !== [],
        );
        $confirmation = $this->buildConfirmation(
            scenarioKey: $scenarioKey,
            unplacedCount: $unplacedCount,
        );

        $toneKey = $this->resolveCoachingToneKey($scenarioKey, $chosenDaypart);
        $coachLine = $this->coachingLineForToneKey($toneKey);
        if ($coachLine !== '' && ! str_contains(mb_strtolower($reasoning), mb_strtolower($coachLine))) {
            $reasoning = trim($reasoning.' '.$coachLine);
        }

        return [
            'framing' => $this->guardrail($framing, 220),
            'reasoning' => $this->guardrail($reasoning, 480),
            'confirmation' => $this->guardrail($confirmation, 260),
            'explanation_meta' => [
                'mode' => 'normal',
                'scenario_key' => $scenarioKey,
                'flow_source' => $flowSource,
                'schedule_scope' => $scheduleScope,
                'requested_window' => $requestedWindowLabel,
                'chosen_window' => $chosenTimeLabel,
                'blocker_titles_used' => $blockerTitles,
                'trigger_list' => $triggerList,
                'fallback_applied' => $fallbackMode !== '',
                'strict_window_requested' => $strictWindowRequested,
                'coaching_tone_key' => $toneKey,
                'requested_count' => $requestedCount,
                'placed_count' => $placedCount,
                'unplaced_count' => $unplacedCount,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *   framing:string,
     *   reasoning:string,
     *   confirmation:string,
     *   reason_message:string,
     *   explanation_meta:array<string,mixed>
     * }
     */
    public function composeConfirmation(array $payload): array
    {
        $reasonCode = trim((string) ($payload['reason_code'] ?? 'schedule_confirmation_needed'));
        $requestedWindowLabel = trim((string) ($payload['requested_window_label'] ?? 'your requested window'));
        $requestedCount = max(1, (int) ($payload['requested_count'] ?? 1));
        $placedCount = max(0, (int) ($payload['placed_count'] ?? 0));
        $prompt = trim((string) ($payload['prompt'] ?? 'Do you want to continue with this draft or choose another time window?'));
        $reasonMessage = trim((string) ($payload['reason_message'] ?? 'I prepared a draft and need your confirmation before finalizing.'));
        $reasonDetails = is_array($payload['reason_details'] ?? null) ? $payload['reason_details'] : [];
        $reasonDetails = array_values(array_filter(array_map(
            static fn (mixed $line): string => trim((string) $line),
            $reasonDetails
        ), static fn (string $line): bool => $line !== ''));

        $framing = match ($reasonCode) {
            'strict_window_no_fit', 'explicit_day_not_feasible' => 'I paused before finalizing because your original time request was too tight with current conflicts.',
            'top_n_shortfall' => "I prepared the strongest draft I could for your top {$requestedCount} request.",
            'empty_placement_no_fit', 'unplaced_explicit_targets' => 'I could not place every requested item in the current window, so I saved a safe draft for your decision.',
            default => 'I prepared a draft and paused so you can decide the next move.',
        };

        $reasoning = $reasonMessage;
        if ($reasonCode === 'top_n_shortfall') {
            $taskNoun = $placedCount === 1 ? 'task' : 'tasks';
            $reasoning = "Only {$placedCount} {$taskNoun} fit in {$requestedWindowLabel} in this pass.";
        } elseif ($reasonCode === 'strict_window_no_fit') {
            $reasoning = "Nothing fully fit inside {$requestedWindowLabel} with your current commitments.";
        }

        if ($reasonDetails !== []) {
            $detailSnippet = implode(' ', array_slice($reasonDetails, 0, 2));
            if ($detailSnippet !== '') {
                $reasoning = trim($reasoning.' '.$detailSnippet);
            }
        }

        $toneKey = 'fallback_nearest';
        $coachLine = $this->coachingLineForToneKey($toneKey);
        if ($coachLine !== '' && ! str_contains(mb_strtolower($reasoning), mb_strtolower($coachLine))) {
            $reasoning = trim($reasoning.' '.$coachLine);
        }

        return [
            'framing' => $this->guardrail($framing, 220),
            'reasoning' => $this->guardrail($reasoning, 420),
            'confirmation' => $this->guardrail($prompt, 260),
            'reason_message' => $this->guardrail($reasoning, 280),
            'explanation_meta' => [
                'mode' => 'confirmation',
                'scenario_key' => strtoupper($reasonCode),
                'coaching_tone_key' => $toneKey,
                'requested_count' => $requestedCount,
                'placed_count' => $placedCount,
                'requested_window' => $requestedWindowLabel,
            ],
        ];
    }

    /**
     * @param  list<string>  $triggerList
     */
    private function resolveScenarioKey(
        array $triggerList,
        bool $strictWindowRequested,
        bool $autoRollToTomorrow,
        int $requestedCount,
        int $placedCount,
        int $unplacedCount,
        bool $explicitRequestedWindow,
        bool $requestedWindowHonored,
        string $flowSource,
        string $scheduleScope,
        bool $hasBlockerTitles,
    ): string {
        if (in_array('strict_window_no_fit', $triggerList, true) || $strictWindowRequested) {
            return 'STRICT_WINDOW_NO_FIT';
        }
        if ($autoRollToTomorrow) {
            return 'AUTO_ROLL_LATER_TO_TOMORROW';
        }
        if (in_array('adaptive_relaxed_placement', $triggerList, true)) {
            return 'ADAPTIVE_FALLBACK_RELAXED';
        }
        if (in_array('top_n_shortfall', $triggerList, true) || ($requestedCount > 0 && $placedCount > 0 && $placedCount < $requestedCount)) {
            return 'TOP_N_SHORTFALL';
        }
        if ($unplacedCount > 0 || in_array('unplaced_units', $triggerList, true)) {
            return 'UNPLACED_TARGETS_EXIST';
        }
        if (in_array('requested_window_unsatisfied', $triggerList, true) || in_array('hinted_window_unsatisfied', $triggerList, true)) {
            return $hasBlockerTitles ? 'BLOCKED_WINDOW_SHIFTED' : 'MISSING_BLOCKER_TITLES';
        }
        if (in_array('placement_outside_horizon', $triggerList, true)) {
            return 'PLACEMENT_OUTSIDE_HORIZON';
        }
        if (in_array('empty_placement', $triggerList, true) && $placedCount === 0) {
            return 'EMPTY_CANDIDATE_LIST';
        }
        if ($hasBlockerTitles && $placedCount > 0) {
            return 'BLOCKED_WINDOW_SHIFTED';
        }
        if ($explicitRequestedWindow && $requestedWindowHonored) {
            return 'REQUESTED_WINDOW_HONORED';
        }
        if ($flowSource === 'prioritize_schedule' && $scheduleScope === 'tasks_only') {
            return 'FLOW_PRIORITIZE_SCHEDULE_TASKS_ONLY';
        }
        if (! $hasBlockerTitles && in_array('unplaced_units', $triggerList, true)) {
            return 'MISSING_BLOCKER_TITLES';
        }

        return 'DEFAULT_FEASIBLE_PLACEMENT';
    }

    private function buildFraming(
        string $scenarioKey,
        string $flowSource,
        string $scheduleScope,
        string $requestedWindowLabel,
        string $chosenDaypart,
    ): string {
        if ($scenarioKey === 'FLOW_PRIORITIZE_SCHEDULE_TASKS_ONLY' || ($flowSource === 'prioritize_schedule' && $scheduleScope === 'tasks_only')) {
            return 'I scheduled your top-ranked tasks first, then placed them in realistic open windows.';
        }

        return match ($scenarioKey) {
            'STRICT_WINDOW_NO_FIT' => "I checked {$requestedWindowLabel} first and paused because that strict window could not fully fit.",
            'AUTO_ROLL_LATER_TO_TOMORROW' => 'I finished checking today and moved this to the nearest open window tomorrow.',
            'ADAPTIVE_FALLBACK_RELAXED' => 'I widened the placement window to keep your plan feasible.',
            'TOP_N_SHORTFALL' => 'I built the strongest draft possible for your requested count.',
            'UNPLACED_TARGETS_EXIST' => 'I scheduled what fit cleanly and held the rest for your next decision.',
            'BLOCKED_WINDOW_SHIFTED' => 'I moved this to the next conflict-free slot.',
            'MISSING_BLOCKER_TITLES' => 'I moved this based on occupied time in your earlier window.',
            'REQUESTED_WINDOW_HONORED' => "I kept this in {$requestedWindowLabel} as requested.",
            'EMPTY_CANDIDATE_LIST' => 'I could not find schedulable items in this scope yet.',
            default => $chosenDaypart !== ''
                ? "I placed this in your {$chosenDaypart} availability for a realistic start."
                : 'I placed this in the closest feasible window for your request.',
        };
    }

    private function buildReasoning(
        string $scenarioKey,
        string $requestedWindowLabel,
        string $chosenTimeLabel,
        string $blockersText,
        int $requestedCount,
        int $placedCount,
        int $unplacedCount,
        string $fallbackMode,
        string $nearestLabel,
        bool $hasBlockerTitles,
    ): string {
        return match ($scenarioKey) {
            'STRICT_WINDOW_NO_FIT' => $hasBlockerTitles
                ? "I could not fit {$requestedWindowLabel} because {$blockersText} occupied that period, so I used the nearest open slot{$this->suffixAtLabel($chosenTimeLabel)}."
                : "I could not fit {$requestedWindowLabel} with current overlaps, so I used the nearest open slot{$this->suffixAtLabel($chosenTimeLabel)}.",
            'AUTO_ROLL_LATER_TO_TOMORROW' => "There was not enough capacity left today, so this moved to tomorrow{$this->suffixAtLabel($chosenTimeLabel)}.",
            'ADAPTIVE_FALLBACK_RELAXED' => $nearestLabel !== ''
                ? "Your first window was too tight, so I relaxed it and used {$nearestLabel}."
                : "Your first window was too tight, so I relaxed it and used the next feasible slot{$this->suffixAtLabel($chosenTimeLabel)}.",
            'TOP_N_SHORTFALL' => "You asked for {$requestedCount}, and {$placedCount} fit in {$requestedWindowLabel} in this pass.".($hasBlockerTitles ? " Main blockers were {$blockersText}." : ''),
            'UNPLACED_TARGETS_EXIST' => "I scheduled what fit and left {$unplacedCount} unscheduled item(s) because no conflict-free slot remained in this window.".($hasBlockerTitles ? " The tightest constraints were {$blockersText}." : ''),
            'BLOCKED_WINDOW_SHIFTED' => $hasBlockerTitles
                ? "I placed this{$this->suffixAtLabel($chosenTimeLabel)} because {$blockersText} occupied your earlier requested window."
                : "I placed this{$this->suffixAtLabel($chosenTimeLabel)} because your earlier window was occupied.",
            'PLACEMENT_OUTSIDE_HORIZON' => "The closest valid slot was outside the original horizon, so I drafted the nearest feasible alternative{$this->suffixAtLabel($chosenTimeLabel)}.",
            'REQUESTED_WINDOW_HONORED' => "That window stayed open and conflict-free, so placement remained inside {$requestedWindowLabel}.",
            'FLOW_PRIORITIZE_SCHEDULE_TASKS_ONLY' => 'This schedule is based on your ranked task targets first, then matched to conflict-free windows.',
            'MISSING_BLOCKER_TITLES' => "I moved this{$this->suffixAtLabel($chosenTimeLabel)} because earlier windows were occupied even though blocker titles were not available.",
            'EMPTY_CANDIDATE_LIST' => 'There are no schedulable items in this scope right now. Add or unfilter tasks, then I can place them immediately.',
            default => $fallbackMode !== ''
                ? "I used a safer fallback placement mode to keep this plan feasible{$this->suffixAtLabel($chosenTimeLabel)}."
                : ($hasBlockerTitles
                    ? "I placed this{$this->suffixAtLabel($chosenTimeLabel)} because {$blockersText} occupied earlier windows."
                    : "I placed this{$this->suffixAtLabel($chosenTimeLabel)} because it is the closest open slot that fits your requested scope."),
        };
    }

    private function buildConfirmation(string $scenarioKey, int $unplacedCount): string
    {
        return match ($scenarioKey) {
            'STRICT_WINDOW_NO_FIT' => 'Do you want me to keep this nearest-slot draft, or should we pick another time window?',
            'TOP_N_SHORTFALL' => 'Do you want to continue with this draft or widen the window to fit more items?',
            'UNPLACED_TARGETS_EXIST' => $unplacedCount > 0
                ? 'Want me to keep this feasible subset now, then schedule the remaining items next?'
                : 'Do you want to continue with this draft or adjust the time window?',
            'EMPTY_CANDIDATE_LIST' => 'Want me to help you prioritize or adjust filters first so we can schedule right away?',
            default => 'Do these times look workable, or should I shift earlier/later before you finalize?',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $blockingReasons
     * @return list<array{title:string,blocked_window:string}>
     */
    private function selectBlockers(array $blockingReasons): array
    {
        $rows = [];
        foreach ($blockingReasons as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $window = trim((string) ($row['blocked_window'] ?? ''));
            $reason = trim((string) ($row['reason'] ?? ''));
            $sourceType = mb_strtolower(trim((string) ($row['source_type'] ?? '')));
            $overlapScore = str_contains(mb_strtolower($reason), 'overlap') ? 3 : 0;
            $overlapScore += str_contains(mb_strtolower($reason), 'occupied') ? 2 : 0;
            $overlapScore += match ($sourceType) {
                'class' => 2,
                'event' => 1,
                default => 0,
            };
            $durationScore = $this->windowDurationMinutes($window);
            $windowStartMinutes = $this->windowStartMinutes($window);
            $rows[] = [
                'title' => $title,
                'blocked_window' => $window,
                'overlap_score' => $overlapScore,
                'duration_score' => $durationScore,
                'window_start_minutes' => $windowStartMinutes,
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            $overlapCompare = (int) ($right['overlap_score'] ?? 0) <=> (int) ($left['overlap_score'] ?? 0);
            if ($overlapCompare !== 0) {
                return $overlapCompare;
            }
            $durationCompare = (int) ($right['duration_score'] ?? 0) <=> (int) ($left['duration_score'] ?? 0);
            if ($durationCompare !== 0) {
                return $durationCompare;
            }
            $startCompare = (int) ($left['window_start_minutes'] ?? 0) <=> (int) ($right['window_start_minutes'] ?? 0);
            if ($startCompare !== 0) {
                return $startCompare;
            }

            return strcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
        });

        $selected = [];
        $seen = [];
        foreach ($rows as $row) {
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $key = mb_strtolower($title);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $selected[] = [
                'title' => $title,
                'blocked_window' => trim((string) ($row['blocked_window'] ?? '')),
            ];
            if (count($selected) >= 2) {
                break;
            }
        }

        return $selected;
    }

    /**
     * @param  list<array{title:string,blocked_window:string}>  $blockers
     */
    private function joinBlockersWithWindows(array $blockers): string
    {
        if ($blockers === []) {
            return '';
        }
        $labels = array_values(array_filter(array_map(
            static function (array $row): string {
                $title = trim((string) ($row['title'] ?? ''));
                $window = trim((string) ($row['blocked_window'] ?? ''));
                if ($title === '') {
                    return '';
                }

                return $window !== '' ? "{$title} ({$window})" : $title;
            },
            $blockers
        ), static fn (string $value): bool => $value !== ''));

        if ($labels === []) {
            return '';
        }
        if (count($labels) === 1) {
            return $labels[0];
        }

        return $labels[0].' and '.$labels[1];
    }

    private function windowDurationMinutes(string $window): int
    {
        $parts = preg_split('/\s*-\s*/', trim($window));
        if (! is_array($parts) || count($parts) !== 2) {
            return 0;
        }
        $start = $this->parseTimeToMinutes($parts[0]);
        $end = $this->parseTimeToMinutes($parts[1]);
        if ($start === null || $end === null) {
            return 0;
        }

        return $end >= $start ? ($end - $start) : (($end + 1440) - $start);
    }

    private function windowStartMinutes(string $window): int
    {
        $parts = preg_split('/\s*-\s*/', trim($window));
        if (! is_array($parts) || $parts === []) {
            return 0;
        }

        return $this->parseTimeToMinutes((string) $parts[0]) ?? 0;
    }

    private function parseTimeToMinutes(string $label): ?int
    {
        $normalized = trim($label);
        if ($normalized === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i', $normalized, $matches) !== 1) {
            return null;
        }
        $hour = (int) ($matches[1] ?? 0);
        $minute = (int) ($matches[2] ?? 0);
        $ampm = mb_strtolower((string) ($matches[3] ?? 'am'));
        if ($hour < 1 || $hour > 12 || $minute < 0 || $minute > 59) {
            return null;
        }
        if ($hour === 12) {
            $hour = 0;
        }
        $hour24 = $ampm === 'pm' ? $hour + 12 : $hour;

        return ($hour24 * 60) + $minute;
    }

    private function resolveCoachingToneKey(string $scenarioKey, string $chosenDaypart): string
    {
        return match ($scenarioKey) {
            'STRICT_WINDOW_NO_FIT', 'AUTO_ROLL_LATER_TO_TOMORROW', 'ADAPTIVE_FALLBACK_RELAXED' => 'fallback_nearest',
            'TOP_N_SHORTFALL', 'UNPLACED_TARGETS_EXIST' => 'partial_fit',
            'BLOCKED_WINDOW_SHIFTED' => 'post_class_or_after_commitment',
            default => match (mb_strtolower($chosenDaypart)) {
                'morning' => 'morning_momentum',
                'afternoon' => 'afternoon_restart',
                'evening' => 'evening_closure',
                default => 'realistic_planning',
            },
        };
    }

    private function coachingLineForToneKey(string $toneKey): string
    {
        return match ($toneKey) {
            'morning_momentum' => 'Starting earlier gives you stronger focus and more recovery time if plans shift.',
            'afternoon_restart' => 'An afternoon block is a practical restart window after earlier commitments.',
            'evening_closure' => 'A defined evening block helps you close the day with one clear win.',
            'post_class_or_after_commitment' => 'Scheduling right after commitments reduces idle time and keeps momentum.',
            'focus_protection' => 'Protecting one active focus block at a time improves completion rate.',
            'fallback_nearest' => 'Using the closest open slot keeps progress moving instead of postponing the task.',
            'partial_fit' => 'A realistic smaller plan is easier to execute than an overloaded one.',
            'next_step_prompt' => 'Once one item is added or unblocked, I can schedule it immediately.',
            default => 'Using real gaps is better than overbooking and slipping tasks.',
        };
    }

    private function resolveDaypartFromChosenTimeLabel(string $chosenTimeLabel): string
    {
        if ($chosenTimeLabel === '') {
            return '';
        }
        if (preg_match('/\b(\d{1,2})(?::\d{2})?\s*(am|pm)\b/i', $chosenTimeLabel, $matches) !== 1) {
            return '';
        }

        $hour = (int) ($matches[1] ?? 0);
        $ampm = mb_strtolower((string) ($matches[2] ?? 'am'));
        if ($hour === 12) {
            $hour = 0;
        }
        $hour24 = $ampm === 'pm' ? $hour + 12 : $hour;

        return match (true) {
            $hour24 < 12 => 'morning',
            $hour24 < 18 => 'afternoon',
            default => 'evening',
        };
    }

    private function suffixAtLabel(string $label): string
    {
        return $label !== '' ? " at {$label}" : '';
    }

    private function guardrail(string $text, int $maxChars): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($value === '') {
            return '';
        }

        $value = str_ireplace(
            ['snapshot', 'digest', 'json', 'backend', 'server-side', 'horizon mode', 'order below', 'ranked list', 'top to bottom'],
            '',
            $value
        );
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if (mb_strlen($value) <= $maxChars) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, max(0, $maxChars - 1))).'…';
    }
}

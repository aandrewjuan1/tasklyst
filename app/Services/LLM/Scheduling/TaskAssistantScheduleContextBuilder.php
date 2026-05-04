<?php

namespace App\Services\LLM\Scheduling;

use App\Services\LLM\Prioritization\TaskAssistantTaskChoiceConstraintsExtractor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Deterministic scheduling context extracted from the user message (no LLM).
 * Delegates shared signals to {@see TaskAssistantTaskChoiceConstraintsExtractor} so scheduling matches prioritize ranking context.
 * {@see TaskAssistantScheduleHorizonResolver} sets which calendar day(s) to search; task-level time filters
 * (e.g. due today) remain on {@see TaskAssistantTaskChoiceConstraintsExtractor} output.
 * Output shape matches {@see TaskAssistantStructuredFlowGenerator::applyContextToSnapshot} expectations.
 */
final class TaskAssistantScheduleContextBuilder
{
    public function __construct(
        private readonly TaskAssistantTaskChoiceConstraintsExtractor $constraintsExtractor,
        private readonly TaskAssistantScheduleHorizonResolver $horizonResolver,
        private readonly SchedulingIntentInterpreter $intentInterpreter,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{
     *   intent_type: string,
     *   priority_filters: list<string>,
     *   task_keywords: list<string>,
     *   strict_filtering: bool,
     *   time_constraint: string,
     *   domain_focus: 'school'|'chores'|null,
     *   entity_type_preference: 'task'|'event'|'project'|'mixed',
     *   comparison_focus: string|null,
     *   recurring_requested: bool,
     *   schedule_horizon: array{
     *     mode: 'single_day'|'range',
     *     start_date: string,
     *     end_date: string,
     *     label: string
     *   }
     * }
     */
    public function build(string $userMessage, array $snapshot): array
    {
        $extracted = $this->constraintsExtractor->extract($userMessage);
        $normalized = $this->buildScheduleContext($userMessage, $extracted);
        $defaultAsapMode = $this->shouldUseDefaultAsapMode($userMessage, $extracted);
        $normalized['default_asap_mode'] = $defaultAsapMode;
        if ($defaultAsapMode) {
            $normalized['schedule_intent_reason_codes'] = ['intent_default_asap_mode'];
        }

        $timezone = (string) ($snapshot['timezone'] ?? config('app.timezone', 'Asia/Manila'));
        $todayStr = (string) ($snapshot['today'] ?? now($timezone)->format('Y-m-d'));
        $nowRaw = is_string($snapshot['now'] ?? null) ? trim((string) $snapshot['now']) : '';
        if ($nowRaw !== '') {
            try {
                $now = CarbonImmutable::parse($nowRaw, $timezone);
            } catch (\Throwable) {
                $now = CarbonImmutable::parse($todayStr.' 12:00:00', $timezone);
            }
        } else {
            $now = CarbonImmutable::now($timezone);
        }
        $normalized['schedule_horizon'] = $this->horizonResolver->resolve($userMessage, $timezone, $now);

        $intent = $this->intentInterpreter->interpret(
            $userMessage,
            $timezone,
            $now,
            $this->resolveDayBoundsFromSnapshot($snapshot)
        );
        $intentReasonCodes = is_array($intent['reason_codes'] ?? null) ? $intent['reason_codes'] : [];
        $horizonResolved = is_array($normalized['schedule_horizon'] ?? null) ? $normalized['schedule_horizon'] : [];
        $normalized['schedule_horizon'] = $this->maybeWidenSmartDefaultHorizon(
            $horizonResolved,
            (string) ($normalized['time_constraint'] ?? 'none'),
            $intentReasonCodes,
            $timezone,
            $defaultAsapMode,
        );

        $explicitOverrideRaw = is_string($snapshot['refinement_explicit_day_override'] ?? null)
            ? trim((string) $snapshot['refinement_explicit_day_override'])
            : '';
        if ($explicitOverrideRaw !== '') {
            try {
                $override = CarbonImmutable::parse($explicitOverrideRaw, $timezone)->toDateString();
                $normalized['schedule_horizon'] = [
                    'mode' => 'single_day',
                    'start_date' => $override,
                    'end_date' => $override,
                    'label' => 'refinement_explicit_override',
                ];
            } catch (\Throwable) {
                // Keep resolved horizon when override parsing fails.
            }
        } else {
            $anchorRaw = is_string($snapshot['refinement_anchor_date'] ?? null)
                ? trim((string) $snapshot['refinement_anchor_date'])
                : '';
            if ($anchorRaw !== '') {
                try {
                    $anchor = CarbonImmutable::parse($anchorRaw, $timezone)->toDateString();
                    $normalized['schedule_horizon'] = [
                        'mode' => 'single_day',
                        'start_date' => $anchor,
                        'end_date' => $anchor,
                        'label' => 'refinement_anchor',
                    ];
                } catch (\Throwable) {
                    // Keep resolved horizon when anchor parsing fails.
                }
            }
        }

        $horizon = is_array($normalized['schedule_horizon'] ?? null) ? $normalized['schedule_horizon'] : [];
        $isMultiDayHorizon = ($horizon['mode'] ?? '') === 'range'
            && isset($horizon['start_date'], $horizon['end_date'])
            && (string) $horizon['start_date'] !== (string) $horizon['end_date'];
        $intentFlags = is_array($intent['intent_flags'] ?? null) ? $intent['intent_flags'] : [];
        if ($isMultiDayHorizon && (($intentFlags['has_later'] ?? false) === true)) {
            $intent['time_window'] = ['start' => '08:00', 'end' => '22:00'];
            $reasonCodes = is_array($intent['reason_codes'] ?? null) ? $intent['reason_codes'] : [];
            if (! in_array('intent_time_window_later_multiday_default', $reasonCodes, true)) {
                $reasonCodes[] = 'intent_time_window_later_multiday_default';
            }
            $intent['reason_codes'] = $reasonCodes;
        }
        $normalized['time_window'] = $intent['time_window'] ?? null;
        $normalized['time_window_strict'] = (bool) ($intent['strict_window'] ?? false);
        $normalized['has_explicit_clock_time'] = (bool) ($intent['has_explicit_clock_time'] ?? false);
        $normalized['schedule_intent_flags'] = is_array($intent['intent_flags'] ?? null)
            ? $intent['intent_flags']
            : [];
        $normalized['schedule_intent_reason_codes'] = $intent['reason_codes'] ?? [];
        $normalized['requested_horizon_label'] = $this->resolveRequestedHorizonLabel(
            is_array($normalized['schedule_horizon'] ?? null) ? $normalized['schedule_horizon'] : []
        );
        $normalized['requested_window_display_label'] = $this->resolveRequestedWindowDisplayLabel(
            is_array($normalized['schedule_horizon'] ?? null) ? $normalized['schedule_horizon'] : [],
            (bool) ($normalized['has_explicit_clock_time'] ?? false),
            is_array($normalized['time_window'] ?? null) ? $normalized['time_window'] : []
        );
        if ($defaultAsapMode) {
            $reasonCodes = is_array($normalized['schedule_intent_reason_codes'] ?? null)
                ? $normalized['schedule_intent_reason_codes']
                : [];
            if (! in_array('intent_default_asap_mode', $reasonCodes, true)) {
                $reasonCodes[] = 'intent_default_asap_mode';
            }
            if (($normalized['schedule_horizon']['label'] ?? null) === 'default_asap_spread'
                && ! in_array('intent_default_asap_horizon_spread', $reasonCodes, true)) {
                $reasonCodes[] = 'intent_default_asap_horizon_spread';
            }
            $normalized['schedule_intent_reason_codes'] = $reasonCodes;
        }

        Log::info('task-assistant.schedule_context_deterministic', [
            'layer' => 'structured_generation',
            'thread_id' => app()->bound('task_assistant.thread_id') ? app('task_assistant.thread_id') : null,
            'user_message_length' => mb_strlen($userMessage),
            'analysis' => $normalized,
            'snapshot_task_count' => is_array($snapshot['tasks'] ?? null) ? count($snapshot['tasks']) : 0,
        ]);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $extracted
     * @return array{
     *   intent_type: string,
     *   priority_filters: list<string>,
     *   task_keywords: list<string>,
     *   strict_filtering: bool,
     *   time_constraint: string,
     *   domain_focus: 'school'|'chores'|null,
     *   entity_type_preference: 'task'|'event'|'project'|'mixed',
     *   comparison_focus: string|null,
     *   recurring_requested: bool
     * }
     */
    private function buildScheduleContext(string $userMessage, array $extracted): array
    {
        $messageLower = mb_strtolower($userMessage);

        $intentType = 'general';
        $comparisonFocus = null;

        if (str_contains($messageLower, 'between') && str_contains($messageLower, 'and')) {
            $intentType = 'specific_comparison';
            $comparisonFocus = 'user_specified_choice';
        } elseif (in_array('urgent', $extracted['priority_filters'] ?? [], true)) {
            $intentType = 'urgent_focus';
        }

        $timeConstraintRaw = $extracted['time_constraint'] ?? null;
        $timeConstraint = 'none';
        if ($timeConstraintRaw === 'today') {
            $timeConstraint = 'today';
        } elseif ($timeConstraintRaw === 'this_week') {
            $timeConstraint = 'this_week';
        }

        $domainFocus = $extracted['domain_focus'] ?? null;
        $entityTypePreference = (string) ($extracted['entity_type_preference'] ?? 'mixed');
        if (! in_array($entityTypePreference, ['task', 'event', 'project', 'mixed'], true)) {
            $entityTypePreference = 'mixed';
        }

        return [
            'intent_type' => $intentType,
            'priority_filters' => array_values(array_unique($extracted['priority_filters'] ?? [])),
            'task_keywords' => array_values(array_unique($extracted['task_keywords'] ?? [])),
            'strict_filtering' => (bool) ($extracted['strict_filtering'] ?? false),
            'time_constraint' => $timeConstraint,
            'domain_focus' => is_string($domainFocus) ? $domainFocus : null,
            'entity_type_preference' => $entityTypePreference,
            'comparison_focus' => $comparisonFocus,
            'recurring_requested' => (bool) ($extracted['recurring_requested'] ?? false),
        ];
    }

    /**
     * @param  array{mode?:string,start_date?:string,end_date?:string,label?:string}  $horizon
     * @param  list<string>  $intentReasonCodes
     * @return array{mode:string,start_date:string,end_date:string,label:string}
     */
    private function maybeWidenSmartDefaultHorizon(
        array $horizon,
        string $timeConstraint,
        array $intentReasonCodes,
        string $timezone,
        bool $defaultAsapMode = false,
    ): array {
        $label = (string) ($horizon['label'] ?? '');
        if ($this->isExplicitDateLikeHorizonLabel($label)) {
            return $this->normalizeHorizonShape($horizon);
        }

        if (($horizon['label'] ?? '') !== 'default_today') {
            return $this->normalizeHorizonShape($horizon);
        }
        if ($timeConstraint !== 'none') {
            return $this->normalizeHorizonShape($horizon);
        }
        if ($this->smartDefaultSpreadBlockedByIntentSignals($intentReasonCodes)) {
            return $this->normalizeHorizonShape($horizon);
        }

        $startRaw = trim((string) ($horizon['start_date'] ?? ''));
        if ($startRaw === '') {
            return $this->normalizeHorizonShape($horizon);
        }

        $maxDays = max(1, (int) config('task-assistant.schedule.max_horizon_days', 14));
        $spread = max(1, (int) config('task-assistant.schedule.smart_default_spread_days', 7));
        $spanDays = min($spread, $maxDays);

        try {
            $start = CarbonImmutable::parse($startRaw, $timezone)->startOfDay();
        } catch (\Throwable) {
            return $this->normalizeHorizonShape($horizon);
        }

        $end = $start->addDays($spanDays - 1);

        return [
            'mode' => 'range',
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'label' => $defaultAsapMode ? 'default_asap_spread' : 'smart_default_spread',
        ];
    }

    /**
     * Block widening when the user already named a daypart, "later", combined windows, etc.—those
     * stay same-day scoped; widening would flip {@see $isMultiDayHorizon} and trigger the multiday
     * "later" time-window override incorrectly.
     *
     * @param  list<string>  $intentReasonCodes
     */
    private function smartDefaultSpreadBlockedByIntentSignals(array $intentReasonCodes): bool
    {
        $blockedPrefixes = [
            'intent_time_window_explicit',
            'intent_time_window_after_anchor',
            'intent_time_window_evening',
            'intent_time_window_morning',
            'intent_time_window_afternoon',
            'intent_time_window_later_after_now',
            'intent_time_window_combined_named',
            'intent_time_window_onwards',
            'intent_time_window_later_multiday_default',
        ];

        foreach ($intentReasonCodes as $code) {
            if (! is_string($code) || $code === '') {
                continue;
            }
            foreach ($blockedPrefixes as $prefix) {
                if (str_starts_with($code, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array{mode?:string,start_date?:string,end_date?:string,label?:string}  $horizon
     * @return array{mode:string,start_date:string,end_date:string,label:string}
     */
    private function normalizeHorizonShape(array $horizon): array
    {
        return [
            'mode' => (string) ($horizon['mode'] ?? 'single_day'),
            'start_date' => (string) ($horizon['start_date'] ?? ''),
            'end_date' => (string) ($horizon['end_date'] ?? ''),
            'label' => (string) ($horizon['label'] ?? ''),
        ];
    }

    private function isExplicitDateLikeHorizonLabel(string $label): bool
    {
        if ($label === '') {
            return false;
        }

        return str_starts_with($label, 'explicit_date_')
            || str_starts_with($label, 'relative_days_')
            || str_starts_with($label, 'qualified_weekday_');
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{start?: string, end?: string}
     */
    private function resolveDayBoundsFromSnapshot(array $snapshot): array
    {
        $preferences = is_array($snapshot['schedule_preferences'] ?? null)
            ? $snapshot['schedule_preferences']
            : [];
        $dayBounds = is_array($preferences['day_bounds'] ?? null)
            ? $preferences['day_bounds']
            : [];

        $start = is_string($dayBounds['start'] ?? null) ? trim((string) $dayBounds['start']) : '';
        $end = is_string($dayBounds['end'] ?? null) ? trim((string) $dayBounds['end']) : '';

        return array_filter([
            'start' => $start !== '' ? $start : null,
            'end' => $end !== '' ? $end : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $extracted
     */
    private function shouldUseDefaultAsapMode(string $userMessage, array $extracted): bool
    {
        $lower = mb_strtolower(trim($userMessage));
        if ($lower === '') {
            return false;
        }

        $hasExplicitDateLike = preg_match(
            '/\b(today|tomorrow|tonight|this\s+week|next\s+week|this\s+weekend|next\s+weekend|monday|tuesday|wednesday|thursday|friday|saturday|sunday|\d{4}-\d{2}-\d{2}|\d{1,2}\/\d{1,2})\b/u',
            $lower
        ) === 1;
        if ($hasExplicitDateLike) {
            return false;
        }

        $hasClockOrDaypart = preg_match(
            '/\b(\d{1,2}(:\d{2})?\s*(am|pm)|morning|afternoon|evening|night|later|onward(s)?|after\s+(lunch|dinner|class|school|work|office|home|gym|breakfast))\b/u',
            $lower
        ) === 1;
        if ($hasClockOrDaypart) {
            return false;
        }

        $timeConstraintRaw = (string) ($extracted['time_constraint'] ?? 'none');
        if ($timeConstraintRaw !== 'none') {
            return false;
        }

        return true;
    }

    /**
     * @param  array{mode?:string,start_date?:string,end_date?:string,label?:string}  $horizon
     */
    private function resolveRequestedHorizonLabel(array $horizon): string
    {
        $label = trim((string) ($horizon['label'] ?? ''));

        return match (true) {
            $label === 'tomorrow' => 'tomorrow',
            $label === 'today',
            $label === 'default_today' => 'today',
            $label === 'this week' => 'this week',
            $label === 'next week' => 'next week',
            str_starts_with($label, 'qualified_weekday_') => trim(str_replace('qualified_weekday_', '', $label)),
            str_starts_with($label, 'relative_days_') => 'the selected day',
            str_starts_with($label, 'explicit_date_') => 'the selected day',
            ($horizon['mode'] ?? '') === 'range' => 'this window',
            default => 'your requested window',
        };
    }

    /**
     * @param  array{mode?:string,start_date?:string,end_date?:string,label?:string}  $horizon
     * @param  array{start?:string,end?:string}  $timeWindow
     */
    private function resolveRequestedWindowDisplayLabel(array $horizon, bool $hasExplicitClockTime, array $timeWindow): string
    {
        if ($hasExplicitClockTime) {
            $start = trim((string) ($timeWindow['start'] ?? ''));
            $end = trim((string) ($timeWindow['end'] ?? ''));
            if ($start !== '' && $end !== '') {
                return $start.'-'.$end;
            }
        }

        $horizonLabel = $this->resolveRequestedHorizonLabel($horizon);

        return match (true) {
            $horizonLabel === 'today' => 'today',
            $horizonLabel === 'tomorrow' => 'tomorrow',
            $horizonLabel === 'this week' => 'this week',
            $horizonLabel === 'next week' => 'next week',
            ($horizon['mode'] ?? '') === 'range' => "this week's window",
            default => 'your requested window',
        };
    }
}

<?php

namespace App\Services\LLM\Scheduling;

use App\Services\LLM\TaskAssistant\ExecutionPlan;

final class ScheduleFallbackPolicy
{
    /**
     * @param  array<string, mixed>  $scheduleData
     */
    public function shouldRequireConfirmation(ExecutionPlan $plan, array $scheduleData): bool
    {
        if ($this->shouldAutoAcceptDefaultAsapSpill($scheduleData)) {
            return false;
        }

        $digest = is_array($scheduleData['placement_digest'] ?? null) ? $scheduleData['placement_digest'] : [];
        $signals = $digest['confirmation_signals'] ?? null;

        if (is_array($signals) && isset($signals['triggers']) && is_array($signals['triggers'])) {
            $allowed = config('task-assistant.schedule.confirmation_triggers', []);
            if (! is_array($allowed)) {
                $allowed = [];
            }

            foreach ($signals['triggers'] as $trigger) {
                if (! is_string($trigger) || $trigger === '') {
                    continue;
                }
                if (in_array($trigger, $allowed, true)) {
                    return true;
                }
            }

            return false;
        }

        return $this->legacyShouldRequireConfirmation($plan, $scheduleData);
    }

    /**
     * @param  array<string, mixed>  $scheduleData
     */
    private function legacyShouldRequireConfirmation(ExecutionPlan $plan, array $scheduleData): bool
    {
        $digest = is_array($scheduleData['placement_digest'] ?? null) ? $scheduleData['placement_digest'] : [];
        $fallbackMode = (string) ($digest['fallback_mode'] ?? '');
        $topNShortfall = (bool) ($digest['top_n_shortfall'] ?? false);
        $topNPolicy = (string) config('task-assistant.schedule.top_n_shortfall_policy', 'confirm_if_shortfall');

        if ($topNPolicy === 'confirm_if_shortfall' && $topNShortfall) {
            return true;
        }

        if ($plan->timeWindowHint === 'later') {
            return $fallbackMode === 'auto_relaxed_today_or_tomorrow';
        }

        return false;
    }

    /**
     * Default no-time/no-date schedule requests should auto-propose if we found at least one slot
     * inside the auto-spill horizon.
     *
     * @param  array<string, mixed>  $scheduleData
     */
    private function shouldAutoAcceptDefaultAsapSpill(array $scheduleData): bool
    {
        $digest = is_array($scheduleData['placement_digest'] ?? null) ? $scheduleData['placement_digest'] : [];
        $defaultAsapMode = (bool) ($digest['default_asap_mode'] ?? false);
        if (! $defaultAsapMode) {
            return false;
        }

        $attemptedHorizon = is_array($digest['attempted_horizon'] ?? null) ? $digest['attempted_horizon'] : [];
        $horizonLabel = trim((string) ($attemptedHorizon['label'] ?? ''));
        if ($horizonLabel !== 'default_asap_spread') {
            return false;
        }

        $proposals = is_array($scheduleData['proposals'] ?? null) ? $scheduleData['proposals'] : [];

        return count($proposals) > 0;
    }

    public function classifyPendingDecision(string $content): string
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $content) ?? $content));
        if ($normalized === '') {
            return 'unknown';
        }

        if (preg_match('/\b(yes|confirm|continue|go ahead|works)\b/u', $normalized) === 1) {
            return 'confirm';
        }

        if (preg_match('/\b(no|cancel|stop|decline|don\'t)\b/u', $normalized) === 1) {
            return 'decline';
        }

        return 'unknown';
    }
}

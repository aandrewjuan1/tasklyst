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

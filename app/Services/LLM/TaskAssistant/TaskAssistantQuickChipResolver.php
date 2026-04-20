<?php

namespace App\Services\LLM\TaskAssistant;

use App\Enums\TaskPriority;
use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Models\User;
use Carbon\CarbonImmutable;

final class TaskAssistantQuickChipResolver
{
    /**
     * @return array<int, string>
     */
    public function resolveForEmptyState(User $user, ?TaskAssistantThread $thread = null, int $limit = 4): array
    {
        $limit = max(1, min(6, $limit));
        $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'));
        $bucket = $this->resolveTimeBucket($now);
        $weekContext = $this->resolveWeekContext($now);
        $signals = $this->collectTaskSignals($user, $now);

        /** @var array<string, mixed> $conversationState */
        $conversationState = is_array(data_get($thread?->metadata, 'conversation_state'))
            ? data_get($thread?->metadata, 'conversation_state')
            : [];
        $lastSourceFlow = (string) data_get($conversationState, 'last_listing.source_flow', '');

        $candidates = $this->baseCandidates();

        foreach ($candidates as $key => &$candidate) {
            $score = (int) ($candidate['score'] ?? 0);
            $intent = (string) ($candidate['intent'] ?? '');

            if ($intent === 'plan_today' && $bucket === 'morning') {
                $score += 130;
            }

            if ($intent === 'plan_tomorrow' && in_array($bucket, ['evening', 'late_night'], true)) {
                $score += 150;
            }

            if ($intent === 'plan_next_week' && in_array($weekContext, ['friday_evening', 'friday_late_night', 'saturday_evening', 'saturday_late_night'], true)) {
                $score += 180;
            }

            if ($intent === 'schedule_top1_later' && in_array($bucket, ['afternoon', 'evening', 'late_night'], true)) {
                $score += 120;
            }

            if ($intent === 'what_first' && ($signals['overdue_count'] > 0 || $signals['high_priority_unscheduled_count'] > 0)) {
                $score += 100;
            }

            if ($intent === 'schedule_most_important' && $signals['high_priority_unscheduled_count'] > 0) {
                $score += 90;
            }

            if ($intent === 'plan_today' && $signals['due_today_count'] > 0) {
                $score += 60;
            }

            if ($intent === 'reprioritize_remaining' && in_array($bucket, ['afternoon', 'evening'], true)) {
                $score += 70;
            }

            if ($intent === 'schedule_most_important' && $lastSourceFlow === 'prioritize') {
                $score += 40;
            }

            if ($intent === 'what_first' && $lastSourceFlow === 'schedule') {
                $score += 20;
            }

            $candidate['score'] = $score;
            $candidate['stable_order'] = $key;
        }
        unset($candidate);

        usort($candidates, function (array $left, array $right): int {
            $leftScore = (int) ($left['score'] ?? 0);
            $rightScore = (int) ($right['score'] ?? 0);

            if ($leftScore === $rightScore) {
                return (int) (($left['stable_order'] ?? 0) <=> ($right['stable_order'] ?? 0));
            }

            return $rightScore <=> $leftScore;
        });

        $labels = [];
        foreach ($candidates as $candidate) {
            $intent = (string) ($candidate['intent'] ?? '');
            if (! $this->isIntentAllowedForContext($intent, $bucket, $weekContext)) {
                continue;
            }

            $label = trim($this->resolveCandidateLabel($candidate));
            if ($label === '' || in_array($label, $labels, true)) {
                continue;
            }

            $labels[] = $label;

            if (count($labels) >= $limit) {
                break;
            }
        }

        return $labels !== [] ? $labels : $this->fallbackLabels($bucket, $limit);
    }

    /**
     * @param  array{intent?: string, label?: string, score?: int}  $candidate
     */
    private function resolveCandidateLabel(array $candidate): string
    {
        return (string) ($candidate['label'] ?? '');
    }

    private function isIntentAllowedForContext(string $intent, string $bucket, string $weekContext): bool
    {
        if ($intent === 'plan_today' && ! in_array($bucket, ['morning', 'afternoon'], true)) {
            return false;
        }

        if ($intent === 'plan_tomorrow' && ! in_array($bucket, ['evening', 'late_night'], true)) {
            return false;
        }

        if ($intent === 'schedule_top1_later' && ! in_array($bucket, ['afternoon', 'evening', 'late_night'], true)) {
            return false;
        }

        if ($intent === 'reprioritize_remaining' && ! in_array($bucket, ['afternoon', 'evening'], true)) {
            return false;
        }

        if ($intent === 'schedule_most_important' && $bucket === 'late_night') {
            return false;
        }

        if ($intent === 'plan_next_week' && ! in_array($weekContext, ['friday_evening', 'friday_late_night', 'saturday_evening', 'saturday_late_night'], true)) {
            return false;
        }

        if ($intent === 'plan_today' && in_array($weekContext, ['sunday_evening', 'sunday_late_night'], true)) {
            return false;
        }

        if ($intent === 'plan_tomorrow' && in_array($weekContext, ['friday_evening', 'friday_late_night', 'saturday_evening', 'saturday_late_night'], true)) {
            return false;
        }

        if ($intent === 'reprioritize_remaining' && in_array($weekContext, ['saturday_morning', 'sunday_morning'], true)) {
            return false;
        }

        return true;
    }

    private function resolveWeekContext(CarbonImmutable $now): string
    {
        $day = (int) $now->dayOfWeekIso;
        $bucket = $this->resolveTimeBucket($now);

        return match ($day) {
            5 => 'friday_'.$bucket,
            6 => 'saturday_'.$bucket,
            7 => 'sunday_'.$bucket,
            default => 'weekday_'.$bucket,
        };
    }

    /**
     * @return array<int, array{intent: string, label: string, score: int}>
     */
    private function baseCandidates(): array
    {
        return [
            ['intent' => 'focus_summary', 'label' => __('What should I focus on today'), 'score' => 22],
            ['intent' => 'plan_today', 'label' => __('Create a plan for today'), 'score' => 50],
            ['intent' => 'plan_tomorrow', 'label' => __('Create a plan for tomorrow'), 'score' => 50],
            ['intent' => 'plan_next_week', 'label' => __('Create a plan for next week'), 'score' => 45],
            ['intent' => 'schedule_top1_later', 'label' => __('Schedule top 1 for later'), 'score' => 50],
            ['intent' => 'schedule_most_important', 'label' => __('Schedule my most important task'), 'score' => 40],
            ['intent' => 'what_first', 'label' => __('What should I do first'), 'score' => 45],
            ['intent' => 'reprioritize_remaining', 'label' => __('Re-prioritize my remaining tasks'), 'score' => 35],
        ];
    }

    /**
     * @return array{overdue_count: int, due_today_count: int, high_priority_unscheduled_count: int}
     */
    private function collectTaskSignals(User $user, CarbonImmutable $now): array
    {
        $startOfDay = $now->startOfDay();
        $endOfDay = $now->endOfDay();

        $base = Task::query()
            ->where('user_id', $user->id)
            ->incomplete();

        return [
            'overdue_count' => (clone $base)
                ->whereNotNull('end_datetime')
                ->where('end_datetime', '<', $now)
                ->count(),
            'due_today_count' => (clone $base)
                ->whereNotNull('end_datetime')
                ->whereBetween('end_datetime', [$startOfDay, $endOfDay])
                ->count(),
            'high_priority_unscheduled_count' => (clone $base)
                ->whereNull('start_datetime')
                ->whereIn('priority', [TaskPriority::High->value, TaskPriority::Urgent->value])
                ->count(),
        ];
    }

    private function resolveTimeBucket(CarbonImmutable $now): string
    {
        $hour = (int) $now->format('G');

        return match (true) {
            $hour >= 5 && $hour <= 11 => 'morning',
            $hour >= 12 && $hour <= 16 => 'afternoon',
            $hour >= 17 && $hour <= 21 => 'evening',
            default => 'late_night',
        };
    }

    /**
     * @return array<int, string>
     */
    private function fallbackLabels(string $bucket, int $limit): array
    {
        $labels = match ($bucket) {
            'morning' => [
                __('Create a plan for today'),
                __('What should I do first'),
                __('Schedule my most important task'),
                __('Re-prioritize my remaining tasks'),
            ],
            'afternoon' => [
                __('Re-prioritize my remaining tasks'),
                __('Schedule top 1 for later'),
                __('What should I do first'),
                __('Create a plan for today'),
            ],
            'evening' => [
                __('Create a plan for tomorrow'),
                __('Schedule top 1 for later'),
                __('Re-prioritize my remaining tasks'),
                __('What should I do first'),
            ],
            default => [
                __('Create a plan for tomorrow'),
                __('Schedule top 1 for later'),
                __('What should I do first'),
                __('Re-prioritize my remaining tasks'),
            ],
        };

        return array_slice($labels, 0, $limit);
    }
}

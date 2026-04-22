<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\TaskAssistantThread;
use App\Models\User;
use Carbon\CarbonImmutable;

final class TaskAssistantQuickChipResolver
{
    /**
     * @return array<int, string>
     */
    public function resolveForPostScheduleAccept(User $user, ?TaskAssistantThread $thread = null, int $limit = 3): array
    {
        $limit = max(1, min(4, $limit));
        $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'));
        $bucket = $this->resolveTimeBucket($now);

        return $this->postScheduleLabels($bucket, $limit);
    }

    /**
     * @return array<int, string>
     */
    public function resolveForEmptyState(User $user, ?TaskAssistantThread $thread = null, int $limit = 4): array
    {
        $limit = max(1, min(6, $limit));
        $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'));
        $bucket = $this->resolveTimeBucket($now);

        return $this->fallbackLabels($bucket, $limit);
    }

    /**
     * @param  array<int, string>  $chips
     * @return array<int, string>
     */
    public function filterContinueStyleQuickChips(array $chips): array
    {
        return array_values(array_filter(
            $chips,
            static function (string $chip): bool {
                $t = mb_strtolower(trim($chip));
                if ($t === '') {
                    return false;
                }
                if (preg_match('/^continue\\b/u', $t) === 1) {
                    return false;
                }
                if (preg_match('/\\bcontinue\\b.*\\b(draft|pending\\s+schedule)\\b/u', $t) === 1) {
                    return false;
                }

                return ! str_contains($t, 'continue with this draft');
            }
        ));
    }

    /**
     * @return array<int, string>
     */
    private function postScheduleLabels(string $bucket, int $limit): array
    {
        $labels = match ($bucket) {
            'morning', 'afternoon' => [
                __('Show my next 3 priorities'),
                __('Create a plan for today'),
                __('Schedule my most important task'),
                __('Plan tomorrow for me'),
            ],
            'evening', 'late_night' => [
                __('Plan tomorrow for me'),
                __('Show my next 3 priorities'),
                __('Schedule my most important task'),
                __('Create a plan for today'),
            ],
            default => [
                __('Show my next 3 priorities'),
                __('Schedule my most important task'),
                __('Create a plan for today'),
                __('Plan tomorrow for me'),
            ],
        };

        return array_slice($labels, 0, $limit);
    }

    private function resolveTimeBucket(CarbonImmutable $now): string
    {
        $hour = (int) $now->format('G');

        return match (true) {
            $hour >= 0 && $hour <= 5 => 'midnight',
            $hour >= 6 && $hour <= 11 => 'morning',
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
                __('What are my top 3 tasks'),
                __('Schedule my most important task'),
                __('What should I focus on today'),
            ],
            'afternoon' => [
                __('Schedule top 1 for later'),
                __('What are my top 3 tasks'),
                __('Create a plan for today'),
                __('What should I focus on today'),
            ],
            'evening' => [
                __('Create a plan for tomorrow'),
                __('Schedule top 1 for later'),
                __('What are my top 3 tasks'),
                __('What should I focus on today'),
            ],
            'midnight' => [
                __('Schedule top 1 for later'),
                __('What are my top 3 tasks'),
                __('What should I focus on today'),
            ],
            default => [
                __('Create a plan for tomorrow'),
                __('What are my top 3 tasks'),
                __('What should I focus on today'),
            ],
        };

        return array_slice($labels, 0, $limit);
    }
}

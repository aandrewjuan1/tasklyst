<?php

namespace App\Services;

use App\Data\Analytics\DashboardAnalyticsOverview;
use App\Data\Analytics\DashboardAnalyticsPeriod;
use App\Data\Analytics\UserAnalyticsOverview;
use App\Enums\TaskComplexity;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\FocusSession;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class UserAnalyticsService
{
    public function dashboardOverview(User $user, string $preset, ?CarbonInterface $anchor = null): DashboardAnalyticsOverview
    {
        $period = DashboardAnalyticsPeriod::fromPreset($preset, $anchor);

        $currentCompletedCount = $this->tasksCompletedBaseQuery($user, $period->currentStart, $period->currentEnd)->count();
        $previousCompletedCount = $this->tasksCompletedBaseQuery($user, $period->previousStart, $period->previousEnd)->count();

        $currentCreatedCount = $this->tasksCreatedBaseQuery($user, $period->currentStart, $period->currentEnd)->count();
        $previousCreatedCount = $this->tasksCreatedBaseQuery($user, $period->previousStart, $period->previousEnd)->count();

        $currentFocusBase = $this->focusWorkBaseQuery($user, $period->currentStart, $period->currentEnd);
        $previousFocusBase = $this->focusWorkBaseQuery($user, $period->previousStart, $period->previousEnd);

        $currentFocusWorkSecondsTotal = (int) (clone $currentFocusBase)->sum('duration_seconds');
        $previousFocusWorkSecondsTotal = (int) (clone $previousFocusBase)->sum('duration_seconds');
        $currentFocusWorkSessionsCount = (int) (clone $currentFocusBase)->count();
        $previousFocusWorkSessionsCount = (int) (clone $previousFocusBase)->count();

        $currentOverdueCount = $this->overdueCount($user, $period->currentEnd);
        $previousOverdueCount = $this->overdueCount($user, $period->previousEnd);
        $currentDueSoonCount = $this->dueSoonCount($user, $period->currentEnd, 7);
        $previousDueSoonCount = $this->dueSoonCount($user, $period->previousEnd, 7);

        $cards = [
            'tasks_created' => $this->card($currentCreatedCount, $previousCreatedCount),
            'tasks_completed' => $this->card($currentCompletedCount, $previousCompletedCount),
            'completion_rate' => $this->card(
                $this->completionRate($currentCompletedCount, $currentCreatedCount),
                $this->completionRate($previousCompletedCount, $previousCreatedCount)
            ),
            'overdue' => $this->card($currentOverdueCount, $previousOverdueCount),
            'due_soon' => $this->card($currentDueSoonCount, $previousDueSoonCount),
            'focus_work_seconds' => $this->card($currentFocusWorkSecondsTotal, $previousFocusWorkSecondsTotal),
            'focus_sessions' => $this->card($currentFocusWorkSessionsCount, $previousFocusWorkSessionsCount),
        ];

        $dailyCompleted = $this->tasksCompletedByDay($user, $period->currentStart, $period->currentEnd);
        $dailyFocusWorkSeconds = $this->focusWorkSecondsByDay($user, $period->currentStart, $period->currentEnd);
        $labels = $this->dateLabelsInPeriod($period->currentStart, $period->currentEnd);

        $trends = [
            'labels' => $labels,
            'tasks_completed' => array_map(
                fn (string $label): int => (int) ($dailyCompleted[$label] ?? 0),
                $labels
            ),
            'focus_work_seconds' => array_map(
                fn (string $label): int => (int) ($dailyFocusWorkSeconds[$label] ?? 0),
                $labels
            ),
        ];

        $breakdowns = [
            'status' => $this->statusBreakdown($user, $period->currentStart, $period->currentEnd),
            'priority' => $this->priorityBreakdown($user, $period->currentStart, $period->currentEnd),
            'complexity' => $this->complexityBreakdown($user, $period->currentStart, $period->currentEnd),
            'project' => $this->projectBreakdown($user, $period->currentStart, $period->currentEnd),
        ];

        return new DashboardAnalyticsOverview(
            preset: $period->preset,
            periodStart: $period->currentStart,
            periodEnd: $period->currentEnd,
            previousPeriodStart: $period->previousStart,
            previousPeriodEnd: $period->previousEnd,
            cards: $cards,
            trends: $trends,
            breakdowns: $breakdowns,
        );
    }

    /**
     * Aggregate analytics for the user over an inclusive calendar period in {@see config('app.timezone')}.
     *
     * @throws InvalidArgumentException When the normalized start is after the normalized end.
     */
    public function overview(User $user, CarbonInterface $start, CarbonInterface $end): UserAnalyticsOverview
    {
        [$periodStart, $periodEnd] = $this->normalizePeriod($start, $end);

        $tasksCompletedCount = $this->tasksCompletedBaseQuery($user, $periodStart, $periodEnd)->count();

        $tasksCreatedCount = Task::query()
            ->forUser($user->id)
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->count();

        $focusBase = FocusSession::query()
            ->forUser($user->id)
            ->work()
            ->completed()
            ->whereBetween('started_at', [$periodStart, $periodEnd]);

        $focusWorkSecondsTotal = (int) (clone $focusBase)->sum('duration_seconds');
        $focusWorkSessionsCount = (int) (clone $focusBase)->count();

        $tasksCompletedByDay = $this->tasksCompletedByDay($user, $periodStart, $periodEnd);
        $focusWorkSecondsByDay = $this->focusWorkSecondsByDay($user, $periodStart, $periodEnd);
        $tasksCompletedByProjectId = $this->tasksCompletedByProjectId($user, $periodStart, $periodEnd);

        return new UserAnalyticsOverview(
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            tasksCompletedCount: $tasksCompletedCount,
            tasksCreatedCount: $tasksCreatedCount,
            focusWorkSecondsTotal: $focusWorkSecondsTotal,
            focusWorkSessionsCount: $focusWorkSessionsCount,
            tasksCompletedByDay: $tasksCompletedByDay,
            focusWorkSecondsByDay: $focusWorkSecondsByDay,
            tasksCompletedByProjectId: $tasksCompletedByProjectId,
        );
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function normalizePeriod(CarbonInterface $start, CarbonInterface $end): array
    {
        $timezone = (string) config('app.timezone');

        $periodStart = CarbonImmutable::parse($start)->timezone($timezone)->startOfDay();
        $periodEnd = CarbonImmutable::parse($end)->timezone($timezone)->endOfDay();

        if ($periodStart->greaterThan($periodEnd)) {
            throw new InvalidArgumentException('Analytics period start must be on or before the period end.');
        }

        return [$periodStart, $periodEnd];
    }

    /**
     * @return Builder<Task>
     */
    private function tasksCompletedBaseQuery(User $user, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): Builder
    {
        return Task::query()
            ->forUser($user->id)
            ->withTrashed()
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$periodStart, $periodEnd]);
    }

    /**
     * @return Builder<Task>
     */
    private function tasksCreatedBaseQuery(User $user, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): Builder
    {
        return Task::query()
            ->forUser($user->id)
            ->whereBetween('created_at', [$periodStart, $periodEnd]);
    }

    /**
     * @return Builder<FocusSession>
     */
    private function focusWorkBaseQuery(User $user, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): Builder
    {
        return FocusSession::query()
            ->forUser($user->id)
            ->work()
            ->completed()
            ->whereBetween('started_at', [$periodStart, $periodEnd]);
    }

    /**
     * Bucket by calendar date in {@see config('app.timezone')} so boundaries match the normalized period.
     *
     * @return array<string, int>
     */
    private function tasksCompletedByDay(User $user, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $timezone = (string) config('app.timezone');

        $tasks = $this->tasksCompletedBaseQuery($user, $periodStart, $periodEnd)
            ->get(['completed_at']);

        $map = [];
        foreach ($tasks as $task) {
            $day = $task->completed_at->timezone($timezone)->format('Y-m-d');
            $map[$day] = ($map[$day] ?? 0) + 1;
        }
        ksort($map);

        return $map;
    }

    /**
     * @return array<string, int>
     */
    private function focusWorkSecondsByDay(User $user, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $timezone = (string) config('app.timezone');

        $sessions = $this->focusWorkBaseQuery($user, $periodStart, $periodEnd)
            ->get(['started_at', 'duration_seconds']);

        $map = [];
        foreach ($sessions as $session) {
            $day = $session->started_at->timezone($timezone)->format('Y-m-d');
            $map[$day] = ($map[$day] ?? 0) + (int) $session->duration_seconds;
        }
        ksort($map);

        return $map;
    }

    /**
     * @return array<string, int>
     */
    private function tasksCompletedByProjectId(User $user, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $rows = $this->tasksCompletedBaseQuery($user, $periodStart, $periodEnd)
            ->selectRaw('project_id, COUNT(*) as c')
            ->groupBy('project_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $key = $row->project_id === null ? 'none' : (string) $row->project_id;
            $map[$key] = (int) $row->c;
        }

        return $map;
    }

    private function overdueCount(User $user, CarbonImmutable $asOf): int
    {
        return Task::query()
            ->forUser($user->id)
            ->whereNull('completed_at')
            ->overdue($asOf)
            ->count();
    }

    private function dueSoonCount(User $user, CarbonImmutable $asOf, int $days): int
    {
        return Task::query()
            ->forUser($user->id)
            ->whereNull('completed_at')
            ->dueSoon($asOf->copy()->startOfDay(), $days)
            ->count();
    }

    private function completionRate(int $completed, int $created): float
    {
        if ($created === 0) {
            return 0.0;
        }

        return round(($completed / $created) * 100, 2);
    }

    /**
     * @return array{current: int|float, previous: int|float, delta: int|float, delta_percentage: int|float|null}
     */
    private function card(int|float $current, int|float $previous): array
    {
        $delta = $current - $previous;
        $deltaPercentage = $previous == 0
            ? null
            : round(($delta / $previous) * 100, 2);

        return [
            'current' => $current,
            'previous' => $previous,
            'delta' => $delta,
            'delta_percentage' => $deltaPercentage,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function dateLabelsInPeriod(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $labels = [];
        $cursor = $start->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $labels[] = $cursor->format('Y-m-d');
            $cursor = $cursor->addDay();
        }

        return $labels;
    }

    /**
     * @return array<int, array{key: string, label: string, value: int}>
     */
    private function statusBreakdown(User $user, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $counts = $this->tasksCreatedBaseQuery($user, $periodStart, $periodEnd)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return collect(TaskStatus::cases())
            ->map(function (TaskStatus $status) use ($counts): array {
                return [
                    'key' => $status->value,
                    'label' => $status->label(),
                    'value' => (int) ($counts[$status->value] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{key: string, label: string, value: int}>
     */
    private function priorityBreakdown(User $user, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $counts = $this->tasksCreatedBaseQuery($user, $periodStart, $periodEnd)
            ->selectRaw('priority, COUNT(*) as c')
            ->groupBy('priority')
            ->pluck('c', 'priority');

        return collect(TaskPriority::cases())
            ->map(function (TaskPriority $priority) use ($counts): array {
                return [
                    'key' => $priority->value,
                    'label' => $priority->label(),
                    'value' => (int) ($counts[$priority->value] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{key: string, label: string, value: int}>
     */
    private function complexityBreakdown(User $user, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $counts = $this->tasksCreatedBaseQuery($user, $periodStart, $periodEnd)
            ->selectRaw('complexity, COUNT(*) as c')
            ->groupBy('complexity')
            ->pluck('c', 'complexity');

        return collect(TaskComplexity::cases())
            ->map(function (TaskComplexity $complexity) use ($counts): array {
                return [
                    'key' => $complexity->value,
                    'label' => $complexity->label(),
                    'value' => (int) ($counts[$complexity->value] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{key: string, label: string, value: int}>
     */
    private function projectBreakdown(User $user, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $countsByProject = $this->tasksCompletedByProjectId($user, $periodStart, $periodEnd);
        $projectIds = collect(array_keys($countsByProject))
            ->reject(fn (string $key): bool => $key === 'none')
            ->map(fn (string $key): int => (int) $key)
            ->values()
            ->all();

        /** @var Collection<int, string> $projectNames */
        $projectNames = Project::query()
            ->whereIn('id', $projectIds)
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name]);

        $projectRows = collect($countsByProject)
            ->map(function (int $value, string $key) use ($projectNames): array {
                return [
                    'key' => $key,
                    'label' => $key === 'none' ? __('No Project') : (string) ($projectNames->get($key) ?? __('Unknown Project')),
                    'value' => $value,
                ];
            })
            ->sortByDesc('value')
            ->values();

        return $projectRows->all();
    }
}

<?php

namespace App\Services;

use App\Data\Analytics\UserAnalyticsOverview;
use App\Models\FocusSession;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

class UserAnalyticsService
{
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

        $sessions = FocusSession::query()
            ->forUser($user->id)
            ->work()
            ->completed()
            ->whereBetween('started_at', [$periodStart, $periodEnd])
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
}

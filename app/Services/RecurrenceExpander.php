<?php

namespace App\Services;

use App\Models\EventException;
use App\Models\RecurringEvent;
use App\Models\RecurringTask;
use App\Models\TaskException;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RecurrenceExpander
{
    /**
     * Expand a recurring task or event into concrete dates within the given range.
     * Respects start_datetime and end_datetime on the recurring record.
     *
     * @param  Collection<int, TaskException|EventException>|null  $preloadedExceptions  When provided, uses these instead of querying (avoids N+1 in batch).
     * @return array<CarbonInterface>
     */
    public function expand(RecurringTask|RecurringEvent $recurring, CarbonInterface $start, CarbonInterface $end, ?Collection $preloadedExceptions = null): array
    {
        $recurrenceType = $recurring->recurrence_type;
        $interval = max(1, (int) $recurring->interval);
        $recurringStart = $recurring->start_datetime ? Carbon::parse($recurring->start_datetime) : null;
        $recurringEnd = $recurring->end_datetime ? Carbon::parse($recurring->end_datetime) : null;

        $effectiveStart = $recurringStart
            ? Carbon::parse($start)->max($recurringStart->copy()->startOfDay())
            : Carbon::parse($start);
        $effectiveEnd = $recurringEnd
            ? Carbon::parse($end)->min($recurringEnd->copy()->endOfDay())
            : Carbon::parse($end);

        if ($effectiveStart->gt($effectiveEnd)) {
            return [];
        }

        $dates = match ($recurrenceType->value) {
            'daily' => $this->expandDaily($recurring, $effectiveStart, $effectiveEnd, $interval),
            'weekly' => $this->expandWeekly($recurring, $effectiveStart, $effectiveEnd, $interval),
            'monthly' => $this->expandMonthly($recurring, $effectiveStart, $effectiveEnd, $interval),
            'yearly' => $this->expandYearly($recurring, $effectiveStart, $effectiveEnd, $interval),
            default => [],
        };

        return $this->applyExceptions($recurring, $dates, $effectiveStart, $effectiveEnd, $start, $end, $preloadedExceptions);
    }

    /**
     * Apply TaskException/EventException: exclude deleted dates, replace with replacement instance dates.
     *
     * @param  array<CarbonInterface>  $dates
     * @param  Collection<int, TaskException|EventException>|null  $preloadedExceptions
     * @return array<CarbonInterface>
     */
    private function applyExceptions(
        RecurringTask|RecurringEvent $recurring,
        array $dates,
        Carbon $effectiveStart,
        Carbon $effectiveEnd,
        CarbonInterface $start,
        CarbonInterface $end,
        ?Collection $preloadedExceptions = null
    ): array {
        $exceptions = $preloadedExceptions ?? $this->loadExceptionsForRecurring($recurring, $start, $end);

        $excludedDates = $exceptions
            ->filter(fn (TaskException|EventException $e) => $e->is_deleted || $e->replacement_instance_id !== null)
            ->map(fn (TaskException|EventException $e) => $e->exception_date->format('Y-m-d'))
            ->flip()
            ->all();

        $replacementDates = $exceptions
            ->filter(fn (TaskException|EventException $e) => $e->replacement_instance_id !== null)
            ->map(fn (TaskException|EventException $e) => $e->replacementInstance?->instance_date)
            ->filter()
            ->map(fn ($d) => Carbon::parse($d))
            ->filter(fn (Carbon $d) => $d->gte($effectiveStart) && $d->lte($effectiveEnd))
            ->all();

        $result = [];
        foreach ($dates as $date) {
            $dateStr = $date->format('Y-m-d');
            if (isset($excludedDates[$dateStr])) {
                continue;
            }
            $result[] = $date;
        }

        foreach ($replacementDates as $replacementDate) {
            $result[] = $replacementDate;
        }

        usort($result, fn ($a, $b) => $a->format('Y-m-d') <=> $b->format('Y-m-d'));

        return array_values(array_unique($result, SORT_REGULAR));
    }

    /**
     * Load exceptions for a single recurring item (used when not batching).
     *
     * @return Collection<int, TaskException|EventException>
     */
    private function loadExceptionsForRecurring(RecurringTask|RecurringEvent $recurring, CarbonInterface $start, CarbonInterface $end): Collection
    {
        $query = $recurring instanceof RecurringTask
            ? $recurring->taskExceptions()
            : $recurring->eventExceptions();

        return $query
            ->where(function ($q) use ($start, $end): void {
                $q->whereBetween('exception_date', [$start, $end])
                    ->orWhereHas('replacementInstance', fn ($r) => $r->whereBetween('instance_date', [$start, $end]));
            })
            ->with('replacementInstance')
            ->get();
    }

    /**
     * Whether the preloaded exceptions collection contains an exclusion for the given date (skip or replacement).
     *
     * @param  Collection<int, TaskException|EventException>|null  $exceptions
     */
    private function dateHasExcludedException(?Collection $exceptions, string $dateStr): bool
    {
        if ($exceptions === null || $exceptions->isEmpty()) {
            return false;
        }

        return $exceptions->contains(fn (TaskException|EventException $e) => $e->exception_date->format('Y-m-d') === $dateStr
            && ($e->is_deleted || $e->replacement_instance_id !== null)
        );
    }

    /**
     * Batch-expand recurring tasks and events to determine which are relevant for a given date.
     * Preloads exceptions in bulk to avoid N+1 queries.
     *
     * @param  iterable<RecurringTask>  $recurringTasks
     * @param  iterable<RecurringEvent>  $recurringEvents
     * @return array{task_ids: array<int>, event_ids: array<int>}
     */
    public function getRelevantRecurringIdsForDate(iterable $recurringTasks, iterable $recurringEvents, CarbonInterface $date): array
    {
        $taskIds = [];
        $eventIds = [];

        $recurringTasks = collect($recurringTasks)->filter()->values();
        $recurringEvents = collect($recurringEvents)->filter()->values();

        $taskExceptionMap = $this->preloadTaskExceptions($recurringTasks->pluck('id'), $date, $date);
        $eventExceptionMap = $this->preloadEventExceptions($recurringEvents->pluck('id'), $date, $date);

        $dateStr = $date->format('Y-m-d');

        foreach ($recurringTasks as $recurring) {
            if ($recurring->start_datetime === null && $recurring->end_datetime === null) {
                if (! $this->dateHasExcludedException($taskExceptionMap[$recurring->id] ?? null, $dateStr)) {
                    $taskIds[] = $recurring->id;
                }

                continue;
            }
            $occurrences = $this->expand($recurring, $date, $date, $taskExceptionMap[$recurring->id] ?? null);
            if (collect($occurrences)->contains(fn ($d) => $d->format('Y-m-d') === $dateStr)) {
                $taskIds[] = $recurring->id;
            }
        }

        foreach ($recurringEvents as $recurring) {
            if ($recurring->start_datetime === null && $recurring->end_datetime === null) {
                if (! $this->dateHasExcludedException($eventExceptionMap[$recurring->id] ?? null, $dateStr)) {
                    $eventIds[] = $recurring->id;
                }

                continue;
            }
            $occurrences = $this->expand($recurring, $date, $date, $eventExceptionMap[$recurring->id] ?? null);
            if (collect($occurrences)->contains(fn ($d) => $d->format('Y-m-d') === $dateStr)) {
                $eventIds[] = $recurring->id;
            }
        }

        return ['task_ids' => $taskIds, 'event_ids' => $eventIds];
    }

    /**
     * Preload TaskExceptions for multiple recurring tasks in one query.
     *
     * @return array<int, Collection<int, TaskException>>
     */
    private function preloadTaskExceptions(Collection $recurringTaskIds, CarbonInterface $start, CarbonInterface $end): array
    {
        if ($recurringTaskIds->isEmpty()) {
            return [];
        }

        $exceptions = TaskException::query()
            ->whereIn('recurring_task_id', $recurringTaskIds)
            ->where(function ($q) use ($start, $end): void {
                $q->whereBetween('exception_date', [$start, $end])
                    ->orWhereHas('replacementInstance', fn ($r) => $r->whereBetween('instance_date', [$start, $end]));
            })
            ->with('replacementInstance')
            ->get();

        return $exceptions->groupBy('recurring_task_id')->all();
    }

    /**
     * Preload EventExceptions for multiple recurring events in one query.
     *
     * @return array<int, Collection<int, EventException>>
     */
    private function preloadEventExceptions(Collection $recurringEventIds, CarbonInterface $start, CarbonInterface $end): array
    {
        if ($recurringEventIds->isEmpty()) {
            return [];
        }

        $exceptions = EventException::query()
            ->whereIn('recurring_event_id', $recurringEventIds)
            ->where(function ($q) use ($start, $end): void {
                $q->whereBetween('exception_date', [$start, $end])
                    ->orWhereHas('replacementInstance', fn ($r) => $r->whereBetween('instance_date', [$start, $end]));
            })
            ->with('replacementInstance')
            ->get();

        return $exceptions->groupBy('recurring_event_id')->all();
    }

    /**
     * @return array<CarbonInterface>
     */
    private function expandDaily(RecurringTask|RecurringEvent $recurring, Carbon $effectiveStart, Carbon $effectiveEnd, int $interval): array
    {
        $dates = [];
        $recurringStart = Carbon::parse($recurring->start_datetime)->startOfDay();

        $current = $recurringStart->copy();
        while ($current->lte($effectiveEnd)) {
            if ($current->gte($effectiveStart)) {
                $dates[] = $current->copy();
            }
            $current->addDays($interval);
        }

        return $dates;
    }

    /**
     * @return array<CarbonInterface>
     */
    private function expandWeekly(RecurringTask|RecurringEvent $recurring, Carbon $effectiveStart, Carbon $effectiveEnd, int $interval): array
    {
        $daysOfWeek = $this->parseDaysOfWeek($recurring->days_of_week);
        if (empty($daysOfWeek)) {
            $daysOfWeek = [Carbon::parse($recurring->start_datetime)->dayOfWeek];
        }

        $dates = [];
        $recurringStart = Carbon::parse($recurring->start_datetime)->startOfDay();

        $weekStart = $recurringStart->copy()->startOfWeek(Carbon::MONDAY);
        $currentWeekStart = $weekStart->copy();

        while ($currentWeekStart->lte($effectiveEnd)) {
            foreach ($daysOfWeek as $dayOfWeek) {
                // Week starts Monday (0). Sunday=0 -> offset 6, Monday=1 -> offset 0, etc.
                $offset = $dayOfWeek === 0 ? 6 : $dayOfWeek - 1;
                $candidate = $currentWeekStart->copy()->addDays($offset);
                if ($candidate->gte($recurringStart) && $candidate->lte($effectiveEnd) && $candidate->gte($effectiveStart)) {
                    $dates[] = $candidate->copy();
                }
            }
            $currentWeekStart->addWeeks($interval);
        }

        return $dates;
    }

    /**
     * @return array<CarbonInterface>
     */
    private function expandMonthly(RecurringTask|RecurringEvent $recurring, Carbon $effectiveStart, Carbon $effectiveEnd, int $interval): array
    {
        $dates = [];
        $recurringStart = Carbon::parse($recurring->start_datetime);
        $dayOfMonth = $recurringStart->day;

        $current = $recurringStart->copy()->startOfMonth();

        while ($current->lte($effectiveEnd)) {
            $candidate = $current->copy()->day(min($dayOfMonth, $current->daysInMonth));
            if ($candidate->gte($recurringStart) && $candidate->lte($effectiveEnd) && $candidate->gte($effectiveStart)) {
                $dates[] = $candidate->copy();
            }
            $current->addMonthsNoOverflow($interval);
        }

        return $dates;
    }

    /**
     * @return array<CarbonInterface>
     */
    private function expandYearly(RecurringTask|RecurringEvent $recurring, Carbon $effectiveStart, Carbon $effectiveEnd, int $interval): array
    {
        $dates = [];
        $recurringStart = Carbon::parse($recurring->start_datetime);
        $month = $recurringStart->month;
        $dayOfMonth = $recurringStart->day;

        $current = $recurringStart->copy()->startOfYear();

        while ($current->lte($effectiveEnd)) {
            $candidate = $current->copy()->month($month)->day(min($dayOfMonth, $current->copy()->month($month)->daysInMonth));
            if ($candidate->gte($recurringStart) && $candidate->lte($effectiveEnd) && $candidate->gte($effectiveStart)) {
                $dates[] = $candidate->copy();
            }
            $current->addYears($interval);
        }

        return $dates;
    }

    /**
     * @return array<int>
     */
    private function parseDaysOfWeek(?string $daysOfWeek): array
    {
        if ($daysOfWeek === null || $daysOfWeek === '') {
            return [];
        }

        $decoded = json_decode($daysOfWeek, true);

        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }
}

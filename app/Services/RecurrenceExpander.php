<?php

namespace App\Services;

use App\Models\EventException;
use App\Models\RecurringEvent;
use App\Models\RecurringTask;
use App\Models\TaskException;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class RecurrenceExpander
{
    /**
     * Expand a recurring task or event into concrete dates within the given range.
     * Respects start_datetime and end_datetime on the recurring record.
     *
     * @return array<CarbonInterface>
     */
    public function expand(RecurringTask|RecurringEvent $recurring, CarbonInterface $start, CarbonInterface $end): array
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

        return $this->applyExceptions($recurring, $dates, $effectiveStart, $effectiveEnd, $start, $end);
    }

    /**
     * Apply TaskException/EventException: exclude deleted dates, replace with replacement instance dates.
     *
     * @param  array<CarbonInterface>  $dates
     * @return array<CarbonInterface>
     */
    private function applyExceptions(
        RecurringTask|RecurringEvent $recurring,
        array $dates,
        Carbon $effectiveStart,
        Carbon $effectiveEnd,
        CarbonInterface $start,
        CarbonInterface $end
    ): array {
        $exceptions = $recurring instanceof RecurringTask
            ? $recurring->taskExceptions()
            : $recurring->eventExceptions();

        $exceptions = $exceptions
            ->where(function ($q) use ($start, $end): void {
                $q->whereBetween('exception_date', [$start, $end])
                    ->orWhereHas('replacementInstance', fn ($r) => $r->whereBetween('instance_date', [$start, $end]));
            })
            ->with('replacementInstance')
            ->get();

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
                $candidate = $currentWeekStart->copy()->addDays($dayOfWeek - 1);
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

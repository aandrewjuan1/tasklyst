<?php

namespace App\Livewire\Concerns;

use App\Enums\TaskPriority;
use App\Models\Event;
use App\Models\Task;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

trait HandlesWorkspaceCalendar
{
    private const CALENDAR_META_MAX_ITEMS = 400;

    private const SELECTED_DAY_AGENDA_TASK_LIMIT = 120;

    private const SELECTED_DAY_AGENDA_EVENT_LIMIT = 120;

    /**
     * When set together, the sidebar calendar grid shows this month/year without changing the workspace selected date.
     * Cleared whenever the selected date changes so the grid follows the active day again.
     */
    public ?int $calendarViewYear = null;

    public ?int $calendarViewMonth = null;

    /**
     * Latest month meta for the calendar grid, mirrored for Alpine after {@see browseCalendarMonth()}.
     * (Blade still receives calendarMonthMeta via computed; this keeps $wire and Alpine in sync.)
     *
     * @var array<string, array<string, int>>
     */
    public array $calendarGridMetaForJs = [];

    public function navigateSelectedDate(int $offsetDays): void
    {
        $this->selectedDate = $this->getParsedSelectedDate()->copy()->addDays($offsetDays)->toDateString();
    }

    /**
     * Move the calendar grid to another month without updating the workspace selected date.
     */
    public function browseCalendarMonth(int $delta): void
    {
        $year = $this->getCalendarGridYear();
        $month = $this->getCalendarGridMonth();

        $next = $this->getParsedSelectedDate()
            ->copy()
            ->setDate($year, $month, 1)
            ->addMonths($delta);

        $this->calendarViewYear = (int) $next->year;
        $this->calendarViewMonth = (int) $next->month;

        $this->calendarGridMetaForJs = $this->calendarMonthMeta;
    }

    protected function resetCalendarViewForSelectedDateChange(): void
    {
        $this->calendarViewYear = null;
        $this->calendarViewMonth = null;
        $this->calendarGridMetaForJs = [];
    }

    protected function getCalendarGridMonth(): int
    {
        if ($this->calendarViewYear !== null && $this->calendarViewMonth !== null) {
            return $this->calendarViewMonth;
        }

        return $this->getParsedSelectedDate()->month;
    }

    protected function getCalendarGridYear(): int
    {
        if ($this->calendarViewYear !== null && $this->calendarViewMonth !== null) {
            return $this->calendarViewYear;
        }

        return $this->getParsedSelectedDate()->year;
    }

    #[Computed]
    public function calendarMonth(): int
    {
        return $this->getCalendarGridMonth();
    }

    #[Computed]
    public function calendarYear(): int
    {
        return $this->getCalendarGridYear();
    }

    /**
     * @return array<string, array{
     *   task_count:int,
     *   overdue_count:int,
     *   due_count:int,
     *   urgent_count:int,
     *   event_count:int,
     *   conflict_count:int,
     *   recurring_count:int,
     *   all_day_count:int
     * }>
     */
    #[Computed]
    public function calendarMonthMeta(): array
    {
        $userId = Auth::id();
        if ($userId === null) {
            return [];
        }

        $monthStart = $this->getParsedSelectedDate()
            ->copy()
            ->setDate($this->getCalendarGridYear(), $this->getCalendarGridMonth(), 1)
            ->startOfMonth();
        $gridStart = $monthStart->copy()->startOfWeek();
        $gridEnd = $monthStart->copy()->endOfMonth()->endOfWeek();

        /** @var array<string, array{task_count:int,overdue_count:int,due_count:int,urgent_count:int,event_count:int,conflict_count:int,recurring_count:int,all_day_count:int}> $meta */
        $meta = [];
        for ($cursor = $gridStart->copy(); $cursor->lte($gridEnd); $cursor->addDay()) {
            $meta[$cursor->toDateString()] = [
                'task_count' => 0,
                'overdue_count' => 0,
                'due_count' => 0,
                'urgent_count' => 0,
                'event_count' => 0,
                'conflict_count' => 0,
                'recurring_count' => 0,
                'all_day_count' => 0,
            ];
        }

        $gridStartAt = $gridStart->copy()->startOfDay();
        $gridEndAt = $gridEnd->copy()->endOfDay();
        $now = now();

        $tasks = Task::query()
            ->forUser($userId)
            ->incomplete()
            ->with('recurringTask')
            ->whereNotNull('end_datetime')
            ->whereBetween('end_datetime', [$gridStartAt, $gridEndAt])
            ->orderBy('end_datetime')
            ->limit(self::CALENDAR_META_MAX_ITEMS)
            ->get(['id', 'priority', 'end_datetime']);

        foreach ($tasks as $task) {
            $key = $task->end_datetime?->toDateString();
            if ($key === null || ! array_key_exists($key, $meta)) {
                continue;
            }

            $meta[$key]['task_count']++;
            $meta[$key]['due_count']++;

            if (in_array($task->priority?->value, [TaskPriority::Urgent->value, TaskPriority::High->value], true)) {
                $meta[$key]['urgent_count']++;
            }

            if ($task->end_datetime !== null && $task->end_datetime->lt($now)) {
                $meta[$key]['overdue_count']++;
            }

            if ($task->recurringTask !== null) {
                $meta[$key]['recurring_count']++;
            }
        }

        $events = Event::query()
            ->forUser($userId)
            ->notCancelled()
            ->notCompleted()
            ->with('recurringEvent')
            ->whereNotNull('start_datetime')
            ->whereBetween('start_datetime', [$gridStartAt, $gridEndAt])
            ->orderBy('start_datetime')
            ->limit(self::CALENDAR_META_MAX_ITEMS)
            ->get(['id', 'all_day', 'start_datetime', 'end_datetime']);

        /** @var array<string, array<int, array{start:\Carbon\Carbon,end:\Carbon\Carbon}>> $eventWindowsPerDay */
        $eventWindowsPerDay = [];

        foreach ($events as $event) {
            $key = $event->start_datetime?->toDateString();
            if ($key === null || ! array_key_exists($key, $meta)) {
                continue;
            }

            $meta[$key]['event_count']++;

            if ($event->recurringEvent !== null) {
                $meta[$key]['recurring_count']++;
            }

            if ($event->all_day) {
                $meta[$key]['all_day_count']++;

                continue;
            }

            $start = $event->start_datetime?->copy();
            $end = $event->end_datetime?->copy() ?? $event->start_datetime?->copy()->addHour();
            if ($start === null || $end === null) {
                continue;
            }

            $eventWindowsPerDay[$key][] = ['start' => $start, 'end' => $end];
        }

        foreach ($eventWindowsPerDay as $date => $windows) {
            usort($windows, static fn (array $left, array $right): int => $left['start']->getTimestamp() <=> $right['start']->getTimestamp());
            $conflicts = 0;
            for ($i = 1; $i < count($windows); $i++) {
                if ($windows[$i]['start']->lt($windows[$i - 1]['end'])) {
                    $conflicts++;
                }
            }
            if (array_key_exists($date, $meta)) {
                $meta[$date]['conflict_count'] = $conflicts;
            }
        }

        return $meta;
    }

    /**
     * @return array{
     *   date:string,
     *   summary:array{tasks:int,events:int,conflicts:int,overdue:int},
     *   urgentTasks:array<int, array{id:int,title:string,time:string,priority:string,workspace_url:string}>,
     *   timedEvents:array<int, array{id:int,title:string,time:string,workspace_url:string}>,
     *   allDayEvents:array<int, array{id:int,title:string,workspace_url:string}>,
     *   carryoverTasks:array<int, array{id:int,title:string,time:string,workspace_url:string}>
     * }
     */
    #[Computed]
    public function selectedDayAgenda(): array
    {
        $userId = Auth::id();
        $selectedDate = $this->getParsedSelectedDate()->copy()->startOfDay();
        $start = $selectedDate->copy()->startOfDay();
        $end = $selectedDate->copy()->endOfDay();

        if ($userId === null) {
            return [
                'date' => $selectedDate->toDateString(),
                'summary' => ['tasks' => 0, 'events' => 0, 'conflicts' => 0, 'overdue' => 0],
                'urgentTasks' => [],
                'timedEvents' => [],
                'allDayEvents' => [],
                'carryoverTasks' => [],
            ];
        }

        $tasks = Task::query()
            ->forUser($userId)
            ->incomplete()
            ->where(function ($query) use ($start, $end): void {
                $query->whereBetween('start_datetime', [$start, $end])
                    ->orWhereBetween('end_datetime', [$start, $end])
                    ->orWhere(function ($overlap) use ($start, $end): void {
                        $overlap->whereNotNull('start_datetime')
                            ->whereNotNull('end_datetime')
                            ->where('start_datetime', '<=', $start)
                            ->where('end_datetime', '>=', $end);
                    });
            })
            ->orderByPriority()
            ->orderBy('end_datetime')
            ->limit(self::SELECTED_DAY_AGENDA_TASK_LIMIT)
            ->get(['id', 'title', 'priority', 'start_datetime', 'end_datetime']);

        $urgentTasks = $tasks
            ->filter(function (Task $task) use ($selectedDate): bool {
                return in_array($task->priority?->value, [TaskPriority::Urgent->value, TaskPriority::High->value], true)
                    && $task->end_datetime !== null
                    && $task->end_datetime->isSameDay($selectedDate);
            })
            ->map(fn (Task $task): array => [
                'id' => $task->id,
                'title' => (string) $task->title,
                'time' => $task->end_datetime?->translatedFormat('H:i') ?? __('No time'),
                'priority' => (string) ($task->priority?->value ?? 'medium'),
                'workspace_url' => route('workspace', [
                    'date' => $selectedDate->toDateString(),
                    'type' => 'tasks',
                    'q' => $task->title,
                ]),
            ])
            ->values()
            ->all();

        $carryoverTasks = $tasks
            ->filter(function (Task $task) use ($selectedDate): bool {
                if ($task->start_datetime === null || $task->end_datetime === null) {
                    return false;
                }

                return $task->start_datetime->lt($selectedDate->copy()->startOfDay())
                    && $task->end_datetime->gt($selectedDate->copy()->endOfDay());
            })
            ->map(fn (Task $task): array => [
                'id' => $task->id,
                'title' => (string) $task->title,
                'time' => __('Until :time', ['time' => $task->end_datetime?->translatedFormat('H:i') ?? __('No time')]),
                'workspace_url' => route('workspace', [
                    'date' => $selectedDate->toDateString(),
                    'type' => 'tasks',
                    'q' => $task->title,
                ]),
            ])
            ->values()
            ->all();

        $events = Event::query()
            ->forUser($userId)
            ->notCancelled()
            ->notCompleted()
            ->where(function ($query) use ($start, $end): void {
                $query->whereBetween('start_datetime', [$start, $end])
                    ->orWhereBetween('end_datetime', [$start, $end])
                    ->orWhere(function ($overlap) use ($start, $end): void {
                        $overlap->whereNotNull('start_datetime')
                            ->whereNotNull('end_datetime')
                            ->where('start_datetime', '<=', $start)
                            ->where('end_datetime', '>=', $end);
                    });
            })
            ->orderBy('start_datetime')
            ->limit(self::SELECTED_DAY_AGENDA_EVENT_LIMIT)
            ->get(['id', 'title', 'all_day', 'start_datetime', 'end_datetime']);

        $timedEvents = $events
            ->where('all_day', false)
            ->map(fn (Event $event): array => [
                'id' => $event->id,
                'title' => (string) $event->title,
                'time' => $event->start_datetime
                    ? $event->start_datetime->translatedFormat('H:i').' - '.($event->end_datetime?->translatedFormat('H:i') ?? __('No end'))
                    : __('No time'),
                'workspace_url' => route('workspace', [
                    'date' => $selectedDate->toDateString(),
                    'type' => 'events',
                    'q' => $event->title,
                ]),
            ])
            ->values()
            ->all();

        $allDayEvents = $events
            ->where('all_day', true)
            ->map(fn (Event $event): array => [
                'id' => $event->id,
                'title' => (string) $event->title,
                'workspace_url' => route('workspace', [
                    'date' => $selectedDate->toDateString(),
                    'type' => 'events',
                    'q' => $event->title,
                ]),
            ])
            ->values()
            ->all();

        $overdueCount = $tasks
            ->filter(fn (Task $task): bool => $task->end_datetime !== null && $task->end_datetime->lt(now()))
            ->count();

        $conflictCount = 0;
        $timedCollection = $events->where('all_day', false)->values();
        for ($i = 1; $i < $timedCollection->count(); $i++) {
            $previous = $timedCollection[$i - 1];
            $current = $timedCollection[$i];
            if ($current->start_datetime !== null && $previous->end_datetime !== null && $current->start_datetime->lt($previous->end_datetime)) {
                $conflictCount++;
            }
        }

        return [
            'date' => $selectedDate->toDateString(),
            'summary' => [
                'tasks' => $tasks->count(),
                'events' => $events->count(),
                'conflicts' => $conflictCount,
                'overdue' => $overdueCount,
            ],
            'urgentTasks' => $urgentTasks,
            'timedEvents' => $timedEvents,
            'allDayEvents' => $allDayEvents,
            'carryoverTasks' => $carryoverTasks,
        ];
    }

    abstract protected function getParsedSelectedDate(): CarbonInterface;
}

<?php

namespace App\Livewire\Concerns;

use App\Models\Event;
use App\Models\SchoolClass;
use App\Models\Task;
use App\Services\SchoolClassService;
use App\Support\WorkspaceAgendaFocusUrl;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

trait HandlesWorkspaceCalendar
{
    private const CALENDAR_META_MAX_ITEMS = 400;

    private const SELECTED_DAY_AGENDA_TASK_LIMIT = 120;

    private const SELECTED_DAY_AGENDA_EVENT_LIMIT = 120;

    private const SELECTED_DAY_AGENDA_SCHOOL_CLASS_LIMIT = 120;

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
     * Set the workspace/dashboard selected day to today and snap the grid to the month that contains today.
     * Clears month-only navigation from {@see browseCalendarMonth()} even when the selected day was already today.
     */
    public function jumpCalendarToToday(): void
    {
        $today = now()->toDateString();

        if ($this->selectedDate !== $today) {
            $this->selectedDate = $today;
        } else {
            $this->resetCalendarViewForSelectedDateChange();
        }

        $this->calendarGridMetaForJs = $this->calendarMonthMeta;
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

    /**
     * Recompute sidebar calendar month meta and selected-day agenda from the database.
     * Used after creates (same response) and as the target of {@see queueWorkspaceCalendarRefresh()}
     * following renderless updates so the calendar DOM can morph.
     */
    public function refreshWorkspaceCalendar(): void
    {
        unset($this->calendarMonthMeta, $this->selectedDayAgenda, $this->calendarMonth, $this->calendarYear);
        $this->calendarGridMetaForJs = $this->calendarMonthMeta;
    }

    /**
     * After a renderless action mutates tasks/events, queue a follow-up request that
     * re-renders calendar state (dots + agenda panel).
     */
    protected function queueWorkspaceCalendarRefresh(): void
    {
        $this->js('$wire.refreshWorkspaceCalendar()');
    }

    protected function resetCalendarViewForSelectedDateChange(): void
    {
        $this->calendarViewYear = null;
        $this->calendarViewMonth = null;
        unset($this->calendarMonthMeta, $this->calendarMonth, $this->calendarYear);
        $this->calendarGridMetaForJs = $this->calendarMonthMeta;
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
     *   task_starts_count:int,
     *   event_count:int,
     *   conflict_count:int,
     *   recurring_count:int,
     *   all_day_count:int,
     *   school_class_count:int
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

        /** @var array<string, array{task_count:int,overdue_count:int,due_count:int,task_starts_count:int,event_count:int,conflict_count:int,recurring_count:int,all_day_count:int,school_class_count:int}> $meta */
        $meta = [];
        for ($cursor = $gridStart->copy(); $cursor->lte($gridEnd); $cursor->addDay()) {
            $meta[$cursor->toDateString()] = [
                'task_count' => 0,
                'overdue_count' => 0,
                'due_count' => 0,
                'task_starts_count' => 0,
                'event_count' => 0,
                'conflict_count' => 0,
                'recurring_count' => 0,
                'all_day_count' => 0,
                'school_class_count' => 0,
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

        $tasksStartingInGrid = Task::query()
            ->forUser($userId)
            ->incomplete()
            ->whereNotNull('start_datetime')
            ->whereBetween('start_datetime', [$gridStartAt, $gridEndAt])
            ->orderBy('start_datetime')
            ->limit(self::CALENDAR_META_MAX_ITEMS)
            ->get(['id', 'start_datetime']);

        foreach ($tasksStartingInGrid as $task) {
            $key = $task->start_datetime?->toDateString();
            if ($key === null || ! array_key_exists($key, $meta)) {
                continue;
            }

            $meta[$key]['task_starts_count']++;
        }

        $schoolClassService = app(SchoolClassService::class);
        $schoolClassesForMeta = SchoolClass::query()
            ->forUser($userId)
            ->notArchived()
            ->with(['recurringSchoolClass'])
            ->orderBy('subject_name')
            ->limit(self::CALENDAR_META_MAX_ITEMS)
            ->get();

        foreach (array_keys($meta) as $dateString) {
            $dayCarbon = \Illuminate\Support\Carbon::parse($dateString)->startOfDay();
            $meta[$dateString]['school_class_count'] = $schoolClassService->countSchoolClassesOnCalendarDay(
                $schoolClassesForMeta,
                $dayCarbon,
                $dayCarbon->copy()->endOfDay()
            );
        }

        return $meta;
    }

    /**
     * When true (e.g. Dashboard), agenda workspace URLs omit the "Show" type filter and pass {@see agendaWorkspaceFocusQueryParam}
     * so the workspace opens with date/view/focus scroll only, matching in-app calendar focus behavior.
     */
    protected function omitTypeFilterOnCalendarAgendaWorkspaceLinks(): bool
    {
        return false;
    }

    /**
     * Query param marking agenda navigation: focus and paginate without forcing filter shell or clearing search.
     */
    protected function agendaWorkspaceFocusQueryParam(): string
    {
        return 'agenda_focus';
    }

    /**
     * Open workspace with list view, selected date, {@see agendaWorkspaceFocusQueryParam}, and optional entity focus.
     * Does not set the "Show" type filter — same contract as dashboard calendar agenda links and in-app calendar focus.
     *
     * @param  'task'|'event'|'project'|'school_class'  $entityType
     */
    public function workspaceRouteForAgendaStyleFocus(string $date, string $entityType, int $entityId): string
    {
        return WorkspaceAgendaFocusUrl::workspaceRouteForAgendaStyleFocus($date, $entityType, $entityId);
    }

    /**
     * Deep-link payload for calendar agenda rows (matches dashboard workspace card URLs).
     *
     * @return array{focus_kind: 'task'|'event'|'schoolClass', focus_id: int, workspace_url: string}
     */
    protected function agendaWorkspaceDeepLink(CarbonInterface $selectedDate, string $kind, int $id): array
    {
        $date = $selectedDate->toDateString();

        if ($kind === 'task') {
            if ($this->omitTypeFilterOnCalendarAgendaWorkspaceLinks()) {
                return [
                    'focus_kind' => 'task',
                    'focus_id' => $id,
                    'workspace_url' => $this->workspaceRouteForAgendaStyleFocus($date, 'task', $id),
                ];
            }

            return [
                'focus_kind' => 'task',
                'focus_id' => $id,
                'workspace_url' => route('workspace', [
                    'date' => $date,
                    'view' => 'list',
                    'type' => 'tasks',
                    'task' => $id,
                ]),
            ];
        }

        if ($kind === 'event') {
            if ($this->omitTypeFilterOnCalendarAgendaWorkspaceLinks()) {
                return [
                    'focus_kind' => 'event',
                    'focus_id' => $id,
                    'workspace_url' => $this->workspaceRouteForAgendaStyleFocus($date, 'event', $id),
                ];
            }

            return [
                'focus_kind' => 'event',
                'focus_id' => $id,
                'workspace_url' => route('workspace', [
                    'date' => $date,
                    'view' => 'list',
                    'type' => 'events',
                    'event' => $id,
                ]),
            ];
        }

        if ($kind === 'schoolClass') {
            if ($this->omitTypeFilterOnCalendarAgendaWorkspaceLinks()) {
                return [
                    'focus_kind' => 'schoolClass',
                    'focus_id' => $id,
                    'workspace_url' => $this->workspaceRouteForAgendaStyleFocus($date, 'school_class', $id),
                ];
            }

            return [
                'focus_kind' => 'schoolClass',
                'focus_id' => $id,
                'workspace_url' => route('workspace', [
                    'date' => $date,
                    'view' => 'list',
                    'type' => 'classes',
                    'school_class' => $id,
                ]),
            ];
        }

        throw new \InvalidArgumentException('Unsupported agenda focus kind: '.$kind);
    }

    /**
     * 12-hour clock for calendar sidebar agenda rows (avoids 24-hour “military” times).
     */
    private function formatCalendarAgendaClock(CarbonInterface $dateTime): string
    {
        return $dateTime->translatedFormat('g:i A');
    }

    /**
     * Overdue row: show clock only when due on the selected day; otherwise weekday, date, and 12-hour time.
     */
    private function formatCalendarAgendaOverdueEnd(CarbonInterface $endDateTime, CarbonInterface $selectedStartOfDay): string
    {
        return $endDateTime->isSameDay($selectedStartOfDay)
            ? $this->formatCalendarAgendaClock($endDateTime)
            : $endDateTime->translatedFormat('D j M, g:i A');
    }

    private function formatCalendarAgendaTimeRange(CarbonInterface $start, ?CarbonInterface $end): string
    {
        if ($end === null) {
            return $this->formatCalendarAgendaClock($start).' - '.__('No end');
        }

        return $this->formatCalendarAgendaClock($start).' - '.$this->formatCalendarAgendaClock($end);
    }

    private function formatCalendarAgendaSchoolClassTimeRange(SchoolClass $class, CarbonInterface $selectedDate): string
    {
        if ($class->start_datetime !== null && $class->end_datetime !== null) {
            return $this->formatCalendarAgendaTimeRange($class->start_datetime, $class->end_datetime);
        }

        if (filled($class->start_time)) {
            try {
                $start = $selectedDate->copy()->setTimeFromTimeString((string) $class->start_time);
                $end = filled($class->end_time)
                    ? $selectedDate->copy()->setTimeFromTimeString((string) $class->end_time)
                    : null;

                return $this->formatCalendarAgendaTimeRange($start, $end);
            } catch (\Throwable) {
                // Fall through to the UX fallback for malformed legacy time values.
            }
        }

        return __('No time');
    }

    /**
     * Build selected-day agenda from the same item collections that power the visible workspace list/kanban.
     * This keeps calendar counters and empty-state behavior aligned with what the user currently sees.
     *
     * @return array{
     *   date:string,
     *   summary:array{tasks:int,events:int,overdue:int,classes:int},
     *   overdueTasks:array<int, array{id:int,title:string,time:string,time_label:string,focus_kind:'task',focus_id:int,workspace_url:string}>,
     *   dueDayTasks:array<int, array{id:int,title:string,time:string,time_label:string,focus_kind:'task',focus_id:int,workspace_url:string}>,
     *   scheduledStarts:array<int, array{title:string,time:string,time_label:string,focus_kind:'task'|'event',focus_id:int,workspace_url:string}>,
     *   timedEvents:array<int, array{id:int,title:string,time:string,time_label:string,focus_kind:'event',focus_id:int,workspace_url:string}>,
     *   allDayEvents:array<int, array{id:int,title:string,time_label:string,time:?string,focus_kind:'event',focus_id:int,workspace_url:string}>,
     *   schoolClasses:array<int, array{id:int,title:string,time:string,time_label:string,focus_kind:'schoolClass',focus_id:int,workspace_url:string}>
     * }
     */
    private function selectedDayAgendaFromWorkspaceVisibleCollections(CarbonInterface $selectedDate): array
    {
        $selectedDay = $selectedDate->copy()->startOfDay();

        $viewMode = property_exists($this, 'viewMode') ? (string) ($this->viewMode ?? 'list') : 'list';
        $isKanban = $viewMode === 'kanban';

        $taskItems = collect();
        $eventItems = collect();
        $schoolClassItems = collect();

        if ($isKanban) {
            /** @var Collection<int, Task> $tasksFromKanban */
            $tasksFromKanban = collect($this->tasks ?? []);
            /** @var Collection<int, array{kind:string,item:mixed}> $overdueRows */
            $overdueRows = collect($this->overdue ?? []);
            $overdueKanbanTasks = $overdueRows
                ->filter(static fn (array $row): bool => ($row['kind'] ?? null) === 'task' && isset($row['item']) && $row['item'] instanceof Task)
                ->map(static fn (array $row): Task => $row['item']);

            $taskItems = $tasksFromKanban
                ->merge($overdueKanbanTasks)
                ->unique(static fn (Task $task): int => (int) $task->id)
                ->values();
        } else {
            /** @var Collection<int, array{kind:string,item:mixed,isOverdue:bool}> $allEntries */
            $allEntries = method_exists($this, 'getAllListEntries')
                ? $this->getAllListEntries()
                : collect();
            $itemsPerPage = (int) (property_exists($this, 'itemsPerPage') ? $this->itemsPerPage : 10);
            $itemsPage = (int) (property_exists($this, 'itemsPage') ? $this->itemsPage : 1);
            $maxVisibleItems = max(1, $itemsPerPage) * max(1, $itemsPage);
            $visibleEntries = $allEntries->take($maxVisibleItems)->values();

            $taskItems = $visibleEntries
                ->filter(static fn (array $entry): bool => ($entry['kind'] ?? null) === 'task' && isset($entry['item']) && $entry['item'] instanceof Task)
                ->map(static fn (array $entry): Task => $entry['item'])
                ->unique(static fn (Task $task): int => (int) $task->id)
                ->values();

            $eventItems = $visibleEntries
                ->filter(static fn (array $entry): bool => ($entry['kind'] ?? null) === 'event' && isset($entry['item']) && $entry['item'] instanceof Event)
                ->map(static fn (array $entry): Event => $entry['item'])
                ->unique(static fn (Event $event): int => (int) $event->id)
                ->values();

            $schoolClassItems = $visibleEntries
                ->filter(static fn (array $entry): bool => ($entry['kind'] ?? null) === 'schoolClass' && isset($entry['item']) && $entry['item'] instanceof SchoolClass)
                ->map(static fn (array $entry): SchoolClass => $entry['item'])
                ->unique(static fn (SchoolClass $class): int => (int) $class->id)
                ->values();
        }

        $taskRows = $taskItems
            ->map(function (Task $task) use ($selectedDay): array {
                $link = $this->agendaWorkspaceDeepLink($selectedDay, 'task', $task->id);
                $isOverdue = $task->end_datetime !== null && $task->end_datetime->lt(now());
                $isDueOnSelectedDay = $task->end_datetime !== null && $task->end_datetime->isSameDay($selectedDay);
                $startsOnSelectedDay = $task->start_datetime !== null && $task->start_datetime->isSameDay($selectedDay);

                return [
                    'task' => $task,
                    'is_overdue' => $isOverdue,
                    'is_due_day' => ! $isOverdue && $isDueOnSelectedDay,
                    'starts_on_selected_day' => $startsOnSelectedDay,
                    'focus_kind' => $link['focus_kind'],
                    'focus_id' => $link['focus_id'],
                    'workspace_url' => $link['workspace_url'],
                ];
            })
            ->values();

        $overdueTasks = $taskRows
            ->filter(static fn (array $row): bool => $row['is_overdue'] === true)
            ->map(function (array $row) use ($selectedDay): array {
                /** @var Task $task */
                $task = $row['task'];

                return [
                    'id' => $task->id,
                    'title' => (string) $task->title,
                    'time_label' => __('Due'),
                    'time' => $task->end_datetime !== null
                        ? $this->formatCalendarAgendaOverdueEnd($task->end_datetime, $selectedDay)
                        : __('No time'),
                    'focus_kind' => $row['focus_kind'],
                    'focus_id' => $row['focus_id'],
                    'workspace_url' => $row['workspace_url'],
                ];
            })
            ->values()
            ->all();

        $dueDayTasks = $taskRows
            ->filter(static fn (array $row): bool => $row['is_due_day'] === true)
            ->map(function (array $row): array {
                /** @var Task $task */
                $task = $row['task'];

                return [
                    'id' => $task->id,
                    'title' => (string) $task->title,
                    'time_label' => __('Due'),
                    'time' => $task->end_datetime !== null
                        ? $this->formatCalendarAgendaClock($task->end_datetime)
                        : __('No time'),
                    'focus_kind' => $row['focus_kind'],
                    'focus_id' => $row['focus_id'],
                    'workspace_url' => $row['workspace_url'],
                ];
            })
            ->values()
            ->all();

        $scheduledStartsRows = $taskRows
            ->filter(static fn (array $row): bool => $row['starts_on_selected_day'] === true)
            ->map(function (array $row): array {
                /** @var Task $task */
                $task = $row['task'];

                return [
                    'sort' => $task->start_datetime?->getTimestamp() ?? PHP_INT_MAX,
                    'title' => (string) $task->title,
                    'time_label' => __('Starts'),
                    'time' => $task->start_datetime !== null
                        ? $this->formatCalendarAgendaClock($task->start_datetime)
                        : __('No time'),
                    'focus_kind' => $row['focus_kind'],
                    'focus_id' => $row['focus_id'],
                    'workspace_url' => $row['workspace_url'],
                ];
            })
            ->values()
            ->all();

        $startsSameDay = fn (Event $event): bool => $event->start_datetime !== null
            && $event->start_datetime->isSameDay($selectedDay);

        foreach ($eventItems as $event) {
            if (! $event instanceof Event) {
                continue;
            }

            $time = $event->all_day
                ? __('All day')
                : ($event->start_datetime !== null
                    ? $this->formatCalendarAgendaTimeRange($event->start_datetime, $event->end_datetime)
                    : __('No time'));

            $link = $this->agendaWorkspaceDeepLink($selectedDay, 'event', $event->id);

            if ($startsSameDay($event)) {
                $scheduledStartsRows[] = [
                    'sort' => $event->start_datetime?->getTimestamp() ?? PHP_INT_MAX,
                    'title' => (string) $event->title,
                    'time_label' => __('Event'),
                    'time' => $time,
                    'focus_kind' => $link['focus_kind'],
                    'focus_id' => $link['focus_id'],
                    'workspace_url' => $link['workspace_url'],
                ];
            }
        }

        usort($scheduledStartsRows, static fn (array $left, array $right): int => $left['sort'] <=> $right['sort']);

        $scheduledStarts = array_values(array_map(static fn (array $row): array => [
            'title' => $row['title'],
            'time_label' => $row['time_label'],
            'time' => $row['time'],
            'focus_kind' => $row['focus_kind'],
            'focus_id' => $row['focus_id'],
            'workspace_url' => $row['workspace_url'],
        ], $scheduledStartsRows));

        $timedEvents = $eventItems
            ->filter(fn (Event $event): bool => ! $event->all_day && ! $startsSameDay($event))
            ->map(function (Event $event) use ($selectedDay): array {
                $link = $this->agendaWorkspaceDeepLink($selectedDay, 'event', $event->id);

                return [
                    'id' => $event->id,
                    'title' => (string) $event->title,
                    'time_label' => __('Ongoing'),
                    'time' => $event->start_datetime !== null
                        ? $this->formatCalendarAgendaTimeRange($event->start_datetime, $event->end_datetime)
                        : __('No time'),
                    'focus_kind' => $link['focus_kind'],
                    'focus_id' => $link['focus_id'],
                    'workspace_url' => $link['workspace_url'],
                ];
            })
            ->values()
            ->all();

        $allDayEvents = $eventItems
            ->filter(fn (Event $event): bool => $event->all_day && ! $startsSameDay($event))
            ->map(function (Event $event) use ($selectedDay): array {
                $link = $this->agendaWorkspaceDeepLink($selectedDay, 'event', $event->id);

                return [
                    'id' => $event->id,
                    'title' => (string) $event->title,
                    'time_label' => __('All day'),
                    'time' => null,
                    'focus_kind' => $link['focus_kind'],
                    'focus_id' => $link['focus_id'],
                    'workspace_url' => $link['workspace_url'],
                ];
            })
            ->values()
            ->all();

        $schoolClassesAgenda = $schoolClassItems
            ->map(function (SchoolClass $class) use ($selectedDay): array {
                $link = $this->agendaWorkspaceDeepLink($selectedDay, 'schoolClass', $class->id);

                return [
                    'id' => $class->id,
                    'title' => (string) $class->subject_name,
                    'time_label' => __('Class'),
                    'time' => $this->formatCalendarAgendaSchoolClassTimeRange($class, $selectedDay),
                    'focus_kind' => $link['focus_kind'],
                    'focus_id' => $link['focus_id'],
                    'workspace_url' => $link['workspace_url'],
                ];
            })
            ->values()
            ->all();

        return [
            'date' => $selectedDay->toDateString(),
            'summary' => [
                'tasks' => $taskItems->count(),
                'events' => $isKanban ? 0 : $eventItems->count(),
                'overdue' => count($overdueTasks),
                'classes' => $isKanban ? 0 : count($schoolClassesAgenda),
            ],
            'overdueTasks' => $overdueTasks,
            'dueDayTasks' => $dueDayTasks,
            'scheduledStarts' => $scheduledStarts,
            'timedEvents' => $isKanban ? [] : $timedEvents,
            'allDayEvents' => $isKanban ? [] : $allDayEvents,
            'schoolClasses' => $isKanban ? [] : $schoolClassesAgenda,
        ];
    }

    /**
     * @return array{
     *   date:string,
     *   summary:array{tasks:int,events:int,overdue:int,classes:int},
     *   overdueTasks:array<int, array{id:int,title:string,time:string,time_label:string,focus_kind:'task',focus_id:int,workspace_url:string}>,
     *   dueDayTasks:array<int, array{id:int,title:string,time:string,time_label:string,focus_kind:'task',focus_id:int,workspace_url:string}>,
     *   scheduledStarts:array<int, array{title:string,time:string,time_label:string,focus_kind:'task'|'event',focus_id:int,workspace_url:string}>,
     *   timedEvents:array<int, array{id:int,title:string,time:string,time_label:string,focus_kind:'event',focus_id:int,workspace_url:string}>,
     *   allDayEvents:array<int, array{id:int,title:string,time_label:string,time:?string,focus_kind:'event',focus_id:int,workspace_url:string}>,
     *   schoolClasses:array<int, array{id:int,title:string,time:string,time_label:string,focus_kind:'schoolClass',focus_id:int,workspace_url:string}>
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
                'summary' => ['tasks' => 0, 'events' => 0, 'overdue' => 0, 'classes' => 0],
                'overdueTasks' => [],
                'dueDayTasks' => [],
                'scheduledStarts' => [],
                'timedEvents' => [],
                'allDayEvents' => [],
                'schoolClasses' => [],
            ];
        }

        $isWorkspaceViewSyncedAgenda = property_exists($this, 'viewMode')
            && in_array((string) ($this->viewMode ?? ''), ['list', 'kanban'], true)
            && method_exists($this, 'getAllListEntries');

        if ($isWorkspaceViewSyncedAgenda) {
            return $this->selectedDayAgendaFromWorkspaceVisibleCollections($selectedDate);
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

        $overdueTaskModels = $tasks->filter(
            fn (Task $task): bool => $task->end_datetime !== null && $task->end_datetime->lt(now())
        );

        $overdueIds = $overdueTaskModels->pluck('id')->all();

        $overdueTasks = $overdueTaskModels
            ->map(function (Task $task) use ($selectedDate): array {
                $link = $this->agendaWorkspaceDeepLink($selectedDate, 'task', $task->id);

                return [
                    'id' => $task->id,
                    'title' => (string) $task->title,
                    'time_label' => __('Due'),
                    'time' => $task->end_datetime !== null
                        ? $this->formatCalendarAgendaOverdueEnd($task->end_datetime, $selectedDate)
                        : __('No time'),
                    'focus_kind' => $link['focus_kind'],
                    'focus_id' => $link['focus_id'],
                    'workspace_url' => $link['workspace_url'],
                ];
            })
            ->values()
            ->all();

        $dueDayTasks = $tasks
            ->filter(function (Task $task) use ($selectedDate, $overdueIds): bool {
                if (in_array($task->id, $overdueIds, true)) {
                    return false;
                }

                return $task->end_datetime !== null
                    && $task->end_datetime->isSameDay($selectedDate);
            })
            ->map(function (Task $task) use ($selectedDate): array {
                $link = $this->agendaWorkspaceDeepLink($selectedDate, 'task', $task->id);

                return [
                    'id' => $task->id,
                    'title' => (string) $task->title,
                    'time_label' => __('Due'),
                    'time' => $task->end_datetime !== null
                        ? $this->formatCalendarAgendaClock($task->end_datetime)
                        : __('No time'),
                    'focus_kind' => $link['focus_kind'],
                    'focus_id' => $link['focus_id'],
                    'workspace_url' => $link['workspace_url'],
                ];
            })
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

        $startsSameDay = fn (Event $event): bool => $event->start_datetime !== null
            && $event->start_datetime->isSameDay($selectedDate);

        $scheduledStartsRows = [];

        foreach ($tasks as $task) {
            if ($task->start_datetime === null || ! $task->start_datetime->isSameDay($selectedDate)) {
                continue;
            }

            $link = $this->agendaWorkspaceDeepLink($selectedDate, 'task', $task->id);

            $scheduledStartsRows[] = [
                'sort' => $task->start_datetime->getTimestamp(),
                'title' => (string) $task->title,
                'time_label' => __('Starts'),
                'time' => $this->formatCalendarAgendaClock($task->start_datetime),
                'focus_kind' => $link['focus_kind'],
                'focus_id' => $link['focus_id'],
                'workspace_url' => $link['workspace_url'],
            ];
        }

        foreach ($events as $event) {
            if (! $event instanceof Event) {
                continue;
            }

            if (! $startsSameDay($event)) {
                continue;
            }

            $time = $event->all_day
                ? __('All day')
                : ($event->start_datetime !== null
                    ? $this->formatCalendarAgendaTimeRange($event->start_datetime, $event->end_datetime)
                    : __('No time'));

            $link = $this->agendaWorkspaceDeepLink($selectedDate, 'event', $event->id);

            $scheduledStartsRows[] = [
                'sort' => $event->start_datetime?->getTimestamp() ?? 0,
                'title' => (string) $event->title,
                'time_label' => __('Event'),
                'time' => $time,
                'focus_kind' => $link['focus_kind'],
                'focus_id' => $link['focus_id'],
                'workspace_url' => $link['workspace_url'],
            ];
        }

        usort($scheduledStartsRows, static fn (array $left, array $right): int => $left['sort'] <=> $right['sort']);

        $scheduledStarts = array_values(array_map(static fn (array $row): array => [
            'title' => $row['title'],
            'time_label' => $row['time_label'],
            'time' => $row['time'],
            'focus_kind' => $row['focus_kind'],
            'focus_id' => $row['focus_id'],
            'workspace_url' => $row['workspace_url'],
        ], $scheduledStartsRows));

        $timedEvents = $events
            ->where('all_day', false)
            ->filter(fn (Event $event): bool => ! $startsSameDay($event))
            ->map(function (Event $event) use ($selectedDate): array {
                $link = $this->agendaWorkspaceDeepLink($selectedDate, 'event', $event->id);

                return [
                    'id' => $event->id,
                    'title' => (string) $event->title,
                    'time_label' => __('Ongoing'),
                    'time' => $event->start_datetime !== null
                        ? $this->formatCalendarAgendaTimeRange($event->start_datetime, $event->end_datetime)
                        : __('No time'),
                    'focus_kind' => $link['focus_kind'],
                    'focus_id' => $link['focus_id'],
                    'workspace_url' => $link['workspace_url'],
                ];
            })
            ->values()
            ->all();

        $allDayEvents = $events
            ->where('all_day', true)
            ->filter(fn (Event $event): bool => ! $startsSameDay($event))
            ->map(function (Event $event) use ($selectedDate): array {
                $link = $this->agendaWorkspaceDeepLink($selectedDate, 'event', $event->id);

                return [
                    'id' => $event->id,
                    'title' => (string) $event->title,
                    'time_label' => __('All day'),
                    'time' => null,
                    'focus_kind' => $link['focus_kind'],
                    'focus_id' => $link['focus_id'],
                    'workspace_url' => $link['workspace_url'],
                ];
            })
            ->values()
            ->all();

        $schoolClassService = app(SchoolClassService::class);
        $schoolClassesForAgenda = SchoolClass::query()
            ->forUser($userId)
            ->notArchived()
            ->with(['recurringSchoolClass'])
            ->orderBy('start_time')
            ->orderBy('subject_name')
            ->limit(self::SELECTED_DAY_AGENDA_SCHOOL_CLASS_LIMIT)
            ->get();

        $classesForSelectedDay = $schoolClassService->filterSchoolClassesForCalendarDay(
            $schoolClassesForAgenda,
            $start,
            $end
        );

        $schoolClassesAgenda = $classesForSelectedDay
            ->map(function (SchoolClass $class) use ($selectedDate): array {
                $link = $this->agendaWorkspaceDeepLink($selectedDate, 'schoolClass', $class->id);

                return [
                    'id' => $class->id,
                    'title' => (string) $class->subject_name,
                    'time_label' => __('Class'),
                    'time' => $this->formatCalendarAgendaSchoolClassTimeRange($class, $selectedDate),
                    'focus_kind' => $link['focus_kind'],
                    'focus_id' => $link['focus_id'],
                    'workspace_url' => $link['workspace_url'],
                ];
            })
            ->values()
            ->all();

        $overdueCount = count($overdueIds);

        return [
            'date' => $selectedDate->toDateString(),
            'summary' => [
                'tasks' => $tasks->count(),
                'events' => $events->count(),
                'overdue' => $overdueCount,
                'classes' => count($schoolClassesAgenda),
            ],
            'overdueTasks' => $overdueTasks,
            'dueDayTasks' => $dueDayTasks,
            'scheduledStarts' => $scheduledStarts,
            'timedEvents' => $timedEvents,
            'allDayEvents' => $allDayEvents,
            'schoolClasses' => $schoolClassesAgenda,
        ];
    }

    abstract protected function getParsedSelectedDate(): CarbonInterface;
}

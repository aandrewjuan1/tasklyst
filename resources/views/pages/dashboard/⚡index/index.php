<?php

use App\Data\Analytics\DashboardAnalyticsOverview;
use App\Enums\AssistantSchedulePlanItemStatus;
use App\Enums\ActivityLogAction;
use App\Enums\TaskSourceType;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\HandlesCalendarFeeds;
use App\Livewire\Concerns\HandlesWorkspaceCalendar;
use App\Models\ActivityLog;
use App\Models\AssistantSchedulePlanItem;
use App\Models\CalendarFeed;
use App\Models\Collaboration;
use App\Models\CollaborationInvitation;
use App\Models\Event;
use App\Models\FocusSession;
use App\Models\Project;
use App\Models\SchoolClass;
use App\Models\Task;
use App\Models\User;
use App\Services\LLM\Prioritization\AssistantCandidateProvider;
use App\Services\LLM\Prioritization\TaskPrioritizationService;
use App\Services\TaskService;
use App\Services\UserAnalyticsService;
use App\Services\SchoolClassService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Title('Dashboard')]
class extends Component
{
    use AuthorizesRequests;
    use HandlesCalendarFeeds;
    use HandlesWorkspaceCalendar;

    /*
     * At-a-glance panels (KPIs, lists, project health, focus throughput, insights charts)
     * load from the database on each request so workspace edits show on the next visit.
     * Livewire Computed still memoizes within a single request.
     */
    private const ANALYTICS_PRESETS = ['daily', 'weekly', 'monthly'];

    private const AT_A_GLANCE_LIMIT = 7;

    /** Visible rows in compact list cards before “See all” (Doing, Recurring, No-date, Classes). */
    private const DASHBOARD_LIST_CARD_ROW_LIMIT = 3;

    /** Maximum rows shown in the Urgent Now panel before “See all”. */
    private const URGENT_NOW_DISPLAY_LIMIT = self::DASHBOARD_LIST_CARD_ROW_LIMIT;

    /** Ranked items loaded; one past display limit signals additional items exist. */
    private const URGENT_NOW_PREVIEW_LIMIT = 4;

    private const URGENT_NOW_SOON_WINDOW_HOURS = 72;

    private const PROJECT_HEALTH_LIMIT = 5;

    private const COLLAB_ACTIVITY_LIMIT = 6;

    private const COLLAB_INVITES_LIMIT = 5;

    private const FEED_HEALTH_LIMIT = 5;

    /** Rows shown in the No-date Backlog panel before “See all”. */
    private const NO_DATE_BACKLOG_DISPLAY_LIMIT = self::DASHBOARD_LIST_CARD_ROW_LIMIT;

    private const RECURRING_DUE_DISPLAY_LIMIT = self::DASHBOARD_LIST_CARD_ROW_LIMIT;

    private const LLM_RECENT_THREADS_LIMIT = 5;

    private const TODAY_SCHOOL_CLASSES_DISPLAY_LIMIT = self::DASHBOARD_LIST_CARD_ROW_LIMIT;

    #[Url(as: 'date')]
    public ?string $selectedDate = null;

    #[Url(as: 'preset')]
    public string $analyticsPreset = 'daily';

    public string $trendPreset = 'daily';

    public bool $insightsOpen = false;
    public bool $insightsChartsReady = false;

    /**
     * Cached parsed date to avoid parsing multiple times.
     * Cleared when selectedDate changes.
     */
    protected ?CarbonInterface $parsedSelectedDate = null;

    protected UserAnalyticsService $userAnalyticsService;

    protected TaskService $taskService;

    protected SchoolClassService $schoolClassService;

    protected AssistantCandidateProvider $assistantCandidateProvider;

    protected TaskPrioritizationService $taskPrioritizationService;

    public function boot(
        UserAnalyticsService $userAnalyticsService,
        TaskService $taskService,
        SchoolClassService $schoolClassService,
        AssistantCandidateProvider $assistantCandidateProvider,
        TaskPrioritizationService $taskPrioritizationService
    ): void {
        $this->userAnalyticsService = $userAnalyticsService;
        $this->taskService = $taskService;
        $this->schoolClassService = $schoolClassService;
        $this->assistantCandidateProvider = $assistantCandidateProvider;
        $this->taskPrioritizationService = $taskPrioritizationService;
    }

    /**
     * Calendar agenda links open the workspace with date + focus only (no "Show" type filter), consistent with in-app calendar focus.
     */
    protected function omitTypeFilterOnCalendarAgendaWorkspaceLinks(): bool
    {
        return true;
    }

    public function mount(): void
    {
        if ($this->selectedDate === null || $this->selectedDate === '' || strtotime($this->selectedDate) === false) {
            $this->selectedDate = now()->toDateString();
        }

        $this->analyticsPreset = $this->normalizeAnalyticsPreset($this->analyticsPreset);
        $this->trendPreset = $this->normalizeAnalyticsPreset($this->trendPreset);
    }

    public function updatedSelectedDate(): void
    {
        $this->parsedSelectedDate = null;
        $this->resetCalendarViewForSelectedDateChange();
    }

    public function toggleInsights(): void
    {
        $this->insightsOpen = ! $this->insightsOpen;
    }

    public function loadInsightsCharts(): void
    {
        if (! $this->insightsOpen) {
            return;
        }

        $this->insightsChartsReady = true;
    }

    public function setTrendPreset(string $preset): void
    {
        $this->trendPreset = $this->normalizeAnalyticsPreset($preset);
    }

    #[Computed]
    public function trendAnalytics(): ?DashboardAnalyticsOverview
    {
        $user = Auth::user();
        if ($user === null) {
            return null;
        }

        return $this->userAnalyticsService->dashboardOverview(
            user: $user,
            preset: $this->trendPreset,
            anchor: $this->analyticsAnchor(),
        );
    }

    private function analyticsAnchor(): CarbonInterface
    {
        return now();
    }

    #[Computed]
    public function workspaceUrlForToday(): string
    {
        return route('workspace', [
            'date' => $this->getParsedSelectedDate()->toDateString(),
            'view' => 'list',
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function scheduledFocusPlanEntries(): Collection
    {
        $userId = Auth::id();
        if ($userId === null) {
            return collect();
        }

        $timezone = (string) config('app.timezone', 'UTC');
        $todayStart = \Carbon\CarbonImmutable::now($timezone)->startOfDay();

        return AssistantSchedulePlanItem::query()
            ->forUser($userId)
            ->active()
            ->where('planned_start_at', '>=', $todayStart)
            ->orderBy('planned_start_at')
            ->limit(50)
            ->get([
                'id',
                'entity_type',
                'entity_id',
                'title',
                'planned_start_at',
                'planned_end_at',
                'planned_duration_minutes',
                'status',
                'metadata',
            ])
            ->map(function (AssistantSchedulePlanItem $item) use ($timezone): array {
                $startAt = $item->planned_start_at?->setTimezone($timezone);
                $endAt = $item->planned_end_at?->setTimezone($timezone);
                $entityType = (string) $item->entity_type;
                $metadata = is_array($item->metadata ?? null) ? $item->metadata : [];
                $lastAction = strtolower(trim((string) data_get($metadata, 'actions.last_action', '')));
                $supersededCount = (int) data_get($metadata, 'rescheduled_from_previous_plan_item_count', 0);
                $isRescheduled = $lastAction === 'rescheduled' || $supersededCount > 0;

                $entityTypePillClass = match ($entityType) {
                    'event' => 'lic-item-type-pill--event',
                    'project' => 'lic-item-type-pill--project',
                    default => 'lic-item-type-pill--task',
                };

                return [
                    'id' => $item->id,
                    'entity_type' => $entityType,
                    'entity_id' => (int) $item->entity_id,
                    'entity_label' => Str::headline($entityType),
                    'entity_type_pill_class' => $entityTypePillClass,
                    'title' => (string) $item->title,
                    'planned_start_at' => $startAt?->toIso8601String(),
                    'planned_end_at' => $endAt?->toIso8601String(),
                    'planned_duration_minutes' => $item->planned_duration_minutes,
                    'status' => $item->status?->value ?? AssistantSchedulePlanItemStatus::Planned->value,
                    'time_range_label' => $this->formatScheduledFocusTimeRange($startAt, $endAt),
                    'duration_label' => $this->formatScheduledFocusDuration($item->planned_duration_minutes),
                    'is_rescheduled' => $isRescheduled,
                    'workspace_url' => $this->workspaceRouteForAgendaStyleFocus(
                        $startAt?->toDateString() ?? $this->getParsedSelectedDate()->toDateString(),
                        $entityType,
                        (int) $item->entity_id
                    ),
                ];
            })
            ->values();
    }

    /**
     * @return array<int, array{key: string, label: string, items: array<int, array<string, mixed>>}>
     */
    #[Computed]
    public function scheduledFocusPlanGroups(): array
    {
        $entries = $this->scheduledFocusPlanEntries;
        $timezone = (string) config('app.timezone', 'UTC');
        $today = \Carbon\CarbonImmutable::now($timezone)->startOfDay();
        $tomorrow = $today->addDay();

        return $entries
            ->groupBy(function (array $entry): string {
                $plannedStartAt = (string) ($entry['planned_start_at'] ?? '');
                if ($plannedStartAt === '') {
                    return 'undated';
                }

                return \Carbon\Carbon::parse($plannedStartAt)->toDateString();
            })
            ->sortKeys()
            ->map(function (Collection $items, string $dayKey) use ($timezone, $today, $tomorrow): array {
                $label = (string) __('No date');
                if ($dayKey !== 'undated') {
                    $day = \Carbon\CarbonImmutable::parse($dayKey, $timezone)->startOfDay();
                    if ($day->equalTo($today)) {
                        $label = (string) __('Today');
                    } elseif ($day->equalTo($tomorrow)) {
                        $label = (string) __('Tomorrow');
                    } else {
                        $label = $day->translatedFormat('l, F j');
                    }
                }

                return [
                    'key' => $dayKey,
                    'label' => $label,
                    'items' => $items->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    #[Computed]
    public function scheduledFocusPlanTotalCount(): int
    {
        return $this->scheduledFocusPlanEntries->count();
    }

    #[Computed]
    public function dashboardTaskCounts(): array
    {
        $userId = Auth::id();
        if ($userId === null) {
            return [
                'incomplete' => 0,
                'todo' => 0,
                'total' => 0,
                'completed' => 0,
                'overdue' => 0,
                'due_today' => 0,
            ];
        }

        $now = now();
        $startOfDay = $this->getParsedSelectedDate()->copy()->startOfDay();
        $endOfDay = $this->getParsedSelectedDate()->copy()->endOfDay();

        $baseCounts = Task::query()
            ->forUser($userId)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN completed_at IS NOT NULL THEN 1 ELSE 0 END) as completed')
            ->selectRaw('SUM(CASE WHEN completed_at IS NULL THEN 1 ELSE 0 END) as incomplete')
            ->selectRaw('SUM(CASE WHEN completed_at IS NULL AND status = ? THEN 1 ELSE 0 END) as todo', [TaskStatus::ToDo->value])
            ->first();

        $overdueCount = Task::query()
            ->forUser($userId)
            ->incomplete()
            ->withoutHiddenOverdueFeedItems($now)
            ->overdue($now)
            ->whereDoesntHave('recurringTask')
            ->count();

        $dueTodayCount = Task::query()
            ->forUser($userId)
            ->incomplete()
            ->whereNotNull('end_datetime')
            ->whereBetween('end_datetime', [$startOfDay, $endOfDay])
            ->whereDoesntHave('recurringTask')
            ->count();

        /** @var array{incomplete:int,todo:int,total:int,completed:int,overdue:int,due_today:int} $counts */
        $counts = [
            'incomplete' => (int) ($baseCounts?->incomplete ?? 0),
            'todo' => (int) ($baseCounts?->todo ?? 0),
            'total' => (int) ($baseCounts?->total ?? 0),
            'completed' => (int) ($baseCounts?->completed ?? 0),
            'overdue' => $overdueCount,
            'due_today' => $dueTodayCount,
        ];

        return $counts;
    }

    #[Computed]
    public function dashboardIncompleteTasksCount(): int
    {
        return (int) ($this->dashboardTaskCounts['incomplete'] ?? 0);
    }

    #[Computed]
    public function dashboardTodoTasksCount(): int
    {
        return (int) ($this->dashboardTaskCounts['todo'] ?? 0);
    }

    #[Computed]
    public function dashboardTotalTasksCount(): int
    {
        return (int) ($this->dashboardTaskCounts['total'] ?? 0);
    }

    #[Computed]
    public function dashboardCompletedTasksCount(): int
    {
        return (int) ($this->dashboardTaskCounts['completed'] ?? 0);
    }

    /**
     * @return EloquentCollection<int, Task>
     */
    #[Computed]
    public function dashboardOverdueTasks(): EloquentCollection
    {
        $userId = Auth::id();
        if ($userId === null) {
            return new EloquentCollection;
        }

        $now = now();

        return Task::query()
            ->with(['project'])
            ->forUser($userId)
            ->incomplete()
            ->withoutHiddenOverdueFeedItems($now)
            ->overdue($now)
            ->whereDoesntHave('recurringTask')
            ->orderByPriority()
            ->orderBy('end_datetime')
            ->limit(self::AT_A_GLANCE_LIMIT)
            ->get();
    }

    #[Computed]
    public function dashboardOverdueTasksCount(): int
    {
        return (int) ($this->dashboardTaskCounts['overdue'] ?? 0);
    }

    /**
     * @return EloquentCollection<int, Task>
     */
    #[Computed]
    public function dashboardDoingTasks(): EloquentCollection
    {
        $userId = Auth::id();
        if ($userId === null) {
            return new EloquentCollection;
        }

        $nonRecurringDoingTasks = Task::query()
            ->with([
                'project',
                'focusSessions' => fn ($query) => $query->work(),
            ])
            ->forUser($userId)
            ->incomplete()
            ->where('status', TaskStatus::Doing)
            ->whereDoesntHave('recurringTask')
            ->orderByPriority()
            ->orderBy('end_datetime')
            ->get();

        $selectedDate = $this->getParsedSelectedDate();
        $recurringCandidates = Task::query()
            ->with([
                'project',
                'recurringTask',
                'focusSessions' => fn ($query) => $query->work(),
            ])
            ->forUser($userId)
            ->incomplete()
            ->whereHas('recurringTask')
            ->relevantForDate($selectedDate)
            ->orderByPriority()
            ->orderByRaw('COALESCE(end_datetime, start_datetime) ASC')
            ->orderByDesc('id')
            ->get();

        $recurringDoingTasks = $this->taskService
            ->processRecurringTasksForDate($recurringCandidates, $selectedDate)
            ->filter(fn (Task $task): bool => $task->effectiveStatusForDate === TaskStatus::Doing);

        return new EloquentCollection(
            $nonRecurringDoingTasks
                ->merge($recurringDoingTasks)
                ->unique('id')
                ->sortBy([
                    fn (Task $task): int => match ($task->priority?->value ?? $task->priority) {
                        'urgent' => 1,
                        'high' => 2,
                        'medium' => 3,
                        'low' => 4,
                        default => 5,
                    },
                    fn (Task $task): int => $task->end_datetime?->getTimestamp() ?? PHP_INT_MAX,
                    fn (Task $task): int => -$task->id,
                ])
                ->take(self::AT_A_GLANCE_LIMIT)
                ->values()
                ->all()
        );
    }

    #[Computed]
    public function dashboardDoingTasksCount(): int
    {
        return $this->dashboardDoingTasks->count();
    }

    /**
     * @return EloquentCollection<int, Task>
     */
    #[Computed]
    public function dashboardDueTodayTasks(): EloquentCollection
    {
        $userId = Auth::id();
        if ($userId === null) {
            return new EloquentCollection;
        }

        $startOfDay = $this->getParsedSelectedDate()->copy()->startOfDay();
        $endOfDay = $this->getParsedSelectedDate()->copy()->endOfDay();

        return Task::query()
            ->with(['project'])
            ->forUser($userId)
            ->incomplete()
            ->whereNotNull('end_datetime')
            ->whereBetween('end_datetime', [$startOfDay, $endOfDay])
            ->whereDoesntHave('recurringTask')
            ->orderByPriority()
            ->orderBy('end_datetime')
            ->limit(self::AT_A_GLANCE_LIMIT)
            ->get();
    }

    #[Computed]
    public function dashboardDueTodayTasksCount(): int
    {
        return (int) ($this->dashboardTaskCounts['due_today'] ?? 0);
    }

    /**
     * @return Collection<int, array{
     *   id:int,
     *   subject_name:string,
     *   teacher_name:string|null,
     *   starts_at_iso:string|null,
     *   ends_at_iso:string|null,
     *   time_label:string,
     *   state:'past'|'now'|'next'|'later',
     *   workspace_url:string
     * }>
     */
    #[Computed]
    public function dashboardTodaySchoolClasses(): Collection
    {
        $userId = Auth::id();
        if ($userId === null) {
            return collect();
        }

        $selectedDate = $this->getParsedSelectedDate()->copy();
        $startOfDay = $selectedDate->copy()->startOfDay();
        $endOfDay = $selectedDate->copy()->endOfDay();

        $allClasses = SchoolClass::query()
            ->forUser($userId)
            ->notArchived()
            ->with(['teacher', 'recurringSchoolClass'])
            ->orderBy('start_time')
            ->orderBy('subject_name')
            ->get();

        $classesForDay = $this->schoolClassService->filterSchoolClassesForCalendarDay($allClasses, $startOfDay, $endOfDay);
        $now = now();

        $rows = $classesForDay
            ->map(function (SchoolClass $class) use ($selectedDate, $now): array {
                [$startsAt, $endsAt] = $this->resolveDashboardSchoolClassDateWindow($class, $selectedDate);
                $state = 'later';

                if ($startsAt !== null && $endsAt !== null) {
                    if ($now->between($startsAt, $endsAt)) {
                        $state = 'now';
                    } elseif ($endsAt->lessThan($now)) {
                        $state = 'past';
                    }
                } elseif ($startsAt !== null && $startsAt->lessThanOrEqualTo($now)) {
                    $state = 'past';
                }

                return [
                    'id' => $class->id,
                    'subject_name' => (string) $class->subject_name,
                    'teacher_name' => $class->teacher?->name,
                    'starts_at_iso' => $startsAt?->toIso8601String(),
                    'ends_at_iso' => $endsAt?->toIso8601String(),
                    'time_label' => $this->formatDashboardSchoolClassTimeLabel($startsAt, $endsAt),
                    'state' => $state,
                    'workspace_url' => $this->workspaceRouteForAgendaStyleFocus(
                        $selectedDate->toDateString(),
                        'school_class',
                        $class->id
                    ),
                ];
            })
            ->sortBy(fn (array $row): int => $row['starts_at_iso'] !== null
                ? \Carbon\Carbon::parse($row['starts_at_iso'])->getTimestamp()
                : PHP_INT_MAX)
            ->values();

        $firstUpcomingClassId = $rows
            ->first(fn (array $row): bool => $row['state'] !== 'now'
                && $row['starts_at_iso'] !== null
                && \Carbon\Carbon::parse($row['starts_at_iso'])->isFuture())['id'] ?? null;

        /** @var array<int, array{
         *   id:int,
         *   subject_name:string,
         *   teacher_name:string|null,
         *   starts_at_iso:string|null,
         *   ends_at_iso:string|null,
         *   time_label:string,
         *   state:'past'|'now'|'next'|'later',
         *   workspace_url:string
         * }> $rowList */
        $rowList = $rows
            ->map(function (array $row) use ($firstUpcomingClassId): array {
                if ($row['state'] === 'later' && $firstUpcomingClassId !== null && $row['id'] === $firstUpcomingClassId) {
                    $row['state'] = 'next';
                }

                return $row;
            })
            ->take(self::TODAY_SCHOOL_CLASSES_DISPLAY_LIMIT)
            ->values()
            ->all();

        return collect($rowList);
    }

    #[Computed]
    public function dashboardTodaySchoolClassesCount(): int
    {
        $userId = Auth::id();
        if ($userId === null) {
            return 0;
        }

        $selectedDate = $this->getParsedSelectedDate()->copy();
        $startOfDay = $selectedDate->copy()->startOfDay();
        $endOfDay = $selectedDate->copy()->endOfDay();

        $allClasses = SchoolClass::query()
            ->forUser($userId)
            ->notArchived()
            ->with(['recurringSchoolClass'])
            ->orderBy('start_time')
            ->orderBy('subject_name')
            ->get();

        return $this->schoolClassService->countSchoolClassesOnCalendarDay($allClasses, $startOfDay, $endOfDay);
    }

    #[Computed]
    public function dashboardTodaySchoolClassesHasMore(): bool
    {
        return $this->dashboardTodaySchoolClassesCount > self::TODAY_SCHOOL_CLASSES_DISPLAY_LIMIT;
    }

    /**
     * @return EloquentCollection<int, Task>
     */
    #[Computed]
    public function dashboardRecurringDueTasksAll(): EloquentCollection
    {
        $userId = Auth::id();
        if ($userId === null) {
            return new EloquentCollection;
        }

        $selectedDate = $this->getParsedSelectedDate();
        $startOfDay = $selectedDate->copy()->startOfDay();
        $endOfDay = $selectedDate->copy()->endOfDay();

        $datedRecurringTasks = Task::query()
            ->with(['project', 'recurringTask'])
            ->forUser($userId)
            ->incomplete()
            ->whereHas('recurringTask')
            ->whereNotNull('end_datetime')
            ->whereBetween('end_datetime', [$startOfDay, $endOfDay])
            ->orderByPriority()
            ->get();

        $undatedRecurringCandidates = Task::query()
            ->with(['project', 'recurringTask'])
            ->forUser($userId)
            ->incomplete()
            ->whereHas('recurringTask')
            ->whereNull('end_datetime')
            ->relevantForDate($selectedDate)
            ->orderByPriority()
            ->get();

        $undatedRecurringTasks = $this->taskService
            ->processRecurringTasksForDate($undatedRecurringCandidates, $selectedDate)
            ->filter(fn (Task $task): bool => $task->effectiveStatusForDate !== TaskStatus::Done);

        /** @var EloquentCollection<int, Task> $processedTasks */
        $processedTasks = new EloquentCollection(
            $datedRecurringTasks
                ->merge($undatedRecurringTasks)
                ->unique('id')
                ->sortBy([
                    fn (Task $task): int => match ($task->priority?->value ?? $task->priority) {
                        'urgent' => 1,
                        'high' => 2,
                        'medium' => 3,
                        'low' => 4,
                        default => 5,
                    },
                    fn (Task $task): int => $task->end_datetime?->getTimestamp() ?? PHP_INT_MAX,
                    fn (Task $task): int => -$task->id,
                ])
                ->values()
                ->all()
        );

        return $processedTasks;
    }

    #[Computed]
    public function dashboardRecurringDueTasks(): EloquentCollection
    {
        return new EloquentCollection(
            $this->dashboardRecurringDueTasksAll
                ->take(self::RECURRING_DUE_DISPLAY_LIMIT)
                ->values()
                ->all()
        );
    }

    #[Computed]
    public function dashboardRecurringDueCount(): int
    {
        return $this->dashboardRecurringDueTasksAll->count();
    }

    #[Computed]
    public function dashboardRecurringDueHasMore(): bool
    {
        return $this->dashboardRecurringDueCount > self::RECURRING_DUE_DISPLAY_LIMIT;
    }

    #[Computed]
    public function dashboardRecurringCompletedCount(): int
    {
        $userId = Auth::id();
        if ($userId === null) {
            return 0;
        }

        $startOfDay = $this->getParsedSelectedDate()->copy()->startOfDay();
        $endOfDay = $this->getParsedSelectedDate()->copy()->endOfDay();

        return Task::query()
            ->forUser($userId)
            ->whereHas('recurringTask')
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$startOfDay, $endOfDay])
            ->count();
    }

    /**
     * @return array{due:int,completed:int,completion_rate:int,streak_days:int}
     */
    #[Computed]
    public function dashboardRecurringSummary(): array
    {
        $dueCount = $this->dashboardRecurringDueCount;
        $completedCount = $this->dashboardRecurringCompletedCount;
        $total = $dueCount + $completedCount;
        $completionRate = $total > 0 ? (int) round(($completedCount / $total) * 100) : 0;
        $streakDays = $this->dashboardRecurringCompletionStreakDays;

        return [
            'due' => $dueCount,
            'completed' => $completedCount,
            'completion_rate' => $completionRate,
            'streak_days' => $streakDays,
        ];
    }

    #[Computed]
    public function dashboardRecurringCompletionStreakDays(): int
    {
        $userId = Auth::id();
        if ($userId === null) {
            return 0;
        }

        $selectedDay = $this->getParsedSelectedDate()->copy()->startOfDay();

        /** @var Collection<int, string> $completionDates */
        $completionDates = Task::query()
            ->forUser($userId)
            ->whereHas('recurringTask')
            ->whereNotNull('completed_at')
            ->whereDate('completed_at', '<=', $selectedDay->toDateString())
            ->selectRaw('DATE(completed_at) as completed_date')
            ->distinct()
            ->orderByDesc('completed_date')
            ->pluck('completed_date')
            ->filter()
            ->map(fn (mixed $completedAt): string => (string) $completedAt)
            ->values();

        if ($completionDates->isEmpty()) {
            return 0;
        }

        $completionDateLookup = array_fill_keys($completionDates->all(), true);
        $streakDays = 0;
        $cursor = $selectedDay->copy();

        while (isset($completionDateLookup[$cursor->toDateString()])) {
            $streakDays++;
            $cursor->subDay();
        }

        return $streakDays;
    }

    /** Visible rows per compact list card (Doing, Recurring, No-date, Classes). */
    public function dashboardListCardRowLimit(): int
    {
        return self::DASHBOARD_LIST_CARD_ROW_LIMIT;
    }

    /**
     * @return EloquentCollection<int, Task>
     */
    #[Computed]
    public function dashboardNoDateBacklogTasks(): EloquentCollection
    {
        $userId = Auth::id();
        if ($userId === null) {
            return new EloquentCollection;
        }

        return Task::query()
            ->with([
                'project',
                'focusSessions' => fn ($query) => $query->work(),
            ])
            ->forUser($userId)
            ->incomplete()
            ->withNoDate()
            ->whereDoesntHave('recurringTask')
            ->orderByPriority()
            ->orderByDesc('updated_at')
            ->limit(self::NO_DATE_BACKLOG_DISPLAY_LIMIT)
            ->get();
    }

    #[Computed]
    public function dashboardNoDateBacklogCount(): int
    {
        $userId = Auth::id();
        if ($userId === null) {
            return 0;
        }

        return Task::query()
            ->forUser($userId)
            ->incomplete()
            ->withNoDate()
            ->whereDoesntHave('recurringTask')
            ->count();
    }

    /**
     * @return EloquentCollection<int, Event>
     */
    #[Computed]
    public function dashboardTodayEvents(): EloquentCollection
    {
        $userId = Auth::id();
        if ($userId === null) {
            return new EloquentCollection;
        }

        $startOfDay = $this->getParsedSelectedDate()->copy()->startOfDay();
        $endOfDay = $this->getParsedSelectedDate()->copy()->endOfDay();

        return Event::query()
            ->forUser($userId)
            ->notCancelled()
            ->notCompleted()
            ->whereDoesntHave('recurringEvent')
            ->whereNotNull('start_datetime')
            ->whereBetween('start_datetime', [$startOfDay, $endOfDay])
            ->orderBy('start_datetime')
            ->limit(self::AT_A_GLANCE_LIMIT)
            ->get();
    }

    #[Computed]
    public function dashboardTodayEventsCount(): int
    {
        $userId = Auth::id();
        if ($userId === null) {
            return 0;
        }

        $startOfDay = $this->getParsedSelectedDate()->copy()->startOfDay();
        $endOfDay = $this->getParsedSelectedDate()->copy()->endOfDay();

        return Event::query()
            ->forUser($userId)
            ->notCancelled()
            ->notCompleted()
            ->whereDoesntHave('recurringEvent')
            ->whereNotNull('start_datetime')
            ->whereBetween('start_datetime', [$startOfDay, $endOfDay])
            ->count();
    }

    /**
     * @return Collection<int, array{
     *   type: string,
     *   id: int,
     *   title: string,
     *   score: int,
     *   reasoning: string,
     *   priority: string|null,
     *   complexity: string|null,
     *   ends_at: string|null,
     *   urgency_level: 'critical'|'high'|'normal',
     *   workspace_url: string
     * }>
     */
    #[Computed]
    public function urgentNow(): Collection
    {
        $user = Auth::user();
        if ($user === null) {
            return collect();
        }

        $snapshot = $this->assistantCandidateProvider->candidatesForUser(
            user: $user,
            taskLimit: 80,
            eventLimit: 12,
            projectLimit: 12,
        );

        // Do not cache ranked output: a stale cache ignored fresh snapshots and hid
        // workspace updates (priority/due date) until TTL expired. prioritizeFocus is deterministic PHP.
        $ranked = $this->taskPrioritizationService->prioritizeFocus($snapshot, []);

        return collect($ranked)
            ->filter(fn (array $row): bool => ($row['type'] ?? null) === 'task')
            ->filter(function (array $item): bool {
                $raw = is_array($item['raw'] ?? null) ? $item['raw'] : [];
                $priority = is_string($raw['priority'] ?? null) ? (string) $raw['priority'] : null;
                $endsAt = is_string($raw['ends_at'] ?? null) ? (string) $raw['ends_at'] : null;
                $status = is_string($raw['status'] ?? null) ? (string) $raw['status'] : null;

                return $this->qualifiesForUrgentNow($priority, $endsAt, $status);
            })
            ->sortBy([
                fn (array $item): int => $this->isUrgentNowCandidateOverdue($item) ? 0 : 1,
                fn (array $item): int => -((int) ($item['score'] ?? 0)),
                fn (array $item): int => -((int) ($item['id'] ?? 0)),
            ])
            ->take(self::URGENT_NOW_PREVIEW_LIMIT)
            ->map(function (array $item): array {
                $raw = is_array($item['raw'] ?? null) ? $item['raw'] : [];
                $priority = is_string($raw['priority'] ?? null) ? (string) $raw['priority'] : null;
                $complexity = is_string($raw['complexity'] ?? null) ? (string) $raw['complexity'] : null;
                $endsAt = is_string($raw['ends_at'] ?? null) ? (string) $raw['ends_at'] : null;
                $status = is_string($raw['status'] ?? null) ? (string) $raw['status'] : null;
                $urgencyLevel = $this->resolveUrgencyLevel($priority, $endsAt, $status);
                $itemType = (string) ($item['type'] ?? 'task');
                $itemId = (int) ($item['id'] ?? 0);

                return [
                    'type' => $itemType,
                    'id' => $itemId,
                    'title' => (string) ($item['title'] ?? __('Untitled')),
                    'score' => (int) ($item['score'] ?? 0),
                    'reasoning' => $this->resolveUrgentNowReasoning($priority, $endsAt, $status),
                    'priority' => $priority,
                    'complexity' => $complexity,
                    'ends_at' => $endsAt,
                    'urgency_level' => $urgencyLevel,
                    'workspace_url' => $this->workspaceRouteForAgendaStyleFocus(
                        $this->getParsedSelectedDate()->toDateString(),
                        $itemType,
                        $itemId
                    ),
                ];
            })
            ->values();
    }

    private function qualifiesForUrgentNow(?string $priority, ?string $endsAt, ?string $status): bool
    {
        $normalizedPriority = strtolower(trim((string) $priority));
        $isHighOrUrgentPriority = in_array($normalizedPriority, ['high', 'urgent'], true);

        if ($normalizedPriority === 'urgent' && $endsAt === null) {
            return true;
        }

        if ($endsAt !== null) {
            try {
                $deadline = \Carbon\Carbon::parse($endsAt);

                if ($deadline->isPast()) {
                    return true;
                }

                if ($deadline->isToday()) {
                    return true;
                }

                if ($isHighOrUrgentPriority && $this->isWithinUrgentSoonWindow($deadline)) {
                    return true;
                }

                if ($status === TaskStatus::Doing->value && $isHighOrUrgentPriority && $this->isWithinUrgentSoonWindow($deadline)) {
                    return true;
                }
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    private function isWithinUrgentSoonWindow(\Carbon\CarbonInterface $deadline): bool
    {
        $now = now();

        return $deadline->greaterThan($now) && $deadline->lessThanOrEqualTo($now->copy()->addHours(self::URGENT_NOW_SOON_WINDOW_HOURS));
    }

    private function isUrgentNowCandidateOverdue(array $item): bool
    {
        $raw = is_array($item['raw'] ?? null) ? $item['raw'] : [];
        $endsAt = is_string($raw['ends_at'] ?? null) ? (string) $raw['ends_at'] : null;
        if ($endsAt === null) {
            return false;
        }

        try {
            return \Carbon\Carbon::parse($endsAt)->isPast();
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveUrgentNowReasoning(?string $priority, ?string $endsAt, ?string $status): string
    {
        $priorityLabel = $priority !== null ? \Illuminate\Support\Str::headline($priority) : null;

        if ($endsAt !== null) {
            try {
                $deadline = \Carbon\Carbon::parse($endsAt);

                if ($deadline->isPast()) {
                    return __('Overdue since :time. Start with this first.', [
                        'time' => $deadline->translatedFormat('M j · g:i A'),
                    ]);
                }

                if ($deadline->isToday()) {
                    if ($status === TaskStatus::Doing->value) {
                        return __('In progress and due today at :time. Try to finish it today.', [
                            'time' => $deadline->translatedFormat('g:i A'),
                        ]);
                    }

                    return __('Due today at :time. Try to finish it today.', [
                        'time' => $deadline->translatedFormat('g:i A'),
                    ]);
                }

                if ($this->isWithinUrgentSoonWindow($deadline)) {
                    $dueInPhrase = $deadline->diffForHumans(now(), [
                        'syntax' => \Carbon\CarbonInterface::DIFF_RELATIVE_TO_NOW,
                        'parts' => 2,
                        'short' => false,
                    ]);

                    if ($status === TaskStatus::Doing->value && $priorityLabel !== null) {
                        return __('In progress, due :when, and marked :priority priority.', [
                            'when' => $dueInPhrase,
                            'priority' => $priorityLabel,
                        ]);
                    }

                    if ($priorityLabel !== null) {
                        return __('Due :when and marked :priority priority.', [
                            'when' => $dueInPhrase,
                            'priority' => $priorityLabel,
                        ]);
                    }

                    return __('Due :when. Keep this near the top of your list.', [
                        'when' => $dueInPhrase,
                    ]);
                }
            } catch (\Throwable) {
                // Fallback to priority/status based copy below when date parsing fails.
            }
        }

        if ($priority === 'urgent') {
            return __('Marked Urgent priority with no due date. Give it a time block soon.');
        }

        if ($status === TaskStatus::Doing->value && $priorityLabel !== null) {
            return __('In progress and marked :priority priority.', [
                'priority' => $priorityLabel,
            ]);
        }

        if ($priorityLabel !== null) {
            return __('Marked :priority priority for your current focus list.', [
                'priority' => $priorityLabel,
            ]);
        }

        return __('Needs attention soon based on your current workload.');
    }

    /**
     * @return Collection<int, array{
     *   type: string,
     *   id: int,
     *   title: string,
     *   score: int,
     *   reasoning: string,
     *   priority: string|null,
     *   complexity: string|null,
     *   ends_at: string|null,
     *   urgency_level: 'critical'|'high'|'normal',
     *   workspace_url: string
     * }>
     */
    #[Computed]
    public function urgentNowDisplayed(): Collection
    {
        return $this->urgentNow->take(self::URGENT_NOW_DISPLAY_LIMIT);
    }

    #[Computed]
    public function urgentNowHasMore(): bool
    {
        return $this->urgentNow->count() > self::URGENT_NOW_DISPLAY_LIMIT;
    }

    /**
     * @return Collection<int, array{
     *   id: int,
     *   name: string,
     *   total_tasks: int,
     *   incomplete_tasks: int,
     *   overdue_tasks: int,
     *   completion_rate: int,
     *   nearest_deadline: string|null,
     *   risk: string,
     *   risk_reason: string,
     *   workspace_url: string
     * }>
     */
    #[Computed]
    public function projectHealth(): Collection
    {
        $userId = Auth::id();
        if ($userId === null) {
            return collect();
        }

        $now = now();
        $soonThreshold = $now->copy()->addDays(3);

        $rows = Project::query()
            ->forUser($userId)
            ->notArchived()
            ->withCount('tasks')
            ->withCount([
                'tasks as incomplete_tasks_count' => fn ($query) => $query->incomplete(),
                'tasks as overdue_tasks_count' => fn ($query) => $query->incomplete()->overdue($now),
            ])
            ->withMin([
                'tasks as nearest_deadline' => fn ($query) => $query
                    ->incomplete()
                    ->whereNotNull('end_datetime'),
            ], 'end_datetime')
            ->orderByDesc('overdue_tasks_count')
            ->orderBy('nearest_deadline')
            ->limit(self::PROJECT_HEALTH_LIMIT)
            ->get()
            ->map(function (Project $project) use ($soonThreshold): array {
                $totalTasks = (int) ($project->tasks_count ?? 0);
                $incompleteTasks = (int) ($project->incomplete_tasks_count ?? 0);
                $overdueTasks = (int) ($project->overdue_tasks_count ?? 0);
                $completedTasks = max(0, $totalTasks - $incompleteTasks);
                $completionRate = $totalTasks > 0 ? (int) round(($completedTasks / $totalTasks) * 100) : 0;
                $nearestDeadline = $project->nearest_deadline;
                $nearestDeadlineDate = $nearestDeadline ? \Carbon\Carbon::parse($nearestDeadline) : null;
                $daysToDeadline = $nearestDeadlineDate !== null ? max(0, now()->startOfDay()->diffInDays($nearestDeadlineDate->startOfDay(), false)) : null;

                $risk = 'On Track';
                $riskReason = __('Healthy progress and no immediate blockers.');
                if ($overdueTasks > 0) {
                    $risk = 'Critical';
                    $riskReason = __('Has overdue tasks that need immediate action.');
                } elseif ($incompleteTasks >= 6 && $completionRate < 35) {
                    $risk = 'Critical';
                    $riskReason = __('Large backlog with low completion velocity.');
                } elseif ($nearestDeadlineDate !== null && $nearestDeadlineDate->lte($soonThreshold) && $completionRate < 60) {
                    $risk = 'At Risk';
                    $riskReason = __('Deadline is close and completion is below target.');
                } elseif ($daysToDeadline !== null && $daysToDeadline <= 7 && $incompleteTasks >= 3) {
                    $risk = 'At Risk';
                    $riskReason = __('Upcoming deadline with several tasks still open.');
                }

                return [
                    'id' => $project->id,
                    'name' => (string) $project->name,
                    'total_tasks' => $totalTasks,
                    'incomplete_tasks' => $incompleteTasks,
                    'overdue_tasks' => $overdueTasks,
                    'completion_rate' => $completionRate,
                    'nearest_deadline' => $nearestDeadlineDate?->toIso8601String(),
                    'risk' => $risk,
                    'risk_reason' => $riskReason,
                    'workspace_url' => $this->workspaceRouteForAgendaStyleFocus(
                        $this->getParsedSelectedDate()->toDateString(),
                        'project',
                        $project->id
                    ),
                ];
            })
            ->values()
            ->all();

        return collect($rows);
    }

    /**
     * @return array{
     *   daily_focus_minutes: int,
     *   weekly_focus_minutes: int,
     *   completed_today: int,
     *   focus_per_completed_minutes: int
     * }
     */
    #[Computed]
    public function focusThroughput(): array
    {
        $userId = Auth::id();
        if ($userId === null) {
            return [
                'daily_focus_minutes' => 0,
                'weekly_focus_minutes' => 0,
                'completed_today' => 0,
                'focus_per_completed_minutes' => 0,
            ];
        }

        $selectedDate = $this->getParsedSelectedDate()->copy();
        $dayStart = $selectedDate->copy()->startOfDay();
        $dayEnd = $selectedDate->copy()->endOfDay();
        $weekStart = $selectedDate->copy()->startOfWeek();
        $weekEnd = $selectedDate->copy()->endOfWeek();

        $dailyWorkSeconds = FocusSession::query()
            ->forUser($userId)
            ->work()
            ->completed()
            ->whereBetween('started_at', [$dayStart, $dayEnd])
            ->selectRaw('COALESCE(SUM(duration_seconds), 0) as aggregate')
            ->value('aggregate');

        $weeklyWorkSeconds = FocusSession::query()
            ->forUser($userId)
            ->work()
            ->completed()
            ->whereBetween('started_at', [$weekStart, $weekEnd])
            ->selectRaw('COALESCE(SUM(duration_seconds), 0) as aggregate')
            ->value('aggregate');

        $completedToday = Task::query()
            ->forUser($userId)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$dayStart, $dayEnd])
            ->count();

        $dailyMinutes = (int) floor(((int) $dailyWorkSeconds) / 60);
        $weeklyMinutes = (int) floor(((int) $weeklyWorkSeconds) / 60);

        return [
            'daily_focus_minutes' => $dailyMinutes,
            'weekly_focus_minutes' => $weeklyMinutes,
            'completed_today' => $completedToday,
            'focus_per_completed_minutes' => $completedToday > 0 ? (int) floor($dailyMinutes / $completedToday) : 0,
        ];
    }

    /**
     * @return array{
     *   pending_invites: int,
     *   active_collaborations: int,
     *   activity_last_7d: int
     * }
     */
    #[Computed]
    public function collaborationPulseCounts(): array
    {
        $user = Auth::user();
        if ($user === null) {
            return [
                'pending_invites' => 0,
                'active_collaborations' => 0,
                'activity_last_7d' => 0,
            ];
        }

        $userId = (int) $user->id;

        $pendingInvites = CollaborationInvitation::query()
            ->pendingForUser($user)
            ->count();

        $activeCollaborations = Collaboration::query()
            ->where('user_id', $userId)
            ->count();

        $activityLast7d = ActivityLog::query()
            ->whereIn('action', self::collaborationActivityActions())
            ->where('created_at', '>=', now()->subDays(7))
            ->whereHasMorph('loggable', [Task::class, Event::class, Project::class], function ($query, string $type) use ($userId): void {
                $query->forUser($userId);
            })
            ->count();

        return [
            'pending_invites' => $pendingInvites,
            'active_collaborations' => $activeCollaborations,
            'activity_last_7d' => $activityLast7d,
        ];
    }

    /**
     * @return Collection<int, array{
     *   id: int,
     *   actor: string,
     *   action_label: string,
     *   message: string,
     *   item_type: string,
     *   item_title: string,
     *   created_at: string
     * }>
     */
    #[Computed]
    public function collaborationPulseRecentActivity(): Collection
    {
        $userId = Auth::id();
        if ($userId === null) {
            return collect();
        }

        return ActivityLog::query()
            ->with(['user', 'loggable'])
            ->whereIn('action', self::collaborationActivityActions())
            ->whereHasMorph('loggable', [Task::class, Event::class, Project::class], function ($query, string $type) use ($userId): void {
                $query->forUser($userId);
            })
            ->latest()
            ->limit(self::COLLAB_ACTIVITY_LIMIT)
            ->get()
            ->map(function (ActivityLog $log): array {
                $itemType = class_basename((string) $log->loggable_type);
                $item = $log->loggable;
                $itemTitle = __('Untitled');
                if ($item !== null) {
                    $itemTitle = (string) ($item->title ?? $item->name ?? __('Untitled'));
                }

                return [
                    'id' => $log->id,
                    'actor' => (string) ($log->user?->name ?? $log->user?->email ?? __('Unknown user')),
                    'action_label' => $log->action?->label() ?? __('Activity'),
                    'message' => $log->message(),
                    'item_type' => $itemType !== '' ? $itemType : __('Item'),
                    'item_title' => $itemTitle,
                    'created_at' => $log->created_at?->toIso8601String() ?? now()->toIso8601String(),
                ];
            })
            ->values();
    }

    /**
     * @return Collection<int, CollaborationInvitation>
     */
    #[Computed]
    public function collaborationInboxInvites(): Collection
    {
        $user = Auth::user();
        if ($user === null) {
            return collect();
        }

        return CollaborationInvitation::query()
            ->with(['inviter', 'collaboratable'])
            ->pendingForUser($user)
            ->latest()
            ->limit(self::COLLAB_INVITES_LIMIT)
            ->get();
    }

    /**
     * @return Collection<int, array{
     *   id: int,
     *   name: string,
     *   source: string,
     *   sync_enabled: bool,
     *   exclude_overdue_items: bool,
     *   import_past_months: int,
     *   last_synced_at: string|null,
     *   total_imported: int,
     * }>
     */
    #[Computed]
    public function calendarFeedHealth(): Collection
    {
        $userId = Auth::id();
        if ($userId === null) {
            return collect();
        }

        $feeds = CalendarFeed::query()
            ->where('user_id', $userId)
            ->orderByDesc('last_synced_at')
            ->limit(self::FEED_HEALTH_LIMIT)
            ->get(['id', 'name', 'source', 'sync_enabled', 'exclude_overdue_items', 'import_past_months', 'last_synced_at', 'created_at']);

        if ($feeds->isEmpty()) {
            return collect();
        }

        $feedIds = $feeds->pluck('id')->all();

        /** @var Collection<int, object{calendar_feed_id: int, total_imported: int|string}> $taskStats */
        $taskStats = Task::query()
            ->selectRaw('calendar_feed_id, COUNT(*) as total_imported')
            ->whereIn('calendar_feed_id', $feedIds)
            ->where('source_type', TaskSourceType::Brightspace->value)
            ->groupBy('calendar_feed_id')
            ->get()
            ->keyBy('calendar_feed_id');

        return $feeds->map(function (CalendarFeed $feed) use ($taskStats): array {
            $stats = $taskStats->get($feed->id);

            return [
                'id' => $feed->id,
                'name' => (string) ($feed->name ?: __('Untitled feed')),
                'source' => (string) $feed->source,
                'sync_enabled' => (bool) $feed->sync_enabled,
                'exclude_overdue_items' => (bool) $feed->exclude_overdue_items,
                'import_past_months' => (int) $feed->resolvedImportPastMonths(),
                'last_synced_at' => $feed->last_synced_at?->toIso8601String(),
                'total_imported' => (int) ($stats?->total_imported ?? 0),
            ];
        })
            ->sortBy(fn (array $row): array => [
                $row['last_synced_at'] ? -\Carbon\Carbon::parse($row['last_synced_at'])->getTimestamp() : PHP_INT_MAX,
            ])
            ->values();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function loadCalendarFeedHealth(): array
    {
        return $this->calendarFeedHealth
            ->map(function (array $feed): array {
                $lastSyncedAt = $feed['last_synced_at'] ? \Carbon\Carbon::parse($feed['last_synced_at']) : null;

                return [
                    'id' => (int) $feed['id'],
                    'name' => (string) $feed['name'],
                    'source' => (string) $feed['source'],
                    'source_label' => ucfirst((string) $feed['source']),
                    'exclude_overdue_items' => (bool) ($feed['exclude_overdue_items'] ?? false),
                    'import_past_months' => (int) ($feed['import_past_months'] ?? (int) config('calendar_feeds.default_import_past_months')),
                    'total_imported' => (int) ($feed['total_imported'] ?? 0),
                    'last_synced_human' => $lastSyncedAt?->diffForHumans() ?? __('Never'),
                ];
            })
            ->values()
            ->all();
    }

    protected function getParsedSelectedDate(): CarbonInterface
    {
        if ($this->parsedSelectedDate === null) {
            $this->parsedSelectedDate = \Carbon\Carbon::parse($this->selectedDate);
        }

        return $this->parsedSelectedDate;
    }

    private function normalizeAnalyticsPreset(string $preset): string
    {
        $normalizedPreset = strtolower(trim($preset));

        if (in_array($normalizedPreset, self::ANALYTICS_PRESETS, true)) {
            return $normalizedPreset;
        }

        return match ($normalizedPreset) {
            '7d' => 'daily',
            '30d' => 'weekly',
            '90d', 'this_month' => 'monthly',
            default => 'daily',
        };
    }

    private function resolveUrgencyLevel(?string $priority, ?string $endsAt, ?string $status): string
    {
        if ($endsAt !== null) {
            try {
                $deadline = \Carbon\Carbon::parse($endsAt);
                if ($deadline->isPast() || $deadline->isToday()) {
                    return 'critical';
                }
                $daysUntilDue = now()->startOfDay()->diffInDays($deadline->copy()->startOfDay(), false);
                if ($daysUntilDue >= 1 && $daysUntilDue <= 3) {
                    return 'high';
                }
            } catch (\Throwable) {
                // Fall through to priority-based urgency when parsing fails.
            }
        }

        $priorityUrgency = match ($priority) {
            'urgent' => 'critical',
            'high' => 'high',
            default => 'normal',
        };

        if ($priorityUrgency !== 'normal') {
            return $priorityUrgency;
        }

        if ($status === TaskStatus::Doing->value) {
            return 'high';
        }

        return 'normal';
    }

    /**
     * @return array{0: CarbonInterface|null, 1: CarbonInterface|null}
     */
    private function resolveDashboardSchoolClassDateWindow(SchoolClass $class, CarbonInterface $selectedDate): array
    {
        $startsAt = null;
        $endsAt = null;

        if ($class->start_time !== null) {
            $startsAt = $selectedDate->copy()->setTimeFromTimeString((string) $class->start_time);
        } elseif ($class->start_datetime !== null) {
            $startsAt = $class->start_datetime->copy();
        }

        if ($class->end_time !== null) {
            $endsAt = $selectedDate->copy()->setTimeFromTimeString((string) $class->end_time);
        } elseif ($class->end_datetime !== null) {
            $endsAt = $class->end_datetime->copy();
        }

        if ($startsAt !== null && $endsAt !== null && $endsAt->lessThanOrEqualTo($startsAt)) {
            $endsAt = $endsAt->addDay();
        }

        return [$startsAt, $endsAt];
    }

    private function formatDashboardSchoolClassTimeLabel(?CarbonInterface $startsAt, ?CarbonInterface $endsAt): string
    {
        if ($startsAt === null || $endsAt === null) {
            return (string) __('No time');
        }

        return $startsAt->translatedFormat('g:i A').' - '.$endsAt->translatedFormat('g:i A');
    }

    private function formatScheduledFocusTimeRange(?CarbonInterface $startAt, ?CarbonInterface $endAt): string
    {
        if (! $startAt) {
            return (string) __('No time set');
        }

        $prefix = $startAt->isToday()
            ? __('Today')
            : ($startAt->isTomorrow() ? __('Tomorrow') : $startAt->translatedFormat('M j, Y'));
        $time = $startAt->format('g:i A');
        if (! $endAt) {
            return sprintf('%s %s %s', $prefix, __('at'), $time);
        }

        return sprintf('%s %s %s - %s', $prefix, __('at'), $time, $endAt->format('g:i A'));
    }

    private function formatScheduledFocusDuration(?int $minutes): ?string
    {
        if ($minutes === null || $minutes <= 0) {
            return null;
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;
        if ($hours === 0) {
            return trans_choice(':count minute|:count minutes', $remainingMinutes, ['count' => $remainingMinutes]);
        }
        if ($remainingMinutes === 0) {
            return trans_choice(':count hour|:count hours', $hours, ['count' => $hours]);
        }

        return trans_choice(':count hour|:count hours', $hours, ['count' => $hours]).' '
            .trans_choice(':count minute|:count minutes', $remainingMinutes, ['count' => $remainingMinutes]);
    }

    /**
     * @return array<int, string>
     */
    private static function collaborationActivityActions(): array
    {
        return [
            ActivityLogAction::CollaboratorInvited->value,
            ActivityLogAction::CollaboratorInvitationAccepted->value,
            ActivityLogAction::CollaboratorInvitationDeclined->value,
            ActivityLogAction::CollaboratorLeft->value,
            ActivityLogAction::CollaboratorRemoved->value,
            ActivityLogAction::CollaboratorPermissionUpdated->value,
        ];
    }

    protected function requireAuth(string $message): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            $this->dispatch('toast', type: 'error', message: $message);

            return null;
        }

        return $user;
    }
};

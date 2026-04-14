<?php

use App\Data\Analytics\DashboardAnalyticsOverview;
use App\Enums\ActivityLogAction;
use App\Enums\TaskSourceType;
use App\Enums\TaskStatus;
use App\Models\ActivityLog;
use App\Models\CalendarFeed;
use App\Models\Collaboration;
use App\Models\CollaborationInvitation;
use App\Models\Event;
use App\Models\FocusSession;
use App\Models\LlmToolCall;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Livewire\Concerns\HandlesCalendarFeeds;
use App\Livewire\Concerns\HandlesWorkspaceCalendar;
use App\Models\User;
use App\Services\LLM\Prioritization\AssistantCandidateProvider;
use App\Services\LLM\Prioritization\TaskPrioritizationService;
use App\Services\TaskService;
use App\Services\UserAnalyticsService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

new
#[Title('Dashboard')]
class extends Component
{
    use AuthorizesRequests;
    use HandlesCalendarFeeds;
    use HandlesWorkspaceCalendar;

    private const ANALYTICS_PRESETS = ['daily', 'weekly', 'monthly'];

    private const AT_A_GLANCE_LIMIT = 7;
    /** Maximum rows shown in the Urgent Now panel before “See all”. */
    private const URGENT_NOW_DISPLAY_LIMIT = 3;

    /** Ranked items loaded; one past display limit signals additional items exist. */
    private const URGENT_NOW_PREVIEW_LIMIT = 4;
    private const PROJECT_HEALTH_LIMIT = 5;
    private const COLLAB_ACTIVITY_LIMIT = 6;
    private const COLLAB_INVITES_LIMIT = 5;
    private const FEED_HEALTH_LIMIT = 5;
    private const NO_DATE_BACKLOG_LIMIT = 7;
    private const RECURRING_DUE_LIMIT = 7;
    private const LLM_RECENT_THREADS_LIMIT = 5;

    #[Url(as: 'date')]
    public ?string $selectedDate = null;

    #[Url(as: 'preset')]
    public string $analyticsPreset = 'daily';

    public string $trendPreset = 'daily';
    public bool $insightsOpen = false;

    /**
     * Cached parsed date to avoid parsing multiple times.
     * Cleared when selectedDate changes.
     */
    protected ?CarbonInterface $parsedSelectedDate = null;

    protected UserAnalyticsService $userAnalyticsService;
    protected TaskService $taskService;
    protected AssistantCandidateProvider $assistantCandidateProvider;
    protected TaskPrioritizationService $taskPrioritizationService;

    public function boot(
        UserAnalyticsService $userAnalyticsService,
        TaskService $taskService,
        AssistantCandidateProvider $assistantCandidateProvider,
        TaskPrioritizationService $taskPrioritizationService
    ): void
    {
        $this->userAnalyticsService = $userAnalyticsService;
        $this->taskService = $taskService;
        $this->assistantCandidateProvider = $assistantCandidateProvider;
        $this->taskPrioritizationService = $taskPrioritizationService;
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

        $cacheKey = sprintf(
            'dashboard:trend-analytics:%d:%s:%s',
            $user->id,
            $this->trendPreset,
            now()->format('YmdHi')
        );

        /** @var DashboardAnalyticsOverview $overview */
        $overview = Cache::remember(
            $cacheKey,
            now()->addSeconds(60),
            fn (): DashboardAnalyticsOverview => $this->userAnalyticsService->dashboardOverview(
                user: $user,
                preset: $this->trendPreset,
                anchor: $this->analyticsAnchor(),
            )
        );

        return $overview;
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

    #[Computed]
    public function dashboardIncompleteTasksCount(): int
    {
        $userId = Auth::id();
        if ($userId === null) {
            return 0;
        }

        return Task::query()
            ->forUser($userId)
            ->incomplete()
            ->count();
    }

    #[Computed]
    public function dashboardTodoTasksCount(): int
    {
        $userId = Auth::id();
        if ($userId === null) {
            return 0;
        }

        return Task::query()
            ->forUser($userId)
            ->incomplete()
            ->where('status', TaskStatus::ToDo)
            ->count();
    }

    #[Computed]
    public function dashboardTotalTasksCount(): int
    {
        $userId = Auth::id();
        if ($userId === null) {
            return 0;
        }

        return Task::query()
            ->forUser($userId)
            ->count();
    }

    #[Computed]
    public function dashboardCompletedTasksCount(): int
    {
        $userId = Auth::id();
        if ($userId === null) {
            return 0;
        }

        return Task::query()
            ->forUser($userId)
            ->whereNotNull('completed_at')
            ->count();
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
        $userId = Auth::id();
        if ($userId === null) {
            return 0;
        }

        $now = now();

        return Task::query()
            ->forUser($userId)
            ->incomplete()
            ->overdue($now)
            ->whereDoesntHave('recurringTask')
            ->count();
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
        $userId = Auth::id();
        if ($userId === null) {
            return 0;
        }

        $startOfDay = $this->getParsedSelectedDate()->copy()->startOfDay();
        $endOfDay = $this->getParsedSelectedDate()->copy()->endOfDay();

        return Task::query()
            ->forUser($userId)
            ->incomplete()
            ->whereNotNull('end_datetime')
            ->whereBetween('end_datetime', [$startOfDay, $endOfDay])
            ->whereDoesntHave('recurringTask')
            ->count();
    }

    /**
     * @return EloquentCollection<int, Task>
     */
    #[Computed]
    public function dashboardRecurringDueTasks(): EloquentCollection
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
                ->take(self::RECURRING_DUE_LIMIT)
                ->values()
                ->all()
        );

        return $processedTasks;
    }

    #[Computed]
    public function dashboardRecurringDueCount(): int
    {
        return $this->dashboardRecurringDueTasks->count();
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
            ->with(['project'])
            ->forUser($userId)
            ->incomplete()
            ->withNoDate()
            ->whereDoesntHave('recurringTask')
            ->orderByPriority()
            ->orderByDesc('updated_at')
            ->limit(self::NO_DATE_BACKLOG_LIMIT)
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
        $ranked = Cache::remember(
            'dashboard:urgent-now:'.$user->id,
            now()->addSeconds(45),
            fn () => $this->taskPrioritizationService->prioritizeFocus($snapshot, [])
        );
        $taskCandidates = collect($ranked)
            ->filter(fn (array $row): bool => ($row['type'] ?? null) === 'task')
            ->values();
        $fallbackCandidates = collect($ranked)
            ->filter(fn (array $row): bool => ($row['type'] ?? null) !== 'task')
            ->values();
        $prioritizedForDashboard = $taskCandidates->isNotEmpty()
            ? $taskCandidates
            : $fallbackCandidates;

        return $prioritizedForDashboard
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
                $workspaceParams = [
                    'date' => $this->getParsedSelectedDate()->toDateString(),
                    'view' => 'list',
                    'type' => 'tasks',
                ];
                if ($itemType === 'task' && $itemId > 0) {
                    $workspaceParams['task'] = $itemId;
                } elseif ($priority !== null && $priority !== '') {
                    $workspaceParams['priority'] = $priority;
                }

                return [
                    'type' => $itemType,
                    'id' => $itemId,
                    'title' => (string) ($item['title'] ?? __('Untitled')),
                    'score' => (int) ($item['score'] ?? 0),
                    'reasoning' => (string) ($item['reasoning'] ?? ''),
                    'priority' => $priority,
                    'complexity' => $complexity,
                    'ends_at' => $endsAt,
                    'urgency_level' => $urgencyLevel,
                    'workspace_url' => route('workspace', $workspaceParams),
                ];
            })
            ->values();
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

        $rows = $this->rememberMetric(
            key: sprintf('project-health:%d:%s', $userId, $this->getParsedSelectedDate()->toDateString()),
            ttlSeconds: 60,
            callback: function () use ($userId): array {
                $now = now();
                $soonThreshold = $now->copy()->addDays(3);

                return Project::query()
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
                            'workspace_url' => route('workspace', [
                                'date' => $this->getParsedSelectedDate()->toDateString(),
                                'view' => 'list',
                                'type' => 'projects',
                                'project' => $project->id,
                            ]),
                        ];
                    })
                    ->values()
                    ->all();
            }
        );

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

        return $this->rememberMetric(
            key: sprintf('focus-throughput:%d:%s', $userId, $this->getParsedSelectedDate()->toDateString()),
            ttlSeconds: 60,
            callback: function () use ($userId): array {
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
        );
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
     *   last_synced_at: string|null,
     *   status: string,
     *   total_imported: int,
     *   updated_last_24h: int,
     *   latest_import_activity_at: string|null
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
            ->get(['id', 'name', 'source', 'sync_enabled', 'last_synced_at', 'created_at']);

        if ($feeds->isEmpty()) {
            return collect();
        }

        $feedIds = $feeds->pluck('id')->all();

        /** @var Collection<int, object{calendar_feed_id: int, total_imported: int|string, updated_last_24h: int|string, latest_import_activity_at: string|null}> $taskStats */
        $taskStats = Task::query()
            ->selectRaw('calendar_feed_id, COUNT(*) as total_imported')
            ->selectRaw('SUM(CASE WHEN updated_at >= ? THEN 1 ELSE 0 END) as updated_last_24h', [now()->subDay()])
            ->selectRaw('MAX(updated_at) as latest_import_activity_at')
            ->whereIn('calendar_feed_id', $feedIds)
            ->where('source_type', TaskSourceType::Brightspace->value)
            ->groupBy('calendar_feed_id')
            ->get()
            ->keyBy('calendar_feed_id');

        return $feeds->map(function (CalendarFeed $feed) use ($taskStats): array {
            $status = $this->resolveFeedHealthStatus((bool) $feed->sync_enabled, $feed->last_synced_at);
            $stats = $taskStats->get($feed->id);

            return [
                'id' => $feed->id,
                'name' => (string) ($feed->name ?: __('Untitled feed')),
                'source' => (string) $feed->source,
                'sync_enabled' => (bool) $feed->sync_enabled,
                'last_synced_at' => $feed->last_synced_at?->toIso8601String(),
                'status' => $status,
                'status_rank' => $this->resolveFeedHealthStatusRank($status),
                'total_imported' => (int) ($stats?->total_imported ?? 0),
                'updated_last_24h' => (int) ($stats?->updated_last_24h ?? 0),
                'latest_import_activity_at' => $stats?->latest_import_activity_at,
            ];
        })
            ->sortBy(fn (array $row): array => [
                (int) ($row['status_rank'] ?? 99),
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
                $latestImportAt = $feed['latest_import_activity_at'] ? \Carbon\Carbon::parse($feed['latest_import_activity_at']) : null;

                return [
                    'id' => (int) $feed['id'],
                    'name' => (string) $feed['name'],
                    'source' => (string) $feed['source'],
                    'source_label' => ucfirst((string) $feed['source']),
                    'status' => (string) $feed['status'],
                    'status_label' => match ((string) $feed['status']) {
                        'fresh' => __('Fresh'),
                        'stale' => __('Stale'),
                        'critical' => __('Critical'),
                        'sync_off' => __('Sync Off'),
                        default => __('Never Synced'),
                    },
                    'total_imported' => (int) ($feed['total_imported'] ?? 0),
                    'updated_last_24h' => (int) ($feed['updated_last_24h'] ?? 0),
                    'last_synced_human' => $lastSyncedAt?->diffForHumans() ?? __('Never'),
                    'latest_import_activity_human' => $latestImportAt?->diffForHumans(),
                    'latest_import_activity_title' => $latestImportAt?->translatedFormat('M j, Y · H:i'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{
     *   total_threads: int,
     *   recent_threads: int,
     *   pending_tool_calls: int,
     *   successful_tool_calls: int,
     *   failed_tool_calls: int
     * }
     */
    #[Computed]
    public function llmActivity(): array
    {
        $userId = Auth::id();
        if ($userId === null) {
            return [
                'total_threads' => 0,
                'recent_threads' => 0,
                'pending_tool_calls' => 0,
                'successful_tool_calls' => 0,
                'failed_tool_calls' => 0,
            ];
        }

        return $this->rememberMetric(
            key: sprintf('llm-activity:%d', $userId),
            ttlSeconds: 60,
            callback: function () use ($userId): array {
                $totalThreads = TaskAssistantThread::query()
                    ->where('user_id', $userId)
                    ->count();

                $recentThreads = TaskAssistantThread::query()
                    ->where('user_id', $userId)
                    ->latest('updated_at')
                    ->limit(self::LLM_RECENT_THREADS_LIMIT)
                    ->count();

                $toolCallCounts = LlmToolCall::query()
                    ->selectRaw('status, COUNT(*) as aggregate')
                    ->where('user_id', $userId)
                    ->whereIn('status', ['pending', 'success', 'failed'])
                    ->groupBy('status')
                    ->pluck('aggregate', 'status');

                return [
                    'total_threads' => $totalThreads,
                    'recent_threads' => $recentThreads,
                    'pending_tool_calls' => (int) ($toolCallCounts['pending'] ?? 0),
                    'successful_tool_calls' => (int) ($toolCallCounts['success'] ?? 0),
                    'failed_tool_calls' => (int) ($toolCallCounts['failed'] ?? 0),
                ];
            }
        );
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

    private function resolveFeedHealthStatus(bool $syncEnabled, ?\Carbon\CarbonInterface $lastSyncedAt): string
    {
        if (! $syncEnabled) {
            return 'sync_off';
        }

        if ($lastSyncedAt === null) {
            return 'never_synced';
        }

        $minutesSinceSync = $lastSyncedAt->diffInMinutes(now());

        if ($minutesSinceSync <= 90) {
            return 'fresh';
        }

        if ($minutesSinceSync <= (24 * 60)) {
            return 'stale';
        }

        return 'critical';
    }

    private function resolveFeedHealthStatusRank(string $status): int
    {
        return match ($status) {
            'critical' => 0,
            'stale' => 1,
            'sync_off' => 2,
            'never_synced' => 3,
            'fresh' => 4,
            default => 5,
        };
    }

    /**
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    private function rememberMetric(string $key, int $ttlSeconds, callable $callback): mixed
    {
        return Cache::remember(
            'dashboard:metric:'.$key,
            now()->addSeconds($ttlSeconds),
            $callback
        );
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
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
use App\Models\User;
use App\Services\LLM\Prioritization\AssistantCandidateProvider;
use App\Services\LLM\Prioritization\TaskPrioritizationService;
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
    private const CALENDAR_LOAD_WINDOW_HOURS = 24;
    private const LLM_RECENT_THREADS_LIMIT = 5;
    private const CALENDAR_META_MAX_ITEMS = 400;
    private const SELECTED_DAY_AGENDA_TASK_LIMIT = 120;
    private const SELECTED_DAY_AGENDA_EVENT_LIMIT = 120;
    private const UPCOMING_LIMIT_PER_KIND = 25;

    #[Url(as: 'date')]
    public ?string $selectedDate = null;

    #[Url(as: 'preset')]
    public string $analyticsPreset = 'daily';

    public string $trendPreset = 'daily';

    #[Url(as: 'calendar_source')]
    public string $calendarSourceFilter = 'all';

    /**
     * Cached parsed date to avoid parsing multiple times.
     * Cleared when selectedDate changes.
     */
    protected ?CarbonInterface $parsedSelectedDate = null;

    protected UserAnalyticsService $userAnalyticsService;
    protected AssistantCandidateProvider $assistantCandidateProvider;
    protected TaskPrioritizationService $taskPrioritizationService;

    public function boot(
        UserAnalyticsService $userAnalyticsService,
        AssistantCandidateProvider $assistantCandidateProvider,
        TaskPrioritizationService $taskPrioritizationService
    ): void
    {
        $this->userAnalyticsService = $userAnalyticsService;
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
        $this->calendarSourceFilter = $this->normalizeCalendarSourceFilter($this->calendarSourceFilter);
    }

    public function updatedSelectedDate(): void
    {
        $this->parsedSelectedDate = null;
    }

    public function updatedCalendarSourceFilter(string $value): void
    {
        $this->calendarSourceFilter = $this->normalizeCalendarSourceFilter($value);
    }

    public function setCalendarSourceFilter(string $filter): void
    {
        $this->calendarSourceFilter = $this->normalizeCalendarSourceFilter($filter);
    }

    public function navigateSelectedDate(int $offsetDays): void
    {
        $this->selectedDate = $this->getParsedSelectedDate()->copy()->addDays($offsetDays)->toDateString();
    }

    public function jumpSelectedDateToToday(): void
    {
        $this->selectedDate = now()->toDateString();
    }

    public function setAnalyticsPreset(string $preset): void
    {
        $this->analyticsPreset = $this->normalizeAnalyticsPreset($preset);
    }
    
    public function setTrendPreset(string $preset): void
    {
        $this->trendPreset = $this->normalizeAnalyticsPreset($preset);
    }

    #[Computed]
    public function analytics(): ?DashboardAnalyticsOverview
    {
        $user = Auth::user();
        if ($user === null) {
            return null;
        }

        return $this->userAnalyticsService->dashboardOverview(
            user: $user,
            preset: $this->analyticsPreset,
            anchor: $this->analyticsAnchor(),
        );
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
        return route('workspace', ['date' => now()->toDateString()]);
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

        return Task::query()
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
            ->limit(self::AT_A_GLANCE_LIMIT)
            ->get();
    }

    #[Computed]
    public function dashboardDoingTasksCount(): int
    {
        $userId = Auth::id();
        if ($userId === null) {
            return 0;
        }

        return Task::query()
            ->forUser($userId)
            ->incomplete()
            ->where('status', TaskStatus::Doing)
            ->whereDoesntHave('recurringTask')
            ->count();
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

        $startOfDay = now()->startOfDay();
        $endOfDay = now()->copy()->endOfDay();

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

        $startOfDay = now()->startOfDay();
        $endOfDay = now()->copy()->endOfDay();

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

        $startOfDay = now()->startOfDay();
        $endOfDay = now()->copy()->endOfDay();

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

        $startOfDay = now()->startOfDay();
        $endOfDay = now()->copy()->endOfDay();

        return Event::query()
            ->forUser($userId)
            ->notCancelled()
            ->notCompleted()
            ->whereDoesntHave('recurringEvent')
            ->whereNotNull('start_datetime')
            ->whereBetween('start_datetime', [$startOfDay, $endOfDay])
            ->count();
    }

    #[Computed]
    public function calendarMonth(): int
    {
        return $this->getParsedSelectedDate()->month;
    }

    #[Computed]
    public function calendarYear(): int
    {
        return $this->getParsedSelectedDate()->year;
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{kind: string, item: mixed}>
     */
    #[Computed]
    public function upcoming(): Collection
    {
        $userId = Auth::id();

        if ($userId === null) {
            return collect();
        }

        $fromDate = now()->startOfDay();
        $days = 7;

        $entries = collect();

        $upcomingTasks = Task::query()
            ->forUser($userId)
            ->dueSoon($fromDate, $days)
            ->whereDoesntHave('recurringTask')
            ->orderBy('end_datetime')
            ->limit(self::UPCOMING_LIMIT_PER_KIND)
            ->get()
            ->map(fn (Task $task) => ['kind' => 'task', 'item' => $task]);

        $entries = $entries->merge($upcomingTasks);

        $upcomingEvents = Event::query()
            ->forUser($userId)
            ->startingSoon($fromDate, $days)
            ->whereDoesntHave('recurringEvent')
            ->notCancelled()
            ->orderBy('start_datetime')
            ->limit(self::UPCOMING_LIMIT_PER_KIND)
            ->get()
            ->map(fn (Event $event) => ['kind' => 'event', 'item' => $event]);

        $entries = $entries->merge($upcomingEvents);

        $upcomingProjects = Project::query()
            ->forUser($userId)
            ->startingSoon($fromDate, $days)
            ->notArchived()
            ->orderBy('start_datetime')
            ->limit(self::UPCOMING_LIMIT_PER_KIND)
            ->get()
            ->map(fn (Project $project) => ['kind' => 'project', 'item' => $project]);

        $entries = $entries->merge($upcomingProjects);

        return $entries
            ->sortBy(function (array $entry): int {
                /** @var \App\Models\Task|\App\Models\Event|\App\Models\Project $item */
                $item = $entry['item'];

                return match ($entry['kind']) {
                    'task' => $item->end_datetime?->timestamp ?? PHP_INT_MAX,
                    'event', 'project' => $item->start_datetime?->timestamp ?? PHP_INT_MAX,
                    default => PHP_INT_MAX,
                };
            })
            ->values();
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

        $monthStart = $this->getParsedSelectedDate()->copy()->startOfMonth();
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

        $tasks = $this->applyTaskSourceFilter(
            Task::query()
                ->forUser($userId)
                ->incomplete()
                ->with('recurringTask')
        )
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

            if (
                in_array($task->priority?->value, [\App\Enums\TaskPriority::Urgent->value, \App\Enums\TaskPriority::High->value], true)
            ) {
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

        $tasks = $this->applyTaskSourceFilter(
            Task::query()
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
        )
            ->orderByPriority()
            ->orderBy('end_datetime')
            ->limit(self::SELECTED_DAY_AGENDA_TASK_LIMIT)
            ->get(['id', 'title', 'priority', 'start_datetime', 'end_datetime']);

        $urgentTasks = $tasks
            ->filter(function (Task $task) use ($selectedDate): bool {
                return in_array($task->priority?->value, [\App\Enums\TaskPriority::Urgent->value, \App\Enums\TaskPriority::High->value], true)
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

    /**
     * @return Collection<int, array{
     *   type: string,
     *   id: int,
     *   title: string,
     *   score: int,
     *   reasoning: string,
     *   priority: string|null,
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
                $endsAt = is_string($raw['ends_at'] ?? null) ? (string) $raw['ends_at'] : null;
                $urgencyLevel = $this->resolveUrgencyLevel($priority, $endsAt);
                $workspaceParams = ['date' => now()->toDateString(), 'type' => 'tasks'];
                if ($priority !== null && $priority !== '') {
                    $workspaceParams['priority'] = $priority;
                }

                return [
                    'type' => (string) ($item['type'] ?? 'task'),
                    'id' => (int) ($item['id'] ?? 0),
                    'title' => (string) ($item['title'] ?? __('Untitled')),
                    'score' => (int) ($item['score'] ?? 0),
                    'reasoning' => (string) ($item['reasoning'] ?? ''),
                    'priority' => $priority,
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
                        'date' => now()->toDateString(),
                        'type' => 'projects',
                        'q' => $project->name,
                    ]),
                ];
            })
            ->values();
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

        $todayStart = now()->startOfDay();
        $weekStart = now()->startOfWeek();
        $weekEnd = now()->endOfWeek();

        $dailyWorkSeconds = FocusSession::query()
            ->forUser($userId)
            ->work()
            ->completed()
            ->whereBetween('started_at', [$todayStart, now()])
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
            ->whereBetween('completed_at', [$todayStart, now()->endOfDay()])
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
     *   window_hours: int,
     *   events_in_window: int,
     *   all_day_events: int,
     *   overlap_conflicts: int,
     *   busy_minutes: int,
     *   free_minutes: int
     * }
     */
    #[Computed]
    public function calendarLoadInsights(): array
    {
        $userId = Auth::id();
        if ($userId === null) {
            return [
                'window_hours' => self::CALENDAR_LOAD_WINDOW_HOURS,
                'events_in_window' => 0,
                'all_day_events' => 0,
                'overlap_conflicts' => 0,
                'busy_minutes' => 0,
                'free_minutes' => self::CALENDAR_LOAD_WINDOW_HOURS * 60,
            ];
        }

        $windowStart = now();
        $windowEnd = now()->copy()->addHours(self::CALENDAR_LOAD_WINDOW_HOURS);

        $events = Event::query()
            ->forUser($userId)
            ->notCancelled()
            ->notCompleted()
            ->whereNotNull('start_datetime')
            ->where('start_datetime', '<', $windowEnd)
            ->where(function ($query) use ($windowStart): void {
                $query->whereNull('end_datetime')
                    ->orWhere('end_datetime', '>', $windowStart);
            })
            ->orderBy('start_datetime')
            ->get(['id', 'start_datetime', 'end_datetime', 'all_day']);

        $eventsCount = $events->count();
        $allDayCount = $events->where('all_day', true)->count();

        $overlapConflicts = 0;
        $busyMinutes = 0;
        $eventWindows = [];

        foreach ($events as $event) {
            if ($event->all_day) {
                $busyMinutes += self::CALENDAR_LOAD_WINDOW_HOURS * 60;
                continue;
            }

            $start = $event->start_datetime?->copy();
            $end = $event->end_datetime?->copy() ?? $event->start_datetime?->copy()->addHour();
            if ($start === null || $end === null) {
                continue;
            }

            if ($end->lessThanOrEqualTo($windowStart) || $start->greaterThanOrEqualTo($windowEnd)) {
                continue;
            }

            $clampedStart = $start->lessThan($windowStart) ? $windowStart->copy() : $start;
            $clampedEnd = $end->greaterThan($windowEnd) ? $windowEnd->copy() : $end;
            $minutes = max(0, (int) $clampedEnd->diffInMinutes($clampedStart));
            $busyMinutes += $minutes;

            $eventWindows[] = [$clampedStart, $clampedEnd];
        }

        usort($eventWindows, static function (array $left, array $right): int {
            return $left[0]->getTimestamp() <=> $right[0]->getTimestamp();
        });

        for ($i = 1; $i < count($eventWindows); $i++) {
            if ($eventWindows[$i][0]->lessThan($eventWindows[$i - 1][1])) {
                $overlapConflicts++;
            }
        }

        $windowMinutes = self::CALENDAR_LOAD_WINDOW_HOURS * 60;
        $busyMinutes = min($busyMinutes, $windowMinutes);

        return [
            'window_hours' => self::CALENDAR_LOAD_WINDOW_HOURS,
            'events_in_window' => $eventsCount,
            'all_day_events' => $allDayCount,
            'overlap_conflicts' => $overlapConflicts,
            'busy_minutes' => $busyMinutes,
            'free_minutes' => max(0, $windowMinutes - $busyMinutes),
        ];
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

    private function getParsedSelectedDate(): CarbonInterface
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

    private function normalizeCalendarSourceFilter(string $filter): string
    {
        $normalized = strtolower(trim($filter));

        return in_array($normalized, ['all', 'manual', 'imported'], true) ? $normalized : 'all';
    }

    private function applyTaskSourceFilter(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return match ($this->calendarSourceFilter) {
            'manual' => $query->where(function ($sourceQuery): void {
                $sourceQuery->whereNull('source_type')
                    ->orWhere('source_type', TaskSourceType::Manual->value);
            }),
            'imported' => $query->whereNotNull('source_type')
                ->where('source_type', '!=', TaskSourceType::Manual->value),
            default => $query,
        };
    }

    private function resolveUrgencyLevel(?string $priority, ?string $endsAt): string
    {
        if ($endsAt !== null) {
            try {
                $deadline = \Carbon\Carbon::parse($endsAt);
                if ($deadline->isPast() || $deadline->isToday()) {
                    return 'critical';
                }
                if ($deadline->isTomorrow()) {
                    return 'high';
                }
            } catch (\Throwable) {
                // Fall through to priority-based urgency when parsing fails.
            }
        }

        return match ($priority) {
            'urgent' => 'critical',
            'high' => 'high',
            default => 'normal',
        };
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
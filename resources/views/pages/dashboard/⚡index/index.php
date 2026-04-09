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
use App\Models\Project;
use App\Models\Task;
use App\Livewire\Concerns\HandlesCalendarFeeds;
use App\Models\User;
use App\Services\LLM\Prioritization\AssistantCandidateProvider;
use App\Services\LLM\Prioritization\TaskPrioritizationService;
use App\Services\UserAnalyticsService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
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
    private const FEED_HEALTH_LIMIT = 5;

    #[Url(as: 'date')]
    public ?string $selectedDate = null;

    #[Url(as: 'preset')]
    public string $analyticsPreset = 'daily';
    
    public string $trendPreset = 'daily';

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
    }

    public function updatedSelectedDate(): void
    {
        $this->parsedSelectedDate = null;
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
            ->limit(50)
            ->get()
            ->map(fn (Task $task) => ['kind' => 'task', 'item' => $task]);

        $entries = $entries->merge($upcomingTasks);

        $upcomingEvents = Event::query()
            ->forUser($userId)
            ->startingSoon($fromDate, $days)
            ->whereDoesntHave('recurringEvent')
            ->notCancelled()
            ->orderBy('start_datetime')
            ->limit(50)
            ->get()
            ->map(fn (Event $event) => ['kind' => 'event', 'item' => $event]);

        $entries = $entries->merge($upcomingEvents);

        $upcomingProjects = Project::query()
            ->forUser($userId)
            ->startingSoon($fromDate, $days)
            ->notArchived()
            ->orderBy('start_datetime')
            ->limit(50)
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
            taskLimit: 120,
            eventLimit: 20,
            projectLimit: 20,
        );

        $ranked = $this->taskPrioritizationService->prioritizeFocus($snapshot, []);
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
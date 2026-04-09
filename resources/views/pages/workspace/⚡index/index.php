<?php

use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Models\Event;
use App\Models\Project;
use App\Models\CalendarFeed;
use Livewire\Component;
use App\Enums\TaskSourceType;
use App\Services\TagService;
use Livewire\Attributes\Url;
use App\Services\TaskService;
use App\Services\EventService;
use Livewire\Attributes\Async;
use Livewire\Attributes\Title;
use App\Services\ProjectService;
use Livewire\Attributes\Computed;
use Illuminate\Support\Collection;
use App\Actions\Tag\CreateTagAction;
use App\Actions\Tag\DeleteTagAction;
use Illuminate\Support\Facades\Auth;
use App\Actions\Task\CreateTaskAction;
use App\Actions\Task\DeleteTaskAction;
use App\Livewire\Concerns\HandlesTags;
use App\Actions\Task\RestoreTaskAction;
use App\Livewire\Concerns\HandlesTasks;
use App\Livewire\Concerns\HandlesTrash;
use App\Actions\Event\CreateEventAction;
use App\Actions\Event\DeleteEventAction;
use App\Livewire\Concerns\HandlesEvents;
use App\Actions\Event\RestoreEventAction;
use App\Livewire\Concerns\HandlesComments;
use App\Livewire\Concerns\HandlesProjects;
use App\Actions\Task\ForceDeleteTaskAction;
use App\Livewire\Concerns\HandlesFiltering;
use App\Actions\Comment\CreateCommentAction;
use App\Actions\Comment\DeleteCommentAction;
use App\Actions\Comment\UpdateCommentAction;
use App\Actions\Project\CreateProjectAction;
use App\Actions\Project\DeleteProjectAction;
use App\Actions\Event\ForceDeleteEventAction;
use App\Actions\Project\RestoreProjectAction;
use App\Actions\Task\UpdateTaskPropertyAction;
use App\Livewire\Concerns\HandlesActivityLogs;
use App\Actions\Task\CreateTaskExceptionAction;
use App\Actions\Task\DeleteTaskExceptionAction;
use App\Livewire\Concerns\HandlesCalendarFeeds;
use App\Livewire\Concerns\HandlesFocusSessions;
use App\Actions\Event\UpdateEventPropertyAction;
use App\Livewire\Concerns\HandlesCollaborations;
use App\Actions\Event\CreateEventExceptionAction;
use App\Actions\Event\DeleteEventExceptionAction;
use App\Actions\Project\ForceDeleteProjectAction;
use App\Livewire\Concerns\HandlesPomodoroSettings;
use App\Actions\Project\UpdateProjectPropertyAction;
use App\Actions\FocusSession\PauseFocusSessionAction;
use App\Actions\FocusSession\StartFocusSessionAction;
use App\Actions\FocusSession\ResumeFocusSessionAction;
use App\Actions\Pomodoro\UpdatePomodoroSettingsAction;
use App\Actions\FocusSession\AbandonFocusSessionAction;
use App\Actions\Pomodoro\CompletePomodoroSessionAction;
use App\Actions\Collaboration\DeleteCollaborationAction;
use App\Actions\FocusSession\CompleteFocusSessionAction;
use App\Actions\FocusSession\GetActiveFocusSessionAction;
use App\Actions\Pomodoro\GetPomodoroSequenceNumberAction;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use App\Actions\Pomodoro\GetNextPomodoroSessionTypeAction;
use App\Actions\Pomodoro\GetOrCreatePomodoroSettingsAction;
use App\Actions\Collaboration\AcceptCollaborationInvitationAction;
use App\Actions\Collaboration\CreateCollaborationInvitationAction;
use App\Actions\Collaboration\UpdateCollaborationPermissionAction;
use App\Actions\Collaboration\DeclineCollaborationInvitationAction;

new
#[Title('Workspace')]
class extends Component
{
    use AuthorizesRequests;
    use HandlesActivityLogs;
    use HandlesCollaborations;
    use HandlesComments;
    use HandlesCalendarFeeds;
    use HandlesEvents;
    use HandlesFiltering;
    use HandlesFocusSessions;
    use HandlesPomodoroSettings;
    use HandlesProjects;
    use HandlesTags;
    use HandlesTasks;
    use HandlesTrash;

    private const FEED_HEALTH_LIMIT = 5;

    #[Url(as: 'date')]
    public ?string $selectedDate = null;

    #[Url(as: 'view')]
    public string $viewMode = 'list';

    /**
     * Global item pagination for the workspace list (across tasks, events, projects).
     * Controls how many combined item cards are visible in the list component.
     */
    public int $itemsPerPage = 10;

    public int $itemsPage = 1;

    /**
     * Version bump to force nested list/kanban remount after mutations (create/delete/restore).
     * This is included in workspaceItemsFingerprint() to ensure wire:key changes when items change.
     */
    public int $workspaceItemsVersion = 0;

    /**
     * Optional list context: when set, task list shows only tasks in this project.
     * Authorized in HandlesTasks::tasks() before applying scope.
     */
    public ?int $listContextProjectId = null;

    /**
     * Optional list context: when set, task list shows only tasks in this event.
     * Authorized in HandlesTasks::tasks() before applying scope.
     */
    public ?int $listContextEventId = null;

    /**
     * Cached parsed date to avoid parsing multiple times.
     * Cleared when selectedDate changes.
     */
    protected ?\Carbon\CarbonInterface $parsedSelectedDate = null;

    /**
     * Current in-progress focus session for UI (resume/overlay). Synced on mount and after start/complete/abandon.
     *
     * @var array{id: int, started_at: string, duration_seconds: int, type: string, task_id: int|null, sequence_number: int, paused_seconds?: int, paused_at?: string|null, payload?: array}|null
     */
    public ?array $activeFocusSession = null;

    protected TaskService $taskService;

    protected ProjectService $projectService;

    protected EventService $eventService;

    protected TagService $tagService;

    protected CreateEventAction $createEventAction;

    protected CreateProjectAction $createProjectAction;

    protected CreateTagAction $createTagAction;

    protected CreateTaskAction $createTaskAction;

    protected DeleteEventAction $deleteEventAction;

    protected DeleteTagAction $deleteTagAction;

    protected DeleteProjectAction $deleteProjectAction;

    protected DeleteTaskAction $deleteTaskAction;

    protected ForceDeleteEventAction $forceDeleteEventAction;

    protected ForceDeleteProjectAction $forceDeleteProjectAction;

    protected ForceDeleteTaskAction $forceDeleteTaskAction;

    protected RestoreEventAction $restoreEventAction;

    protected RestoreProjectAction $restoreProjectAction;

    protected RestoreTaskAction $restoreTaskAction;

    protected UpdateEventPropertyAction $updateEventPropertyAction;

    protected UpdateProjectPropertyAction $updateProjectPropertyAction;

    protected UpdateTaskPropertyAction $updateTaskPropertyAction;

    protected CreateTaskExceptionAction $createTaskExceptionAction;

    protected DeleteTaskExceptionAction $deleteTaskExceptionAction;

    protected CreateEventExceptionAction $createEventExceptionAction;

    protected DeleteEventExceptionAction $deleteEventExceptionAction;

    protected CreateCommentAction $createCommentAction;

    protected UpdateCommentAction $updateCommentAction;

    protected DeleteCommentAction $deleteCommentAction;

    protected CreateCollaborationInvitationAction $createCollaborationInvitationAction;

    protected AcceptCollaborationInvitationAction $acceptCollaborationInvitationAction;

    protected DeclineCollaborationInvitationAction $declineCollaborationInvitationAction;

    protected UpdateCollaborationPermissionAction $updateCollaborationPermissionAction;

    protected DeleteCollaborationAction $deleteCollaborationAction;

    protected AbandonFocusSessionAction $abandonFocusSessionAction;

    protected CompleteFocusSessionAction $completeFocusSessionAction;

    protected GetActiveFocusSessionAction $getActiveFocusSessionAction;

    protected PauseFocusSessionAction $pauseFocusSessionAction;

    protected ResumeFocusSessionAction $resumeFocusSessionAction;

    protected StartFocusSessionAction $startFocusSessionAction;

    protected CompletePomodoroSessionAction $completePomodoroSessionAction;

    protected GetPomodoroSequenceNumberAction $getPomodoroSequenceNumberAction;

    protected GetNextPomodoroSessionTypeAction $getNextPomodoroSessionTypeAction;

    protected GetOrCreatePomodoroSettingsAction $getOrCreatePomodoroSettingsAction;

    protected UpdatePomodoroSettingsAction $updatePomodoroSettingsAction;

    /**
     * @var array<string, mixed>
     */
    public array $taskPayload = [];

    /**
     * @var array<string, mixed>
     */
    public array $eventPayload = [];

    /**
     * @var array<string, mixed>
     */
    public array $projectPayload = [];

    public function boot(
        TaskService $taskService,
        ProjectService $projectService,
        EventService $eventService,
        TagService $tagService,
        CreateEventAction $createEventAction,
        CreateProjectAction $createProjectAction,
        CreateTagAction $createTagAction,
        CreateTaskAction $createTaskAction,
        DeleteEventAction $deleteEventAction,
        DeleteProjectAction $deleteProjectAction,
        DeleteTagAction $deleteTagAction,
        DeleteTaskAction $deleteTaskAction,
        ForceDeleteEventAction $forceDeleteEventAction,
        ForceDeleteProjectAction $forceDeleteProjectAction,
        ForceDeleteTaskAction $forceDeleteTaskAction,
        RestoreEventAction $restoreEventAction,
        RestoreProjectAction $restoreProjectAction,
        RestoreTaskAction $restoreTaskAction,
        UpdateEventPropertyAction $updateEventPropertyAction,
        UpdateProjectPropertyAction $updateProjectPropertyAction,
        UpdateTaskPropertyAction $updateTaskPropertyAction,
        CreateTaskExceptionAction $createTaskExceptionAction,
        DeleteTaskExceptionAction $deleteTaskExceptionAction,
        CreateEventExceptionAction $createEventExceptionAction,
        DeleteEventExceptionAction $deleteEventExceptionAction,
        CreateCommentAction $createCommentAction,
        UpdateCommentAction $updateCommentAction,
        DeleteCommentAction $deleteCommentAction,
        CreateCollaborationInvitationAction $createCollaborationInvitationAction,
        AcceptCollaborationInvitationAction $acceptCollaborationInvitationAction,
        DeclineCollaborationInvitationAction $declineCollaborationInvitationAction,
        UpdateCollaborationPermissionAction $updateCollaborationPermissionAction,
        DeleteCollaborationAction $deleteCollaborationAction,
        AbandonFocusSessionAction $abandonFocusSessionAction,
        CompleteFocusSessionAction $completeFocusSessionAction,
        GetActiveFocusSessionAction $getActiveFocusSessionAction,
        PauseFocusSessionAction $pauseFocusSessionAction,
        ResumeFocusSessionAction $resumeFocusSessionAction,
        StartFocusSessionAction $startFocusSessionAction,
        GetOrCreatePomodoroSettingsAction $getOrCreatePomodoroSettingsAction,
        UpdatePomodoroSettingsAction $updatePomodoroSettingsAction,
        CompletePomodoroSessionAction $completePomodoroSessionAction,
        GetPomodoroSequenceNumberAction $getPomodoroSequenceNumberAction,
        GetNextPomodoroSessionTypeAction $getNextPomodoroSessionTypeAction
    ): void {
        $this->taskService = $taskService;
        $this->projectService = $projectService;
        $this->eventService = $eventService;
        $this->tagService = $tagService;
        $this->createEventAction = $createEventAction;
        $this->createProjectAction = $createProjectAction;
        $this->createTagAction = $createTagAction;
        $this->createTaskAction = $createTaskAction;
        $this->deleteEventAction = $deleteEventAction;
        $this->deleteProjectAction = $deleteProjectAction;
        $this->deleteTagAction = $deleteTagAction;
        $this->deleteTaskAction = $deleteTaskAction;
        $this->forceDeleteEventAction = $forceDeleteEventAction;
        $this->forceDeleteProjectAction = $forceDeleteProjectAction;
        $this->forceDeleteTaskAction = $forceDeleteTaskAction;
        $this->restoreEventAction = $restoreEventAction;
        $this->restoreProjectAction = $restoreProjectAction;
        $this->restoreTaskAction = $restoreTaskAction;
        $this->updateEventPropertyAction = $updateEventPropertyAction;
        $this->updateProjectPropertyAction = $updateProjectPropertyAction;
        $this->updateTaskPropertyAction = $updateTaskPropertyAction;
        $this->createTaskExceptionAction = $createTaskExceptionAction;
        $this->deleteTaskExceptionAction = $deleteTaskExceptionAction;
        $this->createEventExceptionAction = $createEventExceptionAction;
        $this->deleteEventExceptionAction = $deleteEventExceptionAction;
        $this->createCommentAction = $createCommentAction;
        $this->updateCommentAction = $updateCommentAction;
        $this->deleteCommentAction = $deleteCommentAction;
        $this->createCollaborationInvitationAction = $createCollaborationInvitationAction;
        $this->acceptCollaborationInvitationAction = $acceptCollaborationInvitationAction;
        $this->declineCollaborationInvitationAction = $declineCollaborationInvitationAction;
        $this->updateCollaborationPermissionAction = $updateCollaborationPermissionAction;
        $this->deleteCollaborationAction = $deleteCollaborationAction;
        $this->abandonFocusSessionAction = $abandonFocusSessionAction;
        $this->completeFocusSessionAction = $completeFocusSessionAction;
        $this->getActiveFocusSessionAction = $getActiveFocusSessionAction;
        $this->pauseFocusSessionAction = $pauseFocusSessionAction;
        $this->resumeFocusSessionAction = $resumeFocusSessionAction;
        $this->startFocusSessionAction = $startFocusSessionAction;
        $this->getOrCreatePomodoroSettingsAction = $getOrCreatePomodoroSettingsAction;
        $this->updatePomodoroSettingsAction = $updatePomodoroSettingsAction;
        $this->completePomodoroSessionAction = $completePomodoroSessionAction;
        $this->getPomodoroSequenceNumberAction = $getPomodoroSequenceNumberAction;
        $this->getNextPomodoroSessionTypeAction = $getNextPomodoroSessionTypeAction;
    }

    /**
     * Pomodoro settings for the authenticated user (passed to List for list-item-cards).
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function pomodoroSettings(): array
    {
        return $this->getPomodoroSettings();
    }

    /**
     * Calendar month for the selected date (computed to avoid parsing in Blade).
     */
    #[Computed]
    public function calendarMonth(): int
    {
        return $this->getParsedSelectedDate()->month;
    }

    /**
     * Calendar year for the selected date (computed to avoid parsing in Blade).
     */
    #[Computed]
    public function calendarYear(): int
    {
        return $this->getParsedSelectedDate()->year;
    }

    /**
     * Mount: restore any in-progress focus session so per-task focus progress
     * remains consistent across reloads and navigation.
     */
    public function mount(): void
    {
        if (Auth::check()) {
            $this->authorize('viewAny', Task::class);
            $this->authorize('viewAny', Event::class);
            $this->authorize('viewAny', Project::class);
            $this->authorize('viewAny', Tag::class);
        }
        if ($this->selectedDate === null || $this->selectedDate === '' || strtotime($this->selectedDate) === false) {
            $this->selectedDate = now()->toDateString();
        }
        if (! in_array($this->viewMode, ['list', 'kanban'], true)) {
            $this->viewMode = 'list';
        }
        $this->syncFilterTagIdFromTagIds();
        $this->activeFocusSession = $this->getActiveFocusSession();
    }

    /**
     * Reset list pagination to first page. Call when date or filters change
     * so the list shows page 1 of the new result set.
     */
    public function resetListPagination(): void
    {
        if (property_exists($this, 'tasksPage')) {
            $this->tasksPage = 1;
        }
        if (property_exists($this, 'eventsPage')) {
            $this->eventsPage = 1;
        }
        if (property_exists($this, 'projectsPage')) {
            $this->projectsPage = 1;
        }

        $this->itemsPage = 1;
    }

    /**
     * Force the nested list/kanban to remount with fresh model collections.
     * Keep mutations in the parent, but ensure the child receives updated props.
     */
    public function refreshWorkspaceItems(bool $resetPagination = true): void
    {
        if ($resetPagination) {
            $this->resetListPagination();
        }

        $this->workspaceItemsVersion++;
    }

    /**
     * When the selected date changes, reset pagination so we show page 1 for the new date.
     * Also clear the cached parsed date so it gets re-parsed.
     */
    public function updatedSelectedDate(): void
    {
        $this->parsedSelectedDate = null;
        $this->resetListPagination();
    }

    /**
     * Get the parsed selected date, caching it to avoid multiple parses.
     */
    protected function getParsedSelectedDate(): \Carbon\CarbonInterface
    {
        if ($this->parsedSelectedDate === null) {
            $this->parsedSelectedDate = \Carbon\Carbon::parse($this->selectedDate);
        }

        return $this->parsedSelectedDate;
    }

    /**
     * Fingerprint for nested list/kanban wire:key. Model collections are not #[Reactive] on those
     * children (Livewire reactive hash conflicts with Eloquent), so when date, filters, or list
     * context change this key must change to remount children with fresh tasks/events/projects/overdue.
     */
    public function workspaceItemsFingerprint(): string
    {
        return md5(json_encode([
            'date' => $this->selectedDate,
            'listContext' => [$this->listContextProjectId, $this->listContextEventId],
            'filters' => $this->getFilters(),
            'version' => $this->workspaceItemsVersion,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Load the next page of tasks, events, and projects (infinite scroll).
     * Pagination uses #[Reactive] itemsPage on the nested list; wire:key stays stable (fingerprint omits page).
     */
    #[Async]
    public function loadMoreItems(): void
    {
        if (property_exists($this, 'tasksPage')) {
            $this->tasksPage++;
        }
        if (property_exists($this, 'eventsPage')) {
            $this->eventsPage++;
        }
        if (property_exists($this, 'projectsPage')) {
            $this->projectsPage++;
        }

        $this->itemsPage++;
    }

    /**
     * Return HTML for the next page of list items only (append-only load more).
     * Skips full re-render so the existing list DOM is not replaced, keeping
     * dropdowns and other UI state intact and avoiding blocked clicks.
     *
     * @return array{html: string, hasMore: bool}
     */
    #[Async]
    public function getMoreItemsHtml(): array
    {
        if (property_exists($this, 'tasksPage')) {
            $this->tasksPage++;
        }
        if (property_exists($this, 'eventsPage')) {
            $this->eventsPage++;
        }
        if (property_exists($this, 'projectsPage')) {
            $this->projectsPage++;
        }

        $this->itemsPage++;

        $allItems = $this->getAllListEntries();
        $effectiveItemsPerPage = $this->itemsPerPage > 0 ? $this->itemsPerPage : 10;
        $start = ($this->itemsPage - 1) * $effectiveItemsPerPage;
        $newItems = $allItems->slice($start, $effectiveItemsPerPage)->values();
        $hasMore = $allItems->count() > ($this->itemsPage * $effectiveItemsPerPage);

        $html = view('pages.workspace.list-items-chunk', [
            'items' => $newItems,
            'selectedDate' => $this->selectedDate,
            'filters' => $this->getFilters(),
            'tags' => $this->tags,
            'activeFocusSession' => $this->activeFocusSession,
            'pomodoroSettings' => $this->pomodoroSettings,
        ])->render();

        $this->skipRender();

        return ['html' => $html, 'hasMore' => $hasMore];
    }

    /**
     * Build the same unified list entries as the list view (overdue + date items).
     *
     * @return \Illuminate\Support\Collection<int, array{kind: string, item: mixed, isOverdue: bool}>
     */
    protected function getAllListEntries(): Collection
    {
        $overdueItems = $this->overdue->map(fn (array $entry) => array_merge($entry, ['isOverdue' => true]));

        $dateItems = collect()
            ->merge($this->projects->map(fn ($item) => ['kind' => 'project', 'item' => $item, 'isOverdue' => $item->end_datetime ? $item->end_datetime->isPast() : false]))
            ->merge($this->events->map(fn ($item) => ['kind' => 'event', 'item' => $item, 'isOverdue' => $item->end_datetime ? $item->end_datetime->isPast() : false]))
            ->merge($this->tasks->map(fn ($item) => ['kind' => 'task', 'item' => $item, 'isOverdue' => $item->end_datetime ? $item->end_datetime->isPast() : false]))
            ->sortByDesc(fn (array $entry) => $entry['item']->created_at)
            ->values();

        return $overdueItems->merge($dateItems)->values();
    }

    /**
     * Get overdue tasks and events for the authenticated user.
     * Overdue = end/due datetime is before now (date and time aware).
     * Returns a unified collection of entries with 'kind' and 'item' for rendering.
     */
    #[Computed]
    public function overdue(): Collection
    {
        $userId = Auth::id();

        if ($userId === null) {
            return collect();
        }

        // When search scope is "all items", main list shows all matching items; skip overdue bucket to avoid duplicates.
        if (method_exists($this, 'shouldSearchAllItems') && $this->shouldSearchAllItems()) {
            return collect();
        }

        $filterItemType = property_exists($this, 'filterItemType') ? $this->normalizeFilterValue($this->filterItemType) : null;

        $now = now();

        // Early return: Skip overdue queries if filtered to projects only
        if ($filterItemType === 'projects') {
            return collect();
        }

        // Only query overdue tasks if not filtered to events only
        $overdueTasks = collect();
        if ($filterItemType !== 'events') {
            $overdueTaskQuery = Task::query()
                ->with([
                    'project',
                    'tags',
                    'latestUnfinishedFocusSession',
                    'collaborations',
                    'collaborators',
                    'collaborationInvitations.invitee',
                    'comments.user',
                ])
                ->withCount('activityLogs')
                ->withRecentActivityLogs(5)
                ->forUser($userId)
                ->overdue($now)
                ->whereDoesntHave('recurringTask');

            if (method_exists($this, 'applyOverdueTaskFilters')) {
                $this->applyOverdueTaskFilters($overdueTaskQuery);
            }

            if (method_exists($this, 'applyWorkspaceSearchToTaskQuery')) {
                $this->applyWorkspaceSearchToTaskQuery($overdueTaskQuery);
            }

            $overdueTasks = $overdueTaskQuery->orderByPriority()->limit(50)->get()
                ->map(fn (Task $task) => ['kind' => 'task', 'item' => $task]);
        }

        // Only query overdue events if not filtered to tasks only
        $overdueEvents = collect();
        if ($filterItemType !== 'tasks') {
            $overdueEventQuery = Event::query()
                ->with([
                    'tasks',
                    'tags',
                    'collaborations',
                    'collaborators',
                    'collaborationInvitations.invitee',
                ])
                ->withCount('activityLogs')
                ->withRecentActivityLogs(5)
                ->forUser($userId)
                ->notCancelled()
                ->overdue($now)
                ->whereDoesntHave('recurringEvent');

            if (method_exists($this, 'applyOverdueEventFilters')) {
                $this->applyOverdueEventFilters($overdueEventQuery);
            }

            if (method_exists($this, 'applyWorkspaceSearchToEventQuery')) {
                $this->applyWorkspaceSearchToEventQuery($overdueEventQuery);
            }

            $overdueEvents = $overdueEventQuery->orderBy('end_datetime')->limit(50)->get()
                ->map(fn (Event $event) => ['kind' => 'event', 'item' => $event]);
        }

        // Return filtered results based on item type
        if ($filterItemType === 'tasks') {
            return collect($overdueTasks->sortBy(fn (array $entry) => $entry['item']->end_datetime?->timestamp ?? 0)->values()->all());
        }
        if ($filterItemType === 'events') {
            return collect($overdueEvents->sortBy(fn (array $entry) => $entry['item']->end_datetime?->timestamp ?? 0)->values()->all());
        }

        return collect($overdueTasks->all())
            ->merge($overdueEvents->all())
            ->sortBy(fn (array $entry) => $entry['item']->end_datetime?->timestamp ?? 0)
            ->values();
    }

    /**
     * Get upcoming tasks, events, and projects for the authenticated user.
     * Upcoming = within the next N days starting today (independent of the selected date).
     * Intentionally ignores workspace filters and search so the sidebar stays a stable horizon.
     * Returns a unified collection of entries with 'kind' and 'item' for rendering.
     */
    #[Computed]
    public function upcoming(): Collection
    {
        $userId = Auth::id();

        if ($userId === null) {
            return collect();
        }

        // Base date is always "today" for upcoming, regardless of the selected workspace date.
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

        // Sort all upcoming entries by their relevant datetime.
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
     * @return array<int, array<string, mixed>>
     */
    public function loadCalendarFeedHealth(): array
    {
        $userId = Auth::id();
        if ($userId === null) {
            return [];
        }

        $feeds = CalendarFeed::query()
            ->where('user_id', $userId)
            ->orderByDesc('last_synced_at')
            ->limit(self::FEED_HEALTH_LIMIT)
            ->get(['id', 'name', 'source', 'sync_enabled', 'last_synced_at', 'created_at']);

        if ($feeds->isEmpty()) {
            return [];
        }

        $feedIds = $feeds->pluck('id')->all();
        $taskStats = Task::query()
            ->selectRaw('calendar_feed_id, COUNT(*) as total_imported')
            ->selectRaw('SUM(CASE WHEN updated_at >= ? THEN 1 ELSE 0 END) as updated_last_24h', [now()->subDay()])
            ->selectRaw('MAX(updated_at) as latest_import_activity_at')
            ->whereIn('calendar_feed_id', $feedIds)
            ->where('source_type', TaskSourceType::Brightspace->value)
            ->groupBy('calendar_feed_id')
            ->get()
            ->keyBy('calendar_feed_id');

        return $feeds
            ->map(function (CalendarFeed $feed) use ($taskStats): array {
                $status = $this->resolveFeedHealthStatus((bool) $feed->sync_enabled, $feed->last_synced_at);
                $stats = $taskStats->get($feed->id);
                $lastSyncedAt = $feed->last_synced_at;
                $latestImportAt = isset($stats?->latest_import_activity_at) && $stats?->latest_import_activity_at
                    ? \Carbon\Carbon::parse((string) $stats->latest_import_activity_at)
                    : null;

                return [
                    'id' => (int) $feed->id,
                    'name' => (string) ($feed->name ?: __('Untitled feed')),
                    'source' => (string) $feed->source,
                    'source_label' => ucfirst((string) $feed->source),
                    'status' => $status,
                    'status_rank' => $this->resolveFeedHealthStatusRank($status),
                    'status_label' => match ($status) {
                        'fresh' => __('Fresh'),
                        'stale' => __('Stale'),
                        'critical' => __('Critical'),
                        'sync_off' => __('Sync Off'),
                        default => __('Never Synced'),
                    },
                    'total_imported' => (int) ($stats?->total_imported ?? 0),
                    'updated_last_24h' => (int) ($stats?->updated_last_24h ?? 0),
                    'last_synced_human' => $lastSyncedAt?->diffForHumans() ?? __('Never'),
                    'latest_import_activity_human' => $latestImportAt?->diffForHumans(),
                    'latest_import_activity_title' => $latestImportAt?->translatedFormat('M j, Y · H:i'),
                    'last_synced_at' => $lastSyncedAt?->toIso8601String(),
                ];
            })
            ->sortBy(fn (array $row): array => [
                (int) ($row['status_rank'] ?? 99),
                isset($row['last_synced_at']) && $row['last_synced_at']
                    ? -\Carbon\Carbon::parse((string) $row['last_synced_at'])->getTimestamp()
                    : PHP_INT_MAX,
            ])
            ->values()
            ->map(function (array $row): array {
                unset($row['status_rank']);

                return $row;
            })
            ->all();
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

<?php

use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Models\Event;
use App\Models\Project;
use App\Models\CalendarFeed;
use Livewire\Component;
use App\Enums\TaskSourceType;
use App\Enums\TaskStatus;
use App\Enums\EventStatus;
use App\Services\TagService;
use Livewire\Attributes\Url;
use App\Services\TaskService;
use App\Services\EventService;
use Livewire\Attributes\Async;
use Livewire\Attributes\On;
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
use App\Livewire\Concerns\HandlesTasks;
use App\Actions\Event\CreateEventAction;
use App\Actions\Event\DeleteEventAction;
use App\Livewire\Concerns\HandlesEvents;
use App\Livewire\Concerns\HandlesComments;
use App\Livewire\Concerns\HandlesProjects;
use App\Livewire\Concerns\HandlesFiltering;
use App\Actions\Comment\CreateCommentAction;
use App\Actions\Comment\DeleteCommentAction;
use App\Actions\Comment\UpdateCommentAction;
use App\Actions\Project\CreateProjectAction;
use App\Actions\Project\DeleteProjectAction;
use App\Actions\Task\UpdateTaskPropertyAction;
use App\Livewire\Concerns\HandlesActivityLogs;
use App\Actions\Task\CreateTaskExceptionAction;
use App\Actions\Task\DeleteTaskExceptionAction;
use App\Livewire\Concerns\HandlesCalendarFeeds;
use App\Livewire\Concerns\HandlesWorkspaceCalendar;
use App\Livewire\Concerns\HandlesFocusSessions;
use App\Actions\Event\UpdateEventPropertyAction;
use App\Livewire\Concerns\HandlesCollaborations;
use App\Actions\Event\CreateEventExceptionAction;
use App\Actions\Event\DeleteEventExceptionAction;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use App\Support\WorkspaceListAggregator;

new
#[Title('Workspace')]
class extends Component
{
    use AuthorizesRequests;
    use HandlesActivityLogs;
    use HandlesCollaborations;
    use HandlesComments;
    use HandlesCalendarFeeds;
    use HandlesWorkspaceCalendar;
    use HandlesEvents;
    use HandlesFiltering;
    use HandlesFocusSessions;
    use HandlesPomodoroSettings;
    use HandlesProjects;
    use HandlesTags;
    use HandlesTasks;

    private const FEED_HEALTH_LIMIT = 5;

    #[Url(as: 'date')]
    public ?string $selectedDate = null;

    #[Url(as: 'view')]
    public string $viewMode = 'list';

    #[Url(as: 'section')]
    public string $quickSection = 'all';

    #[Url(as: 'task')]
    public ?int $focusTaskId = null;

    #[Url(as: 'event')]
    public ?int $focusEventId = null;

    #[Url(as: 'project')]
    public ?int $focusProjectId = null;

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
     * When true, keep the currently selected list/kanban shell while resolving
     * focus from in-page interactions (calendar agenda, bell), instead of forcing list.
     */
    protected bool $preserveCurrentViewModeForFocus = false;

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
        $this->quickSection = $this->normalizeQuickSection($this->quickSection);
        $this->syncFilterTagIdFromTagIds();
        $this->activeFocusSession = $this->getActiveFocusSession();
        $this->applyWorkspaceDeepLinkFocus();
    }

    /**
     * Focus a task or event from the sidebar calendar agenda without merging stale query-string focus ids.
     */
    public function focusCalendarAgendaItem(string $kind, int $id, bool $expandPagination = true): void
    {
        if ($id < 1 || ! in_array($kind, ['task', 'event', 'project'], true)) {
            return;
        }

        $this->focusTaskId = null;
        $this->focusEventId = null;
        $this->focusProjectId = null;

        if ($kind === 'task') {
            $this->focusTaskId = $id;
        } elseif ($kind === 'event') {
            $this->focusEventId = $id;
        } else {
            $this->focusProjectId = $id;
        }

        $this->preserveCurrentViewModeForFocus = true;

        try {
            $this->applyWorkspaceDeepLinkFocus(false, $expandPagination);
        } finally {
            $this->preserveCurrentViewModeForFocus = false;
        }

        $kindJs = json_encode($kind, JSON_THROW_ON_ERROR);
        $this->js('requestAnimationFrame(() => { setTimeout(() => { window.runWorkspaceFocusToTarget && window.runWorkspaceFocusToTarget('.$kindJs.', '.$id.'); }, 0); });');

        // In-page calendar/bell focus should be one-shot UX; do not persist URL deep-link params.
        $this->focusTaskId = null;
        $this->focusEventId = null;
        $this->focusProjectId = null;
    }

    #[On('workspace-bell-focus-item')]
    public function onWorkspaceBellFocusItem(string $kind, int $id, bool $expandPagination = true): void
    {
        $this->focusCalendarAgendaItem($kind, $id, $expandPagination);
    }

    /**
     * Reset list pagination to first page. Call when date or filters change
     * so the list shows page 1 of the new result set.
     */
    public function resetListPagination(): void
    {
        $this->tasksPage = 1;
        $this->eventsPage = 1;
        $this->projectsPage = 1;
        $this->itemsPage = 1;
    }

    protected function bumpWorkspaceListPages(): void
    {
        $this->tasksPage++;
        $this->eventsPage++;
        $this->projectsPage++;
        $this->itemsPage++;
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
     * Remount list/kanban without resetting pagination (collaboration bell, trash restore).
     */
    protected function refreshWorkspaceListInPlace(): void
    {
        $this->refreshWorkspaceItems(resetPagination: false);
    }

    #[On('collaboration-invitation-accepted')]
    #[On('collaboration-invitation-declined')]
    public function onCollaborationInvitationBellEvent(): void
    {
        $this->refreshWorkspaceListInPlace();
    }

    #[On('workspace-trash-restored')]
    public function onWorkspaceTrashRestored(): void
    {
        $this->refreshWorkspaceListInPlace();
    }

    /**
     * When the selected date changes, reset pagination so we show page 1 for the new date.
     * Also clear the cached parsed date so it gets re-parsed.
     */
    public function updatedSelectedDate(): void
    {
        $this->parsedSelectedDate = null;
        $this->resetCalendarViewForSelectedDateChange();
        $this->resetListPagination();
    }

    public function updatedViewMode(string $value): void
    {
        if (! in_array($value, ['list', 'kanban'], true)) {
            $this->viewMode = 'list';
        }

        $this->quickSection = 'all';
        $this->resetListPagination();
    }

    public function updatedQuickSection(string $value): void
    {
        $this->quickSection = $this->normalizeQuickSection($value);
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
            'quickSection' => $this->quickSection,
            'version' => $this->workspaceItemsVersion,
        ], JSON_THROW_ON_ERROR));
    }

    public function setQuickSection(string $section): void
    {
        $this->quickSection = $this->normalizeQuickSection($section);
        $this->resetListPagination();
    }

    /**
     * Load the next page of tasks, events, and projects (infinite scroll).
     * Pagination uses #[Reactive] itemsPage on the nested list; wire:key stays stable (fingerprint omits page).
     */
    #[Async]
    public function loadMoreItems(): void
    {
        $this->bumpWorkspaceListPages();
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
        $this->bumpWorkspaceListPages();

        $allItems = $this->getSectionedListEntries();
        $effectiveItemsPerPage = $this->itemsPerPage > 0 ? $this->itemsPerPage : 10;
        $start = ($this->itemsPage - 1) * $effectiveItemsPerPage;
        $newItems = $allItems->slice($start, $effectiveItemsPerPage)->values();
        $hasMore = $allItems->count() > ($this->itemsPage * $effectiveItemsPerPage);
        $previousSection = $start > 0
            ? ($allItems->get($start - 1)['plannerSection'] ?? null)
            : null;

        $html = view('pages.workspace.list-items-chunk', [
            'items' => $newItems,
            'selectedDate' => $this->selectedDate,
            'filters' => $this->getFilters(),
            'tags' => $this->tags,
            'activeFocusSession' => $this->activeFocusSession,
            'pomodoroSettings' => $this->pomodoroSettings,
            'previousSection' => $previousSection,
        ])->render();

        $this->skipRender();

        return ['html' => $html, 'hasMore' => $hasMore];
    }

    /**
     * Build the unified workspace list (overdue strip, then day items): deduped, calendar-ordered.
     *
     * @return Collection<int, array{kind: string, item: mixed, isOverdue: bool}>
     */
    public function getAllListEntries(): Collection
    {
        return WorkspaceListAggregator::mergeOrderAndDedupe(
            $this->overdue,
            $this->projects,
            $this->events,
            $this->tasks,
        );
    }

    /**
     * Build unified completed entries across tasks/events/projects.
     *
     * @return Collection<int, array{kind: string, item: mixed, isOverdue: bool}>
     */
    public function completedListEntries(): Collection
    {
        return WorkspaceListAggregator::mergeOrderAndDedupe(
            collect(),
            $this->completedProjects,
            $this->completedEvents,
            $this->completedTasks,
        );
    }

    /**
     * Build planner sections used by the list UI.
     *
     * @return Collection<int, array{kind: string, item: mixed, isOverdue: bool, plannerSection: string, plannerSectionLabel: string}>
     */
    public function getSectionedListEntries(): Collection
    {
        return $this->getSectionedListEntriesBase()
            ->when(
                $this->quickSection !== 'all',
                fn (Collection $entries): Collection => $entries->filter(
                    fn (array $entry): bool => ($entry['plannerSection'] ?? 'upcoming') === $this->quickSection
                )->values()
            )
            ->values();
    }

    /**
     * Build planner sections before quick-section filtering.
     *
     * @return Collection<int, array{kind: string, item: mixed, isOverdue: bool, plannerSection: string, plannerSectionLabel: string}>
     */
    public function getSectionedListEntriesBase(): Collection
    {
        return $this->getAllListEntries()
            ->map(function (array $entry): array {
                $section = $this->plannerSectionForEntry($entry);

                return [
                    ...$entry,
                    'plannerSection' => $section,
                    'plannerSectionLabel' => $this->plannerSectionLabel($section),
                ];
            })
            ->sortBy(fn (array $entry): int => $this->plannerSectionOrder($entry['plannerSection']))
            ->values();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function quickSectionCounts(): array
    {
        $sections = ['overdue', 'today', 'tomorrow', 'upcoming'];

        if ($this->viewMode === 'kanban') {
            $kanbanEntries = $this->tasks
                ->map(fn (Task $task): array => ['kind' => 'task', 'item' => $task, 'isOverdue' => false])
                ->merge(
                    $this->overdue
                        ->filter(fn (array $entry): bool => ($entry['kind'] ?? null) === 'task')
                        ->map(fn (array $entry): array => ['kind' => 'task', 'item' => $entry['item'], 'isOverdue' => true])
                )
                ->unique(fn (array $entry): string => 'task-'.((int) $entry['item']->id))
                ->values()
                ->map(function (array $entry): array {
                    $section = $this->plannerSectionForEntry($entry);

                    return [
                        ...$entry,
                        'plannerSection' => $section,
                    ];
                });

            $counts = collect($sections)->mapWithKeys(
                fn (string $section): array => [$section => $kanbanEntries->where('plannerSection', $section)->count()]
            )->all();

            $counts['all'] = $kanbanEntries->count();

            return $counts;
        }

        $listEntries = $this->getSectionedListEntriesBase();
        $counts = collect($sections)->mapWithKeys(
            fn (string $section): array => [$section => $listEntries->where('plannerSection', $section)->count()]
        )->all();
        $counts['all'] = $listEntries->count();

        return $counts;
    }

    private function plannerSectionForEntry(array $entry): string
    {
        if (($entry['isOverdue'] ?? false) === true) {
            return 'overdue';
        }

        $item = $entry['item'] ?? null;
        if (! $item instanceof Model) {
            return 'upcoming';
        }

        $anchorDate = $item->start_datetime ?? $item->end_datetime;
        if ($anchorDate === null) {
            return 'upcoming';
        }

        $today = now()->startOfDay();
        $tomorrow = $today->copy()->addDay();
        $anchor = $anchorDate->copy()->startOfDay();

        if ($anchor->lessThan($today)) {
            return 'today';
        }

        if ($anchor->equalTo($today)) {
            return 'today';
        }

        if ($anchor->equalTo($tomorrow)) {
            return 'tomorrow';
        }

        return 'upcoming';
    }

    private function plannerSectionLabel(string $section): string
    {
        return match ($section) {
            'overdue' => __('Overdue'),
            'today' => __('Today'),
            'tomorrow' => __('Tomorrow'),
            default => __('Upcoming'),
        };
    }

    private function plannerSectionOrder(string $section): int
    {
        return match ($section) {
            'overdue' => 0,
            'today' => 1,
            'tomorrow' => 2,
            default => 3,
        };
    }

    private function normalizeQuickSection(?string $section): string
    {
        $allowed = ['all', 'overdue', 'today', 'tomorrow', 'upcoming'];
        $normalized = $section !== null ? strtolower(trim($section)) : 'all';

        return in_array($normalized, $allowed, true) ? $normalized : 'all';
    }

    protected function applyWorkspaceDeepLinkFocus(bool $mergeQuery = true, bool $expandPagination = true): void
    {
        if ($mergeQuery) {
            $this->mergeWorkspaceFocusFromRequestQuery();
        }

        if ($this->focusTaskId === null && $this->focusEventId === null && $this->focusProjectId === null) {
            return;
        }

        if (Auth::id() === null) {
            $this->focusTaskId = null;
            $this->focusEventId = null;
            $this->focusProjectId = null;

            return;
        }

        if ($this->focusTaskId !== null) {
            $this->focusEventId = null;
            $this->focusProjectId = null;
            $task = $this->resolveDeepLinkModel(Task::class, $this->focusTaskId);
            if (! $task instanceof Task) {
                $this->focusTaskId = null;

                return;
            }
            $this->applyDeepLinkListShell('tasks');
            if ($expandPagination) {
                $this->expandPaginationUntilFocusItemVisible('task', $task->id);
            }

            return;
        }

        if ($this->focusEventId !== null) {
            $this->focusProjectId = null;
            $event = $this->resolveDeepLinkModel(Event::class, $this->focusEventId);
            if (! $event instanceof Event) {
                $this->focusEventId = null;

                return;
            }
            $this->applyDeepLinkListShell('events');
            if ($expandPagination) {
                $this->expandPaginationUntilFocusItemVisible('event', $event->id);
            }

            return;
        }

        if ($this->focusProjectId !== null) {
            $project = $this->resolveDeepLinkModel(Project::class, $this->focusProjectId);
            if (! $project instanceof Project) {
                $this->focusProjectId = null;

                return;
            }
            $this->applyDeepLinkListShell('projects');
            if ($expandPagination) {
                $this->expandPaginationUntilFocusItemVisible('project', $project->id);
            }
        }
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    protected function resolveDeepLinkModel(string $class, int $id): ?Model
    {
        if ($id < 1) {
            return null;
        }

        /** @var T|null $model */
        $model = $class::query()->find($id);

        if ($model === null) {
            return null;
        }

        try {
            $this->authorize('view', $model);
        } catch (AuthorizationException) {
            return null;
        }

        return $model;
    }

    protected function applyDeepLinkListShell(string $filterItemType): void
    {
        if (! $this->preserveCurrentViewModeForFocus) {
            $this->viewMode = 'list';
        }
        $this->searchQuery = null;
        $this->filterItemType = $filterItemType;
        $this->listContextProjectId = null;
        $this->listContextEventId = null;
    }

    /**
     * Ensures request query wins for ?task / ?event / ?project when present (e.g. alongside #[Url]).
     */
    protected function mergeWorkspaceFocusFromRequestQuery(): void
    {
        if (request()->query->has('task')) {
            $tid = (int) request()->query('task', 0);
            if ($tid > 0) {
                $this->focusTaskId = $tid;
                $this->focusEventId = null;
                $this->focusProjectId = null;
            }

            return;
        }

        if (request()->query->has('event')) {
            $eid = (int) request()->query('event', 0);
            if ($eid > 0) {
                $this->focusEventId = $eid;
                $this->focusTaskId = null;
                $this->focusProjectId = null;
            }

            return;
        }

        if (request()->query->has('project')) {
            $pid = (int) request()->query('project', 0);
            if ($pid > 0) {
                $this->focusProjectId = $pid;
                $this->focusTaskId = null;
                $this->focusEventId = null;
            }
        }
    }

    /**
     * Livewire #[Computed] memoizes per HTTP request; tasks/events/projects use tasksPage/eventsPage/projectsPage.
     * Without clearing, expandPaginationUntilFocusItemVisible() reuses the first cached collection and never finds rows on later pages.
     */
    protected function clearPaginatedWorkspaceListCaches(): void
    {
        unset($this->tasks, $this->events, $this->projects);
    }

    /**
     * Load enough per-type pages so the merged list includes the target row, then set {@see $itemsPage} for the combined slice.
     */
    protected function expandPaginationUntilFocusItemVisible(string $kind, int $id): bool
    {
        $this->resetListPagination();

        for ($pass = 0; $pass < 50; $pass++) {
            if ($pass > 0) {
                $this->clearPaginatedWorkspaceListCaches();
            }
            $entries = $this->getAllListEntries();
            $index = $entries->search(static function (array $entry) use ($kind, $id): bool {
                return $entry['kind'] === $kind && (int) $entry['item']->id === $id;
            });

            if ($index !== false) {
                $perPage = max(1, $this->itemsPerPage);
                $this->itemsPage = (int) max(1, (int) ceil(($index + 1) / $perPage));

                return true;
            }

            if (! $this->hasMoreTasks && ! $this->hasMoreEvents && ! $this->hasMoreProjects) {
                break;
            }

            $this->bumpWorkspaceListPages();
        }

        return false;
    }

    /**
     * Get overdue tasks and events for the authenticated user.
     * Lateness is always relative to {@see now()} (time-aware), independent of the selected calendar day,
     * so the list/kanban overdue strip is always the current real backlog.
     *
     * @return Collection<int, array{kind: string, item: Task|Event}>
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

        // Overdue rows are always non-recurring; with "recurring only" the strip would contradict the filter.
        $filterRecurring = property_exists($this, 'filterRecurring') ? $this->normalizeFilterValue($this->filterRecurring) : null;
        if ($filterRecurring === 'recurring') {
            return collect();
        }

        $filterItemType = property_exists($this, 'filterItemType') ? $this->normalizeFilterValue($this->filterItemType) : null;

        if ($this->viewMode === 'kanban') {
            $filterItemType = 'tasks';
        }

        $overdueAsOf = now();

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
                ->overdue($overdueAsOf)
                ->where('status', '!=', TaskStatus::Done->value)
                ->whereDoesntHave('recurringTask');

            if (method_exists($this, 'applyOverdueTaskFilters')) {
                $this->applyOverdueTaskFilters($overdueTaskQuery);
            }

            if (method_exists($this, 'applyWorkspaceSearchToTaskQuery')) {
                $this->applyWorkspaceSearchToTaskQuery($overdueTaskQuery);
            }

            $overdueLimit = ($this->focusTaskId ?? 0) > 0 ? 500 : 50;

            $overdueTasks = $overdueTaskQuery->orderByPriority()->limit($overdueLimit)->get()
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
                ->where('status', '!=', EventStatus::Completed->value)
                ->overdue($overdueAsOf)
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

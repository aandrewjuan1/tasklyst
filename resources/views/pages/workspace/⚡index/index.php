<?php

use App\Actions\Collaboration\AcceptCollaborationInvitationAction;
use App\Actions\Collaboration\CreateCollaborationInvitationAction;
use App\Actions\Collaboration\DeclineCollaborationInvitationAction;
use App\Actions\Collaboration\DeleteCollaborationAction;
use App\Actions\Collaboration\UpdateCollaborationPermissionAction;
use App\Actions\Comment\CreateCommentAction;
use App\Actions\Comment\DeleteCommentAction;
use App\Actions\Comment\UpdateCommentAction;
use App\Actions\Event\CreateEventAction;
use App\Actions\Event\CreateEventExceptionAction;
use App\Actions\Event\DeleteEventAction;
use App\Actions\Event\DeleteEventExceptionAction;
use App\Actions\Event\UpdateEventPropertyAction;
use App\Actions\FocusSession\AbandonFocusSessionAction;
use App\Actions\FocusSession\CompleteFocusSessionAction;
use App\Actions\FocusSession\GetActiveFocusSessionAction;
use App\Actions\FocusSession\PauseFocusSessionAction;
use App\Actions\FocusSession\ResumeFocusSessionAction;
use App\Actions\FocusSession\StartFocusSessionAction;
use App\Actions\Pomodoro\CompletePomodoroSessionAction;
use App\Actions\Pomodoro\GetNextPomodoroSessionTypeAction;
use App\Actions\Pomodoro\GetOrCreatePomodoroSettingsAction;
use App\Actions\Pomodoro\GetPomodoroSequenceNumberAction;
use App\Actions\Pomodoro\UpdatePomodoroSettingsAction;
use App\Actions\Project\CreateProjectAction;
use App\Actions\Project\DeleteProjectAction;
use App\Actions\Project\UpdateProjectPropertyAction;
use App\Actions\SchoolClass\CreateSchoolClassAction;
use App\Actions\SchoolClass\DeleteSchoolClassAction;
use App\Actions\SchoolClass\ForceDeleteSchoolClassAction;
use App\Actions\SchoolClass\RestoreSchoolClassAction;
use App\Actions\SchoolClass\UpdateSchoolClassPropertyAction;
use App\Actions\Tag\CreateTagAction;
use App\Actions\Tag\DeleteTagAction;
use App\Actions\Teacher\CreateTeacherAction;
use App\Actions\Teacher\DeleteTeacherAction;
use App\Actions\Teacher\UpdateTeacherAction;
use App\Actions\Task\CreateTaskAction;
use App\Actions\Task\CreateTaskExceptionAction;
use App\Actions\Task\DeleteTaskAction;
use App\Actions\Task\DeleteTaskExceptionAction;
use App\Actions\Task\UpdateTaskPropertyAction;
use App\Enums\AssistantSchedulePlanItemStatus;
use App\Enums\EventStatus;
use App\Enums\TaskSourceType;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\HandlesActivityLogs;
use App\Livewire\Concerns\HandlesCalendarFeeds;
use App\Livewire\Concerns\HandlesCollaborations;
use App\Livewire\Concerns\HandlesComments;
use App\Livewire\Concerns\HandlesEvents;
use App\Livewire\Concerns\HandlesFiltering;
use App\Livewire\Concerns\HandlesFocusSessions;
use App\Livewire\Concerns\HandlesPomodoroSettings;
use App\Livewire\Concerns\HandlesProjects;
use App\Livewire\Concerns\HandlesSchoolClasses;
use App\Livewire\Concerns\HandlesTags;
use App\Livewire\Concerns\HandlesTeachers;
use App\Livewire\Concerns\HandlesTasks;
use App\Livewire\Concerns\HandlesWorkspaceCalendar;
use App\Models\AssistantSchedulePlanItem;
use App\Models\CalendarFeed;
use App\Models\Event;
use App\Models\Project;
use App\Models\SchoolClass;
use App\Models\Tag;
use App\Models\Task;
use App\Models\Teacher;
use App\Models\User;
use App\Services\EventService;
use App\Services\ProjectService;
use App\Services\SchoolClassService;
use App\Services\TagService;
use App\Services\TeacherService;
use App\Services\TaskService;
use App\Support\WorkspaceListAggregator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Async;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Title('Workspace')]
class extends Component
{
    use AuthorizesRequests;
    use HandlesActivityLogs;
    use HandlesCalendarFeeds;
    use HandlesCollaborations;
    use HandlesComments;
    use HandlesEvents;
    use HandlesFiltering;
    use HandlesFocusSessions;
    use HandlesPomodoroSettings;
    use HandlesProjects;
    use HandlesSchoolClasses;
    use HandlesTags;
    use HandlesTeachers;
    use HandlesTasks;
    use HandlesWorkspaceCalendar;

    private const FEED_HEALTH_LIMIT = 5;

    #[Url(as: 'date')]
    public ?string $selectedDate = null;

    #[Url(as: 'view')]
    public string $viewMode = 'list';

    #[Url(as: 'task')]
    public ?int $focusTaskId = null;

    #[Url(as: 'event')]
    public ?int $focusEventId = null;

    #[Url(as: 'project')]
    public ?int $focusProjectId = null;

    #[Url(as: 'school_class')]
    public ?int $focusSchoolClassId = null;

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

    protected SchoolClassService $schoolClassService;

    protected TagService $tagService;

    protected TeacherService $teacherService;

    protected CreateEventAction $createEventAction;

    protected CreateProjectAction $createProjectAction;

    protected CreateSchoolClassAction $createSchoolClassAction;

    protected DeleteSchoolClassAction $deleteSchoolClassAction;

    protected RestoreSchoolClassAction $restoreSchoolClassAction;

    protected ForceDeleteSchoolClassAction $forceDeleteSchoolClassAction;

    protected UpdateSchoolClassPropertyAction $updateSchoolClassPropertyAction;

    protected CreateTagAction $createTagAction;

    protected CreateTeacherAction $createTeacherAction;

    protected CreateTaskAction $createTaskAction;

    protected DeleteEventAction $deleteEventAction;

    protected DeleteTagAction $deleteTagAction;

    protected DeleteTeacherAction $deleteTeacherAction;

    protected DeleteProjectAction $deleteProjectAction;

    protected DeleteTaskAction $deleteTaskAction;

    protected UpdateEventPropertyAction $updateEventPropertyAction;

    protected UpdateProjectPropertyAction $updateProjectPropertyAction;

    protected UpdateTaskPropertyAction $updateTaskPropertyAction;

    protected UpdateTeacherAction $updateTeacherAction;

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
        SchoolClassService $schoolClassService,
        TagService $tagService,
        TeacherService $teacherService,
        CreateEventAction $createEventAction,
        CreateProjectAction $createProjectAction,
        CreateSchoolClassAction $createSchoolClassAction,
        DeleteSchoolClassAction $deleteSchoolClassAction,
        RestoreSchoolClassAction $restoreSchoolClassAction,
        ForceDeleteSchoolClassAction $forceDeleteSchoolClassAction,
        UpdateSchoolClassPropertyAction $updateSchoolClassPropertyAction,
        CreateTagAction $createTagAction,
        CreateTeacherAction $createTeacherAction,
        CreateTaskAction $createTaskAction,
        DeleteEventAction $deleteEventAction,
        DeleteProjectAction $deleteProjectAction,
        DeleteTagAction $deleteTagAction,
        DeleteTeacherAction $deleteTeacherAction,
        DeleteTaskAction $deleteTaskAction,
        UpdateEventPropertyAction $updateEventPropertyAction,
        UpdateProjectPropertyAction $updateProjectPropertyAction,
        UpdateTaskPropertyAction $updateTaskPropertyAction,
        UpdateTeacherAction $updateTeacherAction,
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
        $this->schoolClassService = $schoolClassService;
        $this->tagService = $tagService;
        $this->teacherService = $teacherService;
        $this->createEventAction = $createEventAction;
        $this->createProjectAction = $createProjectAction;
        $this->createSchoolClassAction = $createSchoolClassAction;
        $this->deleteSchoolClassAction = $deleteSchoolClassAction;
        $this->restoreSchoolClassAction = $restoreSchoolClassAction;
        $this->forceDeleteSchoolClassAction = $forceDeleteSchoolClassAction;
        $this->updateSchoolClassPropertyAction = $updateSchoolClassPropertyAction;
        $this->createTagAction = $createTagAction;
        $this->createTeacherAction = $createTeacherAction;
        $this->createTaskAction = $createTaskAction;
        $this->deleteEventAction = $deleteEventAction;
        $this->deleteProjectAction = $deleteProjectAction;
        $this->deleteTagAction = $deleteTagAction;
        $this->deleteTeacherAction = $deleteTeacherAction;
        $this->deleteTaskAction = $deleteTaskAction;
        $this->updateEventPropertyAction = $updateEventPropertyAction;
        $this->updateProjectPropertyAction = $updateProjectPropertyAction;
        $this->updateTaskPropertyAction = $updateTaskPropertyAction;
        $this->updateTeacherAction = $updateTeacherAction;
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
            $this->authorize('viewAny', SchoolClass::class);
            $this->authorize('viewAny', Tag::class);
            $this->authorize('viewAny', Teacher::class);
        }
        if ($this->selectedDate === null || $this->selectedDate === '' || strtotime($this->selectedDate) === false) {
            $this->selectedDate = now()->toDateString();
        }
        if (! in_array($this->viewMode, ['list', 'kanban'], true)) {
            $this->viewMode = 'list';
        }
        $this->syncFilterTagIdFromTagIds();
        $this->initializeQuickFilterPresetState();
        $this->activeFocusSession = $this->getActiveFocusSession();
        $this->applyWorkspaceDeepLinkFocus();
        $this->handleDashboardFilterToastOnLoad();
    }

    protected function handleDashboardFilterToastOnLoad(): void
    {
        $source = strtolower(trim((string) request()->query('from_dashboard_filter', '')));
        if ($source === '') {
            return;
        }

        $message = match ($source) {
            'doing' => __('Showing Doing tasks.'),
            'classes' => __('Showing Classes.'),
            'recurring' => __('Showing Recurring tasks.'),
            default => null,
        };

        if ($message === null) {
            return;
        }

        $this->dispatch('toast', type: 'info', message: $message);

        $this->js(<<<'JS'
            (() => {
                const url = new URL(window.location.href);
                if (!url.searchParams.has('from_dashboard_filter')) {
                    return;
                }
                url.searchParams.delete('from_dashboard_filter');
                window.history.replaceState(window.history.state, '', url.toString());
            })();
        JS);
    }

    /**
     * Focus a task or event from the sidebar calendar agenda without merging stale query-string focus ids.
     * Does not set the "Show" item-type filter (unlike URL deep links). If the row is not in the merged list
     * under the current date and filters, aligns the workspace date to the item when possible and clears
     * filters (same idea as {@see focusFromScheduledPlanItem}), then expands pagination so the row can load.
     */
    public function focusCalendarAgendaItem(string $kind, int $id, bool $expandPagination = true): void
    {
        if ($id < 1 || ! in_array($kind, ['task', 'event', 'project', 'schoolClass'], true)) {
            return;
        }

        $this->focusTaskId = null;
        $this->focusEventId = null;
        $this->focusProjectId = null;
        $this->focusSchoolClassId = null;

        $seedTaskVisibilityInKanban = $kind === 'task' && $this->viewMode === 'kanban';
        if ($seedTaskVisibilityInKanban) {
            // In kanban, keep existing behavior: allow immediate in-board focus resolution
            // without clearing pinned filters when the card can already be found.
            $this->focusTaskId = $id;
        }

        $this->preserveCurrentViewModeForFocus = true;

        try {
            $model = match ($kind) {
                'task' => $this->resolveDeepLinkModel(Task::class, $id),
                'event' => $this->resolveDeepLinkModel(Event::class, $id),
                'project' => $this->resolveDeepLinkModel(Project::class, $id),
                default => $this->resolveDeepLinkModel(SchoolClass::class, $id),
            };

            if ($model === null) {
                return;
            }

            $didExpand = false;

            if ($expandPagination) {
                $didExpand = $this->expandPaginationUntilFocusItemVisible($kind, $id);
            }

            if ($expandPagination && ! $didExpand) {
                $anchorDate = $this->resolveWorkspaceAnchorDateStringForModel($kind, $model);
                $currentDate = $this->getParsedSelectedDate()->toDateString();

                if ($anchorDate !== null && $anchorDate !== $currentDate) {
                    $this->selectedDate = $anchorDate;
                    $this->parsedSelectedDate = null;
                    $this->dispatch('toast', type: 'info', message: __('Switched to :date for this item.', [
                        'date' => \Carbon\Carbon::parse($anchorDate)->translatedFormat('l, F j, Y'),
                    ]));
                }

                $this->clearAllFilters();
                // Refresh computed list sources before the retry so we do not reuse stale
                // in-request collections that were built under the previous filter/date state.
                $this->clearPaginatedWorkspaceListCaches();
                unset($this->overdue);
                $this->expandPaginationUntilFocusItemVisible($kind, $id);
            }

            if ($kind === 'task') {
                $this->focusTaskId = $id;
            } elseif ($kind === 'event') {
                $this->focusEventId = $id;
            } elseif ($kind === 'project') {
                $this->focusProjectId = $id;
            } else {
                $this->focusSchoolClassId = $id;
            }

            $this->applyWorkspaceDeepLinkFocus(
                mergeQuery: false,
                expandPagination: false,
                applyItemTypeToFilters: false,
                clearSearch: false,
            );
        } finally {
            $this->preserveCurrentViewModeForFocus = false;
        }

        $kindJs = json_encode($kind, JSON_THROW_ON_ERROR);
        $this->js('requestAnimationFrame(() => { setTimeout(() => { window.runWorkspaceFocusToTarget && window.runWorkspaceFocusToTarget('.$kindJs.', '.$id.'); }, 0); });');

        // In-page calendar/bell focus should be one-shot UX; do not persist URL deep-link params.
        $this->focusTaskId = null;
        $this->focusEventId = null;
        $this->focusProjectId = null;
        $this->focusSchoolClassId = null;
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
            $this->schoolClassesForWorkspaceList,
            $this->selectedDate,
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
            collect(),
        );
    }

    protected function dispatchWorkspaceVisibilityToastForCreatedItem(string $kind, Model $model): void
    {
        if (! in_array($kind, ['task', 'event', 'project', 'schoolClass'], true)) {
            return;
        }

        if ($this->viewMode === 'kanban' && $kind !== 'task') {
            $this->dispatch('toast', type: 'info', message: __('Item no longer matches this view. Switch view or Show filters to see it again.'));

            return;
        }

        $this->clearPaginatedWorkspaceListCaches();
        unset($this->overdue);

        $itemId = (int) $model->getKey();
        $isVisible = $this->getAllListEntries()->contains(
            fn (array $entry): bool => ($entry['kind'] ?? null) === $kind
                && (int) ($entry['item']->id ?? 0) === $itemId
        );

        if ($isVisible) {
            return;
        }

        $filters = $this->getFilters();
        $reason = 'filter';
        $searchScope = (string) ($filters['searchScope'] ?? 'selected_date');
        if ($searchScope !== 'all_items') {
            $anchorDate = $this->resolveWorkspaceAnchorDateStringForModel($kind, $model);
            if ($anchorDate !== null && $anchorDate !== $this->getParsedSelectedDate()->toDateString()) {
                $reason = 'date';
            }
        }

        if (($filters['hasActiveSearch'] ?? false) === true) {
            $reason = 'search';
        }

        $message = match ($reason) {
            'date' => __('Item moved out of this date view. Pick its date or switch search to all items.'),
            'search' => __('Item no longer matches current search. Clear search or switch scope.'),
            default => __('Item no longer matches active filters. Clear or adjust filters to see it again.'),
        };

        $this->dispatch('toast', type: 'info', message: $message);
    }

    public function dismissScheduledFocusForEntity(string $entityType, int $entityId, string $reason = 'entity_datetime_updated'): void
    {
        if (! in_array($entityType, ['task', 'event', 'project'], true) || $entityId < 1) {
            return;
        }

        $this->deactivateScheduledFocusForEntity($entityType, $entityId, $reason);
    }

    protected function deactivateScheduledFocusForEntity(string $entityType, int $entityId, string $reason = 'entity_updated'): void
    {
        $userId = Auth::id();
        if ($userId === null || $entityId < 1) {
            return;
        }
        if (! in_array($entityType, ['task', 'event', 'project'], true)) {
            return;
        }

        AssistantSchedulePlanItem::query()
            ->forUser($userId)
            ->active()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->get()
            ->each(function (AssistantSchedulePlanItem $planItem) use ($reason): void {
                $metadata = is_array($planItem->metadata ?? null) ? $planItem->metadata : [];
                data_set($metadata, 'actions.last_action', 'dismissed');
                data_set($metadata, 'actions.last_action_reason', $reason);
                data_set($metadata, 'actions.last_action_at', now()->toIso8601String());

                $planItem->update([
                    'status' => AssistantSchedulePlanItemStatus::Dismissed,
                    'dismissed_at' => now(),
                    'metadata' => $metadata,
                ]);
            });
    }

    private function formatDurationHumanReadable(?int $minutes): ?string
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

    protected function applyWorkspaceDeepLinkFocus(
        bool $mergeQuery = true,
        bool $expandPagination = true,
        bool $applyItemTypeToFilters = true,
        bool $clearSearch = true,
    ): void {
        if ($mergeQuery) {
            $this->mergeWorkspaceFocusFromRequestQuery();
        }

        if (request()->query($this->agendaWorkspaceFocusQueryParam()) === '1') {
            $applyItemTypeToFilters = false;
            $clearSearch = false;
        }

        if ($this->focusTaskId === null && $this->focusEventId === null && $this->focusProjectId === null && $this->focusSchoolClassId === null) {
            return;
        }

        if (Auth::id() === null) {
            $this->focusTaskId = null;
            $this->focusEventId = null;
            $this->focusProjectId = null;
            $this->focusSchoolClassId = null;

            return;
        }

        if ($this->focusTaskId !== null) {
            $this->focusEventId = null;
            $this->focusProjectId = null;
            $this->focusSchoolClassId = null;
            $task = $this->resolveDeepLinkModel(Task::class, $this->focusTaskId);
            if (! $task instanceof Task) {
                $this->focusTaskId = null;

                return;
            }
            $this->applyDeepLinkListShell('tasks', $applyItemTypeToFilters, $clearSearch);
            if ($expandPagination) {
                $this->expandPaginationUntilFocusItemVisible('task', $task->id);
            }

            return;
        }

        if ($this->focusEventId !== null) {
            $this->focusProjectId = null;
            $this->focusSchoolClassId = null;
            $event = $this->resolveDeepLinkModel(Event::class, $this->focusEventId);
            if (! $event instanceof Event) {
                $this->focusEventId = null;

                return;
            }
            $this->applyDeepLinkListShell('events', $applyItemTypeToFilters, $clearSearch);
            if ($expandPagination) {
                $this->expandPaginationUntilFocusItemVisible('event', $event->id);
            }

            return;
        }

        if ($this->focusProjectId !== null) {
            $this->focusSchoolClassId = null;
            $project = $this->resolveDeepLinkModel(Project::class, $this->focusProjectId);
            if (! $project instanceof Project) {
                $this->focusProjectId = null;

                return;
            }
            $this->applyDeepLinkListShell('projects', $applyItemTypeToFilters, $clearSearch);
            if ($expandPagination) {
                $this->expandPaginationUntilFocusItemVisible('project', $project->id);
            }

            return;
        }

        if ($this->focusSchoolClassId !== null) {
            $schoolClass = $this->resolveDeepLinkModel(SchoolClass::class, $this->focusSchoolClassId);
            if (! $schoolClass instanceof SchoolClass) {
                $this->focusSchoolClassId = null;

                return;
            }
            $this->applyDeepLinkListShell('classes', $applyItemTypeToFilters, $clearSearch);
            if ($expandPagination) {
                $this->expandPaginationUntilFocusItemVisible('schoolClass', $schoolClass->id);
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

    /**
     * @param  'tasks'|'events'|'projects'|'classes'  $filterItemType
     */
    protected function applyDeepLinkListShell(
        string $filterItemType,
        bool $applyItemTypeToFilters = true,
        bool $clearSearch = true,
    ): void {
        if (! $this->preserveCurrentViewModeForFocus) {
            $this->viewMode = 'list';
        }
        if ($clearSearch) {
            $this->searchQuery = null;
        }
        if ($applyItemTypeToFilters) {
            $this->filterItemType = $filterItemType;
        }
        $this->listContextProjectId = null;
        $this->listContextEventId = null;
    }

    /**
     * Calendar date to jump to when aligning the workspace so an agenda item can appear in the list
     * (due date for tasks; start date for events and projects when present).
     */
    protected function resolveWorkspaceAnchorDateStringForModel(string $kind, Model $model): ?string
    {
        $timezone = (string) config('app.timezone', 'UTC');

        if ($kind === 'task' && $model instanceof Task) {
            if ($model->end_datetime !== null) {
                return $model->end_datetime->copy()->timezone($timezone)->toDateString();
            }
            if ($model->start_datetime !== null) {
                return $model->start_datetime->copy()->timezone($timezone)->toDateString();
            }

            return null;
        }

        if ($kind === 'event' && $model instanceof Event) {
            if ($model->start_datetime !== null) {
                return $model->start_datetime->copy()->timezone($timezone)->toDateString();
            }
            if ($model->end_datetime !== null) {
                return $model->end_datetime->copy()->timezone($timezone)->toDateString();
            }

            return null;
        }

        if ($kind === 'project' && $model instanceof Project) {
            if ($model->start_datetime !== null) {
                return $model->start_datetime->copy()->timezone($timezone)->toDateString();
            }
            if ($model->end_datetime !== null) {
                return $model->end_datetime->copy()->timezone($timezone)->toDateString();
            }

            return null;
        }

        if ($kind === 'schoolClass' && $model instanceof SchoolClass) {
            if ($model->start_datetime !== null) {
                return $model->start_datetime->copy()->timezone($timezone)->toDateString();
            }
            if ($model->end_datetime !== null) {
                return $model->end_datetime->copy()->timezone($timezone)->toDateString();
            }

            return null;
        }

        return null;
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
                $this->focusSchoolClassId = null;
            }

            return;
        }

        if (request()->query->has('event')) {
            $eid = (int) request()->query('event', 0);
            if ($eid > 0) {
                $this->focusEventId = $eid;
                $this->focusTaskId = null;
                $this->focusProjectId = null;
                $this->focusSchoolClassId = null;
            }

            return;
        }

        if (request()->query->has('project')) {
            $pid = (int) request()->query('project', 0);
            if ($pid > 0) {
                $this->focusProjectId = $pid;
                $this->focusTaskId = null;
                $this->focusEventId = null;
                $this->focusSchoolClassId = null;
            }

            return;
        }

        if (request()->query->has('school_class')) {
            $sid = (int) request()->query('school_class', 0);
            if ($sid > 0) {
                $this->focusSchoolClassId = $sid;
                $this->focusTaskId = null;
                $this->focusEventId = null;
                $this->focusProjectId = null;
            }
        }
    }

    /**
     * Livewire #[Computed] memoizes per HTTP request; tasks/events/projects use tasksPage/eventsPage/projectsPage.
     * Without clearing, expandPaginationUntilFocusItemVisible() reuses the first cached collection and never finds rows on later pages.
     */
    protected function clearPaginatedWorkspaceListCaches(): void
    {
        unset($this->tasks, $this->events, $this->projects, $this->schoolClassesForSelectedDate, $this->schoolClassesForWorkspaceList);
    }

    /**
     * True when the merged list has more rows than {@see $itemsPage} × {@see $itemsPerPage} can show (e.g. school classes only).
     */
    protected function hasMoreWorkspaceMergedListEntries(): bool
    {
        $allItems = $this->getAllListEntries();
        $effectiveItemsPerPage = max(1, $this->itemsPerPage);

        return $allItems->count() > ($this->itemsPage * $effectiveItemsPerPage);
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

            if (! $this->hasMoreTasks && ! $this->hasMoreEvents && ! $this->hasMoreProjects && ! $this->hasMoreWorkspaceMergedListEntries()) {
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

        if (method_exists($this, 'isDueStateFilterActive') && $this->isDueStateFilterActive()) {
            return collect();
        }

        // When search scope is "all items", main list shows all matching items; skip overdue bucket to avoid duplicates.
        if (
            method_exists($this, 'shouldSearchAllItems')
            && $this->shouldSearchAllItems()
            && (! method_exists($this, 'isOverdueStateFilterActive') || ! $this->isOverdueStateFilterActive())
        ) {
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

        // Early return: Skip overdue queries if filtered to projects/classes only
        if ($filterItemType === 'projects' || $filterItemType === 'classes') {
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
                ])
                ->withCount('comments')
                ->withCount('activityLogs')
                ->forUser($userId)
                ->withoutHiddenOverdueFeedItems($overdueAsOf)
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
                ->withCount('comments')
                ->withCount('activityLogs')
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
            ->get(['id', 'name', 'source', 'sync_enabled', 'exclude_overdue_items', 'import_past_months', 'last_synced_at', 'created_at']);

        if ($feeds->isEmpty()) {
            return [];
        }

        $feedIds = $feeds->pluck('id')->all();
        $taskStats = Task::query()
            ->selectRaw('calendar_feed_id, COUNT(*) as total_imported')
            ->whereIn('calendar_feed_id', $feedIds)
            ->where('source_type', TaskSourceType::Brightspace->value)
            ->groupBy('calendar_feed_id')
            ->get()
            ->keyBy('calendar_feed_id');

        return $feeds
            ->map(function (CalendarFeed $feed) use ($taskStats): array {
                $stats = $taskStats->get($feed->id);
                $lastSyncedAt = $feed->last_synced_at;

                return [
                    'id' => (int) $feed->id,
                    'name' => (string) ($feed->name ?: __('Untitled feed')),
                    'source' => (string) $feed->source,
                    'source_label' => ucfirst((string) $feed->source),
                    'exclude_overdue_items' => (bool) $feed->exclude_overdue_items,
                    'import_past_months' => (int) $feed->resolvedImportPastMonths(),
                    'total_imported' => (int) ($stats?->total_imported ?? 0),
                    'last_synced_human' => $lastSyncedAt?->diffForHumans() ?? __('Never'),
                    'last_synced_at' => $lastSyncedAt?->toIso8601String(),
                ];
            })
            ->sortBy(fn (array $row): array => [
                isset($row['last_synced_at']) && $row['last_synced_at']
                    ? -\Carbon\Carbon::parse((string) $row['last_synced_at'])->getTimestamp()
                    : PHP_INT_MAX,
            ])
            ->values()
            ->all();
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

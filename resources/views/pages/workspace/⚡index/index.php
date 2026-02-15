<?php

use App\Actions\Comment\CreateCommentAction;
use App\Actions\Comment\DeleteCommentAction;
use App\Actions\Comment\UpdateCommentAction;
use App\Actions\Collaboration\AcceptCollaborationInvitationAction;
use App\Actions\Collaboration\CreateCollaborationInvitationAction;
use App\Actions\Collaboration\DeclineCollaborationInvitationAction;
use App\Actions\Collaboration\DeleteCollaborationAction;
use App\Actions\Collaboration\UpdateCollaborationPermissionAction;
use App\Actions\Event\CreateEventAction;
use App\Actions\Event\DeleteEventAction;
use App\Actions\Event\ForceDeleteEventAction;
use App\Actions\Event\RestoreEventAction;
use App\Actions\Event\UpdateEventPropertyAction;
use App\Actions\Project\CreateProjectAction;
use App\Actions\Project\DeleteProjectAction;
use App\Actions\Project\ForceDeleteProjectAction;
use App\Actions\Project\RestoreProjectAction;
use App\Actions\Project\UpdateProjectPropertyAction;
use App\Actions\Tag\CreateTagAction;
use App\Actions\Tag\DeleteTagAction;
use App\Actions\FocusSession\AbandonFocusSessionAction;
use App\Actions\FocusSession\CompleteFocusSessionAction;
use App\Actions\FocusSession\GetActiveFocusSessionAction;
use App\Actions\FocusSession\PauseFocusSessionAction;
use App\Actions\FocusSession\ResumeFocusSessionAction;
use App\Actions\FocusSession\StartFocusSessionAction;
use App\Actions\Task\CreateTaskAction;
use App\Actions\Task\DeleteTaskAction;
use App\Actions\Task\ForceDeleteTaskAction;
use App\Actions\Task\RestoreTaskAction;
use App\Actions\Task\UpdateTaskPropertyAction;
use App\Livewire\Concerns\HandlesActivityLogs;
use App\Livewire\Concerns\HandlesFocusSessions;
use App\Livewire\Concerns\HandlesCollaborations;
use App\Livewire\Concerns\HandlesComments;
use App\Livewire\Concerns\HandlesEvents;
use App\Livewire\Concerns\HandlesFiltering;
use App\Livewire\Concerns\HandlesProjects;
use App\Livewire\Concerns\HandlesTags;
use App\Livewire\Concerns\HandlesTasks;
use App\Livewire\Concerns\HandlesTrash;
use App\Models\Event;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\EventService;
use App\Services\ProjectService;
use App\Services\TagService;
use App\Services\TaskService;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Workspace')]
class extends Component
{
    use AuthorizesRequests;
    use HandlesActivityLogs;
    use HandlesCollaborations;
    use HandlesComments;
    use HandlesEvents;
    use HandlesFiltering;
    use HandlesProjects;
    use HandlesTags;
    use HandlesFocusSessions;
    use HandlesTasks;
    use HandlesTrash;

    public string $selectedDate;

    public int $listRefresh = 0;

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
        StartFocusSessionAction $startFocusSessionAction
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
    }

    /**
     * Mount: fetch any in-progress focus session via getActiveFocusSession() and abandon it
     * so focus does not persist across reload, tab close, or navigation.
     */
    public function mount(): void
    {
        if (Auth::check()) {
            $this->authorize('viewAny', Task::class);
            $this->authorize('viewAny', Event::class);
            $this->authorize('viewAny', Project::class);
            $this->authorize('viewAny', Tag::class);
        }
        $this->selectedDate = now()->toDateString();
        $this->syncFilterTagIdFromTagIds();
        $session = $this->getActiveFocusSession();
        if ($session !== null) {
            $this->abandonFocusSession(
                (int) $session['id'],
                ['paused_seconds' => (int) ($session['paused_seconds'] ?? 0)]
            );
        }
        $this->activeFocusSession = null;
    }

    public function incrementListRefresh(): void
    {
        $this->listRefresh++;
    }

    /**
     * Get overdue tasks and events for the authenticated user.
     * Overdue = end/due date is before today (not the selected view date).
     * Returns a unified collection of entries with 'kind' and 'item' for rendering.
     */
    #[Computed]
    public function overdue(): Collection
    {
        $userId = Auth::id();

        if ($userId === null) {
            return collect();
        }

        $filterItemType = property_exists($this, 'filterItemType') ? $this->normalizeFilterValue($this->filterItemType) : null;

        $today = Carbon::today();

        $overdueTaskQuery = Task::query()
            ->with([
                'project',
                'tags',
                'collaborations',
                'collaborators',
                'collaborationInvitations.invitee',
                'comments.user',
            ])
            ->withCount('activityLogs')
            ->withRecentActivityLogs(5)
            ->forUser($userId)
            ->incomplete()
            ->overdue($today)
            ->whereDoesntHave('recurringTask');

        if (method_exists($this, 'applyOverdueTaskFilters')) {
            $this->applyOverdueTaskFilters($overdueTaskQuery);
        }

        $overdueTasks = $overdueTaskQuery->orderByPriority()->limit(50)->get()
            ->map(fn (Task $task) => ['kind' => 'task', 'item' => $task]);

        $overdueEventQuery = Event::query()
            ->with([
                'tags',
                'collaborations',
                'collaborators',
                'collaborationInvitations.invitee',
            ])
            ->withCount('activityLogs')
            ->withRecentActivityLogs(5)
            ->forUser($userId)
            ->notCancelled()
            ->notCompleted()
            ->overdue($today)
            ->whereDoesntHave('recurringEvent');

        if (method_exists($this, 'applyOverdueEventFilters')) {
            $this->applyOverdueEventFilters($overdueEventQuery);
        }

        $overdueEvents = $overdueEventQuery->orderBy('end_datetime')->limit(50)->get()
            ->map(fn (Event $event) => ['kind' => 'event', 'item' => $event]);

        if ($filterItemType !== null) {
            if ($filterItemType === 'tasks') {
                return collect($overdueTasks->sortBy(fn (array $entry) => $entry['item']->end_datetime?->timestamp ?? 0)->values()->all());
            }
            if ($filterItemType === 'events') {
                return collect($overdueEvents->sortBy(fn (array $entry) => $entry['item']->end_datetime?->timestamp ?? 0)->values()->all());
            }
            if ($filterItemType === 'projects') {
                return collect();
            }
        }

        return collect($overdueTasks->all())
            ->merge($overdueEvents->all())
            ->sortBy(fn (array $entry) => $entry['item']->end_datetime?->timestamp ?? 0)
            ->values();
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

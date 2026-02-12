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
use App\Actions\Event\UpdateEventPropertyAction;
use App\Actions\Project\CreateProjectAction;
use App\Actions\Tag\CreateTagAction;
use App\Actions\Tag\DeleteTagAction;
use App\Actions\Project\DeleteProjectAction;
use App\Actions\Project\UpdateProjectPropertyAction;
use App\Actions\Task\CreateTaskAction;
use App\Actions\Task\DeleteTaskAction;
use App\Actions\Task\UpdateTaskPropertyAction;
use App\Livewire\Concerns\HandlesActivityLogs;
use App\Livewire\Concerns\HandlesCollaborations;
use App\Livewire\Concerns\HandlesComments;
use App\Livewire\Concerns\HandlesEvents;
use App\Livewire\Concerns\HandlesFiltering;
use App\Livewire\Concerns\HandlesProjects;
use App\Livewire\Concerns\HandlesTags;
use App\Livewire\Concerns\HandlesTasks;
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
    use HandlesTasks;

    public string $selectedDate;

    public int $listRefresh = 0;

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

    protected CreateCommentAction $createCommentAction;

    protected UpdateCommentAction $updateCommentAction;

    protected DeleteCommentAction $deleteCommentAction;

    protected CreateCollaborationInvitationAction $createCollaborationInvitationAction;

    protected AcceptCollaborationInvitationAction $acceptCollaborationInvitationAction;

    protected DeclineCollaborationInvitationAction $declineCollaborationInvitationAction;

    protected UpdateCollaborationPermissionAction $updateCollaborationPermissionAction;

    protected DeleteCollaborationAction $deleteCollaborationAction;

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
        CreateCommentAction $createCommentAction,
        UpdateCommentAction $updateCommentAction,
        DeleteCommentAction $deleteCommentAction,
        CreateCollaborationInvitationAction $createCollaborationInvitationAction,
        AcceptCollaborationInvitationAction $acceptCollaborationInvitationAction,
        DeclineCollaborationInvitationAction $declineCollaborationInvitationAction,
        UpdateCollaborationPermissionAction $updateCollaborationPermissionAction,
        DeleteCollaborationAction $deleteCollaborationAction
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
        $this->createCommentAction = $createCommentAction;
        $this->updateCommentAction = $updateCommentAction;
        $this->deleteCommentAction = $deleteCommentAction;
        $this->createCollaborationInvitationAction = $createCollaborationInvitationAction;
        $this->acceptCollaborationInvitationAction = $acceptCollaborationInvitationAction;
        $this->declineCollaborationInvitationAction = $declineCollaborationInvitationAction;
        $this->updateCollaborationPermissionAction = $updateCollaborationPermissionAction;
        $this->deleteCollaborationAction = $deleteCollaborationAction;
    }

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

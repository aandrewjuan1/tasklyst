<?php

use App\Actions\Event\CreateEventAction;
use App\Actions\Event\DeleteEventAction;
use App\Actions\Event\UpdateEventPropertyAction;
use App\Actions\Task\CreateTaskAction;
use App\Actions\Task\DeleteTaskAction;
use App\Actions\Task\UpdateTaskPropertyAction;
use App\Livewire\Concerns\HandlesWorkspaceFiltering;
use App\Livewire\Concerns\HandlesWorkspaceItems;
use App\Models\Event;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Services\EventService;
use App\Services\ProjectService;
use App\Services\RecurrenceExpander;
use App\Services\TagService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Workspace')]
class extends Component
{
    use AuthorizesRequests;
    use HandlesWorkspaceFiltering;
    use HandlesWorkspaceItems;

    public string $selectedDate;

    public int $listRefresh = 0;

    protected TaskService $taskService;

    protected ProjectService $projectService;

    protected EventService $eventService;

    protected TagService $tagService;

    protected RecurrenceExpander $recurrenceExpander;

    protected CreateEventAction $createEventAction;

    protected CreateTaskAction $createTaskAction;

    protected DeleteEventAction $deleteEventAction;

    protected DeleteTaskAction $deleteTaskAction;

    protected UpdateEventPropertyAction $updateEventPropertyAction;

    protected UpdateTaskPropertyAction $updateTaskPropertyAction;

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
        RecurrenceExpander $recurrenceExpander,
        CreateEventAction $createEventAction,
        CreateTaskAction $createTaskAction,
        DeleteEventAction $deleteEventAction,
        DeleteTaskAction $deleteTaskAction,
        UpdateEventPropertyAction $updateEventPropertyAction,
        UpdateTaskPropertyAction $updateTaskPropertyAction
    ): void {
        $this->taskService = $taskService;
        $this->projectService = $projectService;
        $this->eventService = $eventService;
        $this->tagService = $tagService;
        $this->recurrenceExpander = $recurrenceExpander;
        $this->createEventAction = $createEventAction;
        $this->createTaskAction = $createTaskAction;
        $this->deleteEventAction = $deleteEventAction;
        $this->deleteTaskAction = $deleteTaskAction;
        $this->updateEventPropertyAction = $updateEventPropertyAction;
        $this->updateTaskPropertyAction = $updateTaskPropertyAction;
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
    }

    public function incrementListRefresh(): void
    {
        $this->listRefresh++;
    }
};

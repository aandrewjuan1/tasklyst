<?php

use App\Livewire\Concerns\HandlesWorkspaceItems;
use App\Services\EventService;
use App\Services\ProjectService;
use App\Services\RecurrenceExpander;
use App\Services\TagService;
use App\Services\TaskService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Workspace')]
class extends Component
{
    use AuthorizesRequests;
    use HandlesWorkspaceItems;

    public string $selectedDate;

    public int $listRefresh = 0;

    protected TaskService $taskService;

    protected ProjectService $projectService;

    protected EventService $eventService;

    protected TagService $tagService;

    protected RecurrenceExpander $recurrenceExpander;

    /**
     * @var array<string, mixed>
     */
    public array $taskPayload = [];

    /**
     * @var array<string, mixed>
     */
    public array $eventPayload = [];

    public function boot(TaskService $taskService, ProjectService $projectService, EventService $eventService, TagService $tagService, RecurrenceExpander $recurrenceExpander): void
    {
        $this->taskService = $taskService;
        $this->projectService = $projectService;
        $this->eventService = $eventService;
        $this->tagService = $tagService;
        $this->recurrenceExpander = $recurrenceExpander;
    }

    public function mount(): void
    {
        $this->selectedDate = now()->toDateString();
    }

    public function incrementListRefresh(): void
    {
        $this->listRefresh++;
    }
};

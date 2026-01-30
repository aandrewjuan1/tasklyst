<?php

use App\Livewire\Concerns\HandlesWorkspaceItems;
use App\Services\EventService;
use App\Services\ProjectService;
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

    /**
     * @var array<string, mixed>
     */
    public array $taskPayload = [];

    public function boot(TaskService $taskService, ProjectService $projectService, EventService $eventService, TagService $tagService): void
    {
        $this->taskService = $taskService;
        $this->projectService = $projectService;
        $this->eventService = $eventService;
        $this->tagService = $tagService;
    }

    public function mount(): void
    {
        $this->selectedDate = now()->toDateString();
    }
};

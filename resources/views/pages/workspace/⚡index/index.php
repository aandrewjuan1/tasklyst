<?php

use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Services\TaskService;
use App\Support\Validation\TaskPayloadValidation;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon as SupportCarbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Workspace')]
class extends Component
{
    use AuthorizesRequests;

    public string $selectedDate;

    public int $listRefresh = 0;

    protected TaskService $taskService;

    /**
     * @var array<string, mixed>
     */
    public array $taskPayload = [];

    public function boot(TaskService $taskService): void
    {
        $this->taskService = $taskService;
    }

    public function mount(): void
    {
        $this->selectedDate = now()->toDateString();
    }

    /**
     * Create a new task for the authenticated user.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createTask(array $payload): void
    {
        $user = Auth::user();

        if ($user === null) {
            $this->dispatch('toast', type: 'error', message: __('You must be logged in to create tasks.'));

            return;
        }

        $this->authorize('create', Task::class);

        $this->taskPayload = array_replace_recursive(TaskPayloadValidation::defaults(), $payload);

        try {
            /** @var array{taskPayload: array<string, mixed>} $validated */
            $validated = $this->validate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Task validation failed', [
                'errors' => $e->errors(),
                'payload' => $this->taskPayload,
            ]);
            $this->dispatch('toast', type: 'error', message: __('Please fix the task details and try again.'));

            return;
        }

        $validatedTask = $validated['taskPayload'];

        $title = (string) ($validatedTask['title'] ?? '');
        $startDatetime = $this->parseOptionalDatetime($validatedTask['startDatetime'] ?? null);
        $endDatetime = $this->parseOptionalDatetime($validatedTask['endDatetime'] ?? null);

        $taskAttributes = [
            'title' => $title,
            'status' => $validatedTask['status'] ?? null,
            'priority' => $validatedTask['priority'] ?? null,
            'complexity' => $validatedTask['complexity'] ?? null,
            'duration' => $validatedTask['duration'] ?? null,
            'start_datetime' => $startDatetime,
            'end_datetime' => $endDatetime,
            'project_id' => $validatedTask['projectId'] ?? null,
        ];

        try {
            $task = $this->taskService->createTask($user, $taskAttributes);
        } catch (\Throwable $e) {
            Log::error('Failed to create task from workspace.', [
                'user_id' => $user->id,
                'payload' => $this->taskPayload,
                'exception' => $e,
            ]);

            $this->dispatch('toast', type: 'error', message: __('Something went wrong creating the task.'));

            return;
        }

        $this->listRefresh++;
        $this->dispatch('task-created', id: $task->id, title: $task->title);
        $this->dispatch('toast', type: 'success', message: __('Task created.'));
    }

    /**
     * Delete a task for the authenticated user.
     */
    public function deleteTask(int $taskId): void
    {
        $user = Auth::user();

        if ($user === null) {
            $this->dispatch('toast', type: 'error', message: __('You must be logged in to delete tasks.'));

            return;
        }

        $task = Task::query()->find($taskId);

        if ($task === null) {
            $this->dispatch('toast', type: 'error', message: __('Task not found.'));

            return;
        }

        $this->authorize('delete', $task);

        try {
            $deleted = $this->taskService->deleteTask($task);
        } catch (\Throwable $e) {
            Log::error('Failed to delete task from workspace.', [
                'user_id' => $user->id,
                'task_id' => $taskId,
                'exception' => $e,
            ]);

            $this->dispatch('toast', type: 'error', message: __('Something went wrong deleting the task.'));

            return;
        }

        if (! $deleted) {
            $this->dispatch('toast', type: 'error', message: __('Something went wrong deleting the task.'));

            return;
        }

        $this->listRefresh++;
        $this->dispatch('toast', type: 'success', message: __('Task deleted.'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return TaskPayloadValidation::rules();
    }

    private function parseOptionalDatetime(mixed $value): ?SupportCarbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $parsed = SupportCarbon::parse((string) $value);

            return $parsed;
        } catch (\Throwable $e) {
            Log::error('Failed to parse datetime', [
                'input' => $value,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get tasks for the selected date for the authenticated user.
     */
    #[Computed]
    public function tasks(): Collection
    {
        $userId = Auth::id();

        if ($userId === null) {
            return collect();
        }

        $date = Carbon::parse($this->selectedDate);

        return Task::query()
            ->with([
                'project',
                'event',
                'recurringTask',
                'tags',
                'collaborations',
            ])
            ->forUser($userId)
            ->incomplete()
            ->relevantForDate($date)
            ->orderBy('start_datetime')
            ->limit(50)
            ->get();
    }

    /**
     * Get projects for the selected date for the authenticated user.
     */
    #[Computed]
    public function projects(): Collection
    {
        $userId = Auth::id();

        if ($userId === null) {
            return collect();
        }

        $date = Carbon::parse($this->selectedDate);

        return Project::query()
            ->with([
                'tasks',
                'collaborations',
            ])
            ->forUser($userId)
            ->notArchived()
            ->activeForDate($date)
            ->orderBy('start_datetime')
            ->limit(50)
            ->get();
    }

    /**
     * Get events for the selected date for the authenticated user.
     */
    #[Computed]
    public function events(): Collection
    {
        $userId = Auth::id();

        if ($userId === null) {
            return collect();
        }

        $date = Carbon::parse($this->selectedDate);

        return Event::query()
            ->with([
                'recurringEvent',
                'collaborations',
            ])
            ->forUser($userId)
            ->activeForDate($date)
            ->orderBy('start_datetime')
            ->limit(50)
            ->get();
    }
};

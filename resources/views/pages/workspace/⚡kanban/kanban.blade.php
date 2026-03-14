<div class="space-y-4">
    <x-workspace.item-creation :tags="$tags" :projects="$projects" :active-focus-session="$activeFocusSession" />

    @php
    use App\Enums\TaskStatus;

    $overdueTaskItems = $overdue->filter(fn (array $entry) => ($entry['kind'] ?? '') === 'task')->map(fn (array $entry) => $entry['item']);
    $allTasks = $tasks->merge($overdueTaskItems)->unique('id')->values();
    $effectiveStatusValue = fn ($task) => $task->effectiveStatusForDate?->value ?? $task->status?->value;
    $tasksByStatus = collect(TaskStatus::cases())->mapWithKeys(fn (TaskStatus $status) => [$status->value => $allTasks->filter(fn ($task) => $effectiveStatusValue($task) === $status->value)->values()])->all();
    $defaultWorkDurationMinutes = config('focus.default_duration_minutes', config('pomodoro.defaults.work_duration_minutes', 25));
    $kanbanColumns = collect(TaskStatus::cases())
        ->mapWithKeys(fn (TaskStatus $status) => [
            $status->value => [
                'count' => ($tasksByStatus[$status->value] ?? collect())->count(),
            ],
        ])
        ->all();

    $kanbanStatusMeta = [
        TaskStatus::ToDo->value => [
            'label' => TaskStatus::ToDo->label(),
            'class' => 'bg-gray-800/10 text-gray-800 dark:bg-gray-300/20 dark:text-gray-300',
        ],
        TaskStatus::Doing->value => [
            'label' => TaskStatus::Doing->label(),
            'class' => 'bg-blue-800/10 text-blue-800 dark:bg-blue-300/20 dark:text-blue-300',
        ],
        TaskStatus::Done->value => [
            'label' => TaskStatus::Done->label(),
            'class' => 'bg-green-800/10 text-green-800 dark:bg-green-300/20 dark:text-green-300',
        ],
    ];

    $kanbanConfig = [
        'selectedDate' => $selectedDate,
        'columns' => $kanbanColumns,
        'statusMeta' => $kanbanStatusMeta,
        'moveErrorToast' => __('Failed to move task. Please try again.'),
    ];
@endphp
<div
    class="w-full space-y-4"
    role="region"
    aria-label="{{ __('Kanban board') }}"
    wire:ignore
    x-data="kanbanBoard({{ \Illuminate\Support\Js::from($kanbanConfig) }})"
    @task-status-updated.window="onTaskStatusUpdated($event.detail)"
    @task-status-updated="onTaskStatusUpdated($event.detail)"
>
    <div class="w-full min-w-0">
        <div class="grid min-h-[50vh] w-full gap-3 sm:gap-4 md:grid-cols-3" style="min-width: min-content;">
            @foreach(TaskStatus::cases() as $status)
                @php
                    $columnTasks = $tasksByStatus[$status->value] ?? collect();
                @endphp
                <div
                    data-kanban-column
                    data-status="{{ $status->value }}"
                    role="group"
                    aria-label="{{ $status->label() }}"
                    class="flex w-full flex-col rounded-xl border border-border/60 bg-muted/30 shadow-sm transition-colors"
                    :class="{ 'ring-2 ring-primary/30 bg-muted/40': dragOverColumn === $el && draggedTaskId }"
                    @dragover.prevent="onDragOver($event)"
                    @drop.prevent="onDrop('{{ $status->value }}', $event)"
                    @dragleave="onDragLeave($event)"
                >
                    <div class="flex items-center justify-between gap-2 border-b border-border/60 px-3 py-2">
                        <h3 class="text-sm font-semibold text-foreground">{{ $status->label() }}</h3>
                        <span
                            class="rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
                            x-text="columns['{{ $status->value }}']?.count ?? {{ $columnTasks->count() }}"
                        ></span>
                    </div>
                    <div data-kanban-column-cards class="flex min-h-[140px] flex-1 flex-col gap-2.5 overflow-visible p-2.5 sm:min-h-[160px] sm:gap-3 sm:p-3">
                        @foreach($columnTasks as $task)
                            <div
                                data-kanban-card
                                data-task-id="{{ $task->id }}"
                                draggable="true"
                                role="button"
                                tabindex="0"
                                aria-grabbed="false"
                                aria-label="{{ __('Drag to move task') }}"
                                class="cursor-grab active:cursor-grabbing touch-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
                                @dragstart="onDragStart($event)"
                                @dragend="onDragEnd()"
                            >
                                <x-workspace.list-item-card
                                    kind="task"
                                    :item="$task"
                                    layout="kanban"
                                    :list-filter-date="$overdue->contains(fn (array $e) => ($e['kind'] ?? '') === 'task' && (isset($e['item']) && $e['item']->id === $task->id)) ? null : $selectedDate"
                                    :filters="$filters"
                                    :available-tags="$tags"
                                    :is-overdue="$task->end_datetime && $task->end_datetime->isPast()"
                                    :active-focus-session="$activeFocusSession"
                                    :default-work-duration-minutes="$defaultWorkDurationMinutes"
                                    :pomodoro-settings="$pomodoroSettings"
                                    wire:key="kanban-task-{{ $task->id }}"
                                />
                            </div>
                        @endforeach
                        <p
                            class="py-4 text-center text-xs text-muted-foreground"
                            x-show="(columns['{{ $status->value }}']?.count ?? {{ $columnTasks->count() }}) === 0"
                            x-cloak
                        >
                            {{ __('No tasks') }}
                        </p>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
</div>

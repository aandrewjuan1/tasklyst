<div class="space-y-4">
    <x-workspace.item-creation :tags="$tags" :projects="$projects" :active-focus-session="$activeFocusSession" mode="kanban" />

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
            'class' => 'bg-gray-800/10 text-gray-800',
        ],
        TaskStatus::Doing->value => [
            'label' => TaskStatus::Doing->label(),
            'class' => 'bg-blue-800/10 text-blue-800',
        ],
        TaskStatus::Done->value => [
            'label' => TaskStatus::Done->label(),
            'class' => 'bg-green-800/10 text-green-800',
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
    @workspace-item-visibility-updated.window="onItemVisibilityUpdated($event.detail)"
>
    <div class="w-full min-w-0">
        <div class="grid min-h-[50vh] w-full gap-3 sm:gap-4 md:grid-cols-3" style="min-width: min-content;">
            @foreach(TaskStatus::cases() as $status)
                @php
                    $columnTasks = $tasksByStatus[$status->value] ?? collect();
                    $kanbanColumnShell = 'border border-zinc-200/80 bg-linear-to-b to-background/95 shadow-[0_10px_28px_-12px_rgb(33_52_72/0.08)] ring-1 ring-zinc-200/35 dark:border-zinc-700/70 dark:to-zinc-950 dark:shadow-[0_12px_32px_-14px_rgb(0_0_0/0.35)] dark:ring-zinc-700/40';
                    $kanbanColumnTheme = match ($status->value) {
                        'to_do' => [
                            'shell' => $kanbanColumnShell.' from-brand-light-lavender/40 via-white dark:from-zinc-900/50 dark:via-zinc-900/35',
                            'accent' => 'border-l-4 border-l-brand-navy-blue/22 dark:border-l-zinc-500/50',
                            'header' => 'border-b border-brand-blue/10 bg-brand-light-lavender/55 dark:border-zinc-600/50 dark:bg-zinc-800/45',
                            'headerTitle' => 'text-sm font-semibold tracking-tight text-brand-navy-blue dark:text-zinc-100',
                            'count' => 'min-w-[1.75rem] rounded-full bg-white/90 px-2 py-0.5 text-center text-xs font-semibold tabular-nums text-brand-navy-blue shadow-sm ring-1 ring-brand-blue/12 dark:bg-zinc-800/90 dark:text-zinc-200 dark:ring-zinc-600/50',
                            'body' => 'bg-white/35 dark:bg-zinc-950/25',
                            'dragOver' => 'ring-2 ring-brand-blue/35 ring-offset-2 ring-offset-background shadow-md dark:ring-brand-blue/40 dark:ring-offset-zinc-950',
                        ],
                        'doing' => [
                            'shell' => $kanbanColumnShell.' from-brand-light-blue/35 via-white dark:from-zinc-900/45 dark:via-zinc-900/30',
                            'accent' => 'border-l-4 border-l-brand-blue/35 dark:border-l-brand-light-blue/25',
                            'header' => 'border-b border-brand-blue/12 bg-brand-light-blue/45 dark:border-brand-blue/20 dark:bg-brand-blue/10',
                            'headerTitle' => 'text-sm font-semibold tracking-tight text-brand-navy-blue dark:text-zinc-100',
                            'count' => 'min-w-[1.75rem] rounded-full bg-white/90 px-2 py-0.5 text-center text-xs font-semibold tabular-nums text-brand-navy-blue shadow-sm ring-1 ring-brand-blue/15 dark:bg-zinc-800/90 dark:text-zinc-200 dark:ring-brand-blue/25',
                            'body' => 'bg-brand-light-blue/12 dark:bg-zinc-950/30',
                            'dragOver' => 'ring-2 ring-brand-blue/40 ring-offset-2 ring-offset-background shadow-md dark:ring-brand-light-blue/30 dark:ring-offset-zinc-950',
                        ],
                        'done' => [
                            'shell' => $kanbanColumnShell.' from-emerald-50/30 via-white dark:from-emerald-950/12 dark:via-zinc-900/35',
                            'accent' => 'border-l-4 border-l-emerald-400/30 dark:border-l-emerald-500/25',
                            'header' => 'border-b border-emerald-200/40 bg-emerald-50/35 dark:border-emerald-800/25 dark:bg-emerald-950/15',
                            'headerTitle' => 'text-sm font-semibold tracking-tight text-brand-navy-blue dark:text-zinc-100',
                            'count' => 'min-w-[1.75rem] rounded-full bg-white/90 px-2 py-0.5 text-center text-xs font-semibold tabular-nums text-brand-navy-blue shadow-sm ring-1 ring-emerald-200/50 dark:bg-zinc-800/90 dark:text-zinc-200 dark:ring-emerald-800/40',
                            'body' => 'bg-emerald-50/10 dark:bg-emerald-950/10',
                            'dragOver' => 'ring-2 ring-emerald-400/35 ring-offset-2 ring-offset-background shadow-md dark:ring-emerald-500/30 dark:ring-offset-zinc-950',
                        ],
                        default => [
                            'shell' => $kanbanColumnShell.' from-muted/30 via-background dark:from-zinc-900/40',
                            'accent' => 'border-l-4 border-l-border/60',
                            'header' => 'border-b border-border/50 bg-muted/50 dark:bg-zinc-800/40',
                            'headerTitle' => 'text-sm font-semibold text-foreground',
                            'count' => 'rounded-full bg-background px-2 py-0.5 text-xs font-semibold tabular-nums text-foreground ring-1 ring-border/50',
                            'body' => 'bg-muted/15',
                            'dragOver' => 'ring-2 ring-brand-blue/30 ring-offset-2 ring-offset-background',
                        ],
                    };
                    $kanbanEmptyVisual = match ($status->value) {
                        'to_do' => [
                            'panel' => 'border-dashed border-zinc-300/55 bg-linear-to-b from-brand-light-lavender/25 via-transparent to-transparent dark:border-zinc-600/45 dark:from-zinc-500/[0.05]',
                            'iconRing' => 'ring-brand-blue/15 dark:ring-zinc-500/30',
                            'iconWrap' => 'bg-brand-light-lavender/60 text-brand-navy-blue/70 dark:bg-zinc-800/60 dark:text-zinc-300',
                            'icon' => 'inbox',
                        ],
                        'doing' => [
                            'panel' => 'border-dashed border-brand-blue/20 bg-linear-to-b from-brand-light-blue/20 via-transparent to-transparent dark:border-brand-blue/25 dark:from-brand-blue/[0.06]',
                            'iconRing' => 'ring-brand-blue/15 dark:ring-brand-blue/20',
                            'iconWrap' => 'bg-brand-light-blue/70 text-brand-blue dark:bg-brand-blue/15 dark:text-brand-light-blue',
                            'icon' => 'bolt',
                        ],
                        'done' => [
                            'panel' => 'border-dashed border-emerald-200/60 bg-linear-to-b from-emerald-50/30 via-transparent to-transparent dark:border-emerald-800/30 dark:from-emerald-500/[0.05]',
                            'iconRing' => 'ring-emerald-300/40 dark:ring-emerald-600/25',
                            'iconWrap' => 'bg-emerald-50/80 text-emerald-700/80 dark:bg-emerald-950/40 dark:text-emerald-300/90',
                            'icon' => 'check-circle',
                        ],
                        default => [
                            'panel' => 'border-dashed border-border/45 bg-muted/10',
                            'iconRing' => 'ring-border/15',
                            'iconWrap' => 'bg-muted text-muted-foreground',
                            'icon' => 'inbox',
                        ],
                    };
                @endphp
                <div
                    data-kanban-column
                    data-status="{{ $status->value }}"
                    role="group"
                    aria-label="{{ $status->label() }}"
                    class="flex w-full flex-col overflow-visible rounded-xl transition-[box-shadow,ring] duration-200 ease-out {{ $kanbanColumnTheme['shell'] }} {{ $kanbanColumnTheme['accent'] }}"
                    :class="dragOverColumn === $el && draggedTaskId ? @js($kanbanColumnTheme['dragOver']) : ''"
                    @dragover.prevent="onDragOver($event)"
                    @drop.prevent="onDrop('{{ $status->value }}', $event)"
                    @dragleave="onDragLeave($event)"
                >
                    <div class="flex items-center justify-between gap-3 px-3 py-2.5 sm:px-4 sm:py-3 {{ $kanbanColumnTheme['header'] }}">
                        <h3 class="{{ $kanbanColumnTheme['headerTitle'] }}">{{ $status->label() }}</h3>
                        <span
                            class="{{ $kanbanColumnTheme['count'] }}"
                            x-text="columns['{{ $status->value }}']?.count ?? {{ $columnTasks->count() }}"
                        >{{ $columnTasks->count() }}</span>
                    </div>
                    <div data-kanban-column-cards class="flex min-h-[160px] flex-1 flex-col gap-2.5 overflow-visible p-2.5 sm:min-h-[180px] sm:gap-3 sm:p-3 {{ $kanbanColumnTheme['body'] }}">
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
                        <div
                            class="flex flex-1 flex-col items-center justify-center gap-3 rounded-xl px-4 py-8 text-center sm:py-10 {{ $kanbanEmptyVisual['panel'] }}"
                            x-show="(columns['{{ $status->value }}']?.count ?? {{ $columnTasks->count() }}) === 0"
                            @if($columnTasks->isNotEmpty())
                            style="display: none"
                            @endif
                            role="status"
                            aria-label="{{ __('Empty column') }}"
                        >
                            <div
                                class="flex size-12 shrink-0 items-center justify-center rounded-2xl ring-1 {{ $kanbanEmptyVisual['iconRing'] }} {{ $kanbanEmptyVisual['iconWrap'] }}"
                                aria-hidden="true"
                            >
                                <flux:icon name="{{ $kanbanEmptyVisual['icon'] }}" class="size-6 opacity-90" />
                            </div>
                            <div class="max-w-[16rem] space-y-1.5">
                                <p class="text-sm font-semibold tracking-tight text-foreground">
                                    {{ __('No tasks in this column') }}
                                </p>
                                <p class="text-xs leading-relaxed text-muted-foreground">
                                    {{ __('Drag a task here from another column, or add one with the form above.') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
</div>

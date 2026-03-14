<div class="w-full space-y-4">
    <x-workspace.item-creation :tags="$tags" :projects="$projects" :active-focus-session="$activeFocusSession" />

    @php
    use App\Enums\TaskStatus;

    $overdueTaskItems = $overdue->filter(fn (array $entry) => ($entry['kind'] ?? '') === 'task')->map(fn (array $entry) => $entry['item']);
    $allTasks = $tasks->merge($overdueTaskItems)->unique('id')->values();
    $tasksByStatus = collect(TaskStatus::cases())->mapWithKeys(fn (TaskStatus $status) => [$status->value => $allTasks->filter(fn ($task) => $task->status?->value === $status->value)->values()])->all();
    $defaultWorkDurationMinutes = config('focus.default_duration_minutes', config('pomodoro.defaults.work_duration_minutes', 25));
@endphp
<div
    class="w-full space-y-4"
    role="region"
    aria-label="{{ __('Kanban board') }}"
    wire:ignore
    x-data="{
        draggedTaskId: null,
        sourceColumn: null,
        cardElement: null,
        pendingIds: new Set(),
        dragOverColumn: null,
        onDragStart(event) {
            const card = event.target.closest('[data-kanban-card]');
            if (!card) return;
            const taskId = card.getAttribute('data-task-id');
            if (!taskId || this.pendingIds.has(Number(taskId))) return;
            this.draggedTaskId = Number(taskId);
            this.sourceColumn = card.closest('[data-kanban-column]');
            this.cardElement = card;
            event.dataTransfer.setData('text/plain', taskId);
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('application/json', JSON.stringify({ taskId }));
            if (event.dataTransfer.setDragImage) {
                event.dataTransfer.setDragImage(card, 0, 0);
            }
            card.setAttribute('aria-grabbed', 'true');
        },
        onDragEnd(event) {
            const card = event.target.closest('[data-kanban-card]');
            if (card) card.setAttribute('aria-grabbed', 'false');
            this.draggedTaskId = null;
            this.sourceColumn = null;
            this.cardElement = null;
            this.dragOverColumn = null;
        },
        async onDrop(targetStatus, event) {
            event.preventDefault();
            this.dragOverColumn = null;
            const taskIdStr = event.dataTransfer.getData('text/plain');
            if (!taskIdStr) return;
            const taskId = Number(taskIdStr);
            const targetColumn = event.currentTarget.closest('[data-kanban-column]');
            if (!targetColumn || !this.sourceColumn || this.sourceColumn === targetColumn) return;
            if (this.pendingIds.has(taskId)) return;
            this.pendingIds.add(taskId);
            const sourceCards = this.sourceColumn.querySelector('[data-kanban-column-cards]');
            const targetCards = targetColumn.querySelector('[data-kanban-column-cards]');
            const snapshot = { sourceColumn: this.sourceColumn, sourceCards, cardElement: this.cardElement };
            try {
                targetCards.appendChild(this.cardElement);
                const promise = $wire.$parent.$call('updateTaskProperty', taskId, 'status', targetStatus, true);
                await promise;
            } catch (error) {
                if (snapshot.sourceCards && snapshot.cardElement) {
                    snapshot.sourceCards.appendChild(snapshot.cardElement);
                }
                $wire.dispatch('toast', { type: 'error', message: '{{ __('Failed to move task. Please try again.') }}' });
            } finally {
                this.pendingIds.delete(taskId);
                this.draggedTaskId = null;
                this.sourceColumn = null;
                this.cardElement = null;
            }
        },
        onDragOver(event) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            this.dragOverColumn = event.currentTarget.closest('[data-kanban-column]');
        },
        onDragLeave(event) {
            const column = event.currentTarget.closest('[data-kanban-column]');
            if (column && !column.contains(event.relatedTarget)) {
                this.dragOverColumn = null;
            }
        },
    }"
>
    <div class="flex min-w-0 overflow-x-auto pb-2">
        <div class="flex gap-4" style="min-width: min-content;">
            @foreach(TaskStatus::cases() as $status)
                @php
                    $columnTasks = $tasksByStatus[$status->value] ?? collect();
                @endphp
                <div
                    data-kanban-column
                    data-status="{{ $status->value }}"
                    role="group"
                    aria-label="{{ $status->label() }}"
                    class="flex w-72 shrink-0 flex-col rounded-xl border border-border/60 bg-muted/20 transition-colors"
                    :class="{ 'ring-2 ring-primary/30 bg-muted/40': dragOverColumn === $el && draggedTaskId }"
                    @dragover.prevent="onDragOver($event)"
                    @drop.prevent="onDrop('{{ $status->value }}', $event)"
                    @dragleave="onDragLeave($event)"
                >
                    <div class="flex items-center justify-between gap-2 border-b border-border/60 px-3 py-2">
                        <h3 class="text-sm font-semibold text-foreground">{{ $status->label() }}</h3>
                        <span class="rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">{{ $columnTasks->count() }}</span>
                    </div>
                    <div data-kanban-column-cards class="flex min-h-[120px] flex-col gap-3 p-3">
                        @forelse($columnTasks as $task)
                            <div
                                data-kanban-card
                                data-task-id="{{ $task->id }}"
                                draggable="true"
                                role="button"
                                tabindex="0"
                                aria-grabbed="false"
                                aria-label="{{ __('Drag to move task') }}"
                                class="cursor-grab active:cursor-grabbing touch-none"
                                @dragstart="onDragStart($event)"
                                @dragend="onDragEnd()"
                            >
                                <x-workspace.list-item-card
                                    kind="task"
                                    :item="$task"
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
                        @empty
                            <p class="py-4 text-center text-xs text-muted-foreground">{{ __('No tasks') }}</p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
</div>

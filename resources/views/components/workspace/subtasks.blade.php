@props([
    'item',
    'kind' => null,
])

@php
    use App\Enums\TaskStatus;
    use App\Enums\TaskPriority;

    $parentProperty = match ($kind) {
        'project' => 'projectId',
        'event' => 'eventId',
        'schoolclass' => 'schoolClassId',
        default => 'eventId',
    };
    $parentId = $item->id;

    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Task> $tasks */
    $tasks = $item->tasks ?? collect();
    $totalTasks = (int) ($item->tasks_count ?? $tasks->count());
    $subtasksPanelId = 'subtasks-panel-'.($kind ?? 'item').'-'.$item->id;

    $statusClassMap = [
        TaskStatus::ToDo->value => 'bg-gray-800/10 text-gray-800 dark:bg-gray-300/20 dark:text-gray-300',
        TaskStatus::Doing->value => 'bg-blue-800/10 text-blue-800 dark:bg-blue-300/20 dark:text-blue-300',
        TaskStatus::Done->value => 'bg-green-800/10 text-green-800 dark:bg-green-300/20 dark:text-green-300',
    ];
    $priorityClassMap = [
        TaskPriority::Low->value => 'bg-sky-800/10 text-sky-800 dark:bg-sky-300/20 dark:text-sky-300',
        TaskPriority::Medium->value => 'bg-yellow-800/10 text-yellow-800 dark:bg-yellow-300/20 dark:text-yellow-300',
        TaskPriority::High->value => 'bg-orange-800/10 text-orange-800 dark:bg-orange-300/20 dark:text-orange-300',
        TaskPriority::Urgent->value => 'bg-red-800/10 text-red-800 dark:bg-red-300/20 dark:text-red-300',
    ];

    $tasksForAlpine = $tasks->map(function (\App\Models\Task $task) use ($statusClassMap, $priorityClassMap): array {
        $statusValue = $task->status?->value ?? '';
        $priorityValue = $task->priority?->value ?? '';

        return [
            'id' => $task->id,
            'title' => $task->title,
            'statusLabel' => $task->status?->label() ?? '',
            'statusClass' => $statusClassMap[$statusValue] ?? 'bg-muted text-muted-foreground',
            'dueLabel' => $task->end_datetime?->translatedFormat('M j · H:i'),
            'priorityLabel' => $task->priority?->label(),
            'priorityClass' => $priorityClassMap[$priorityValue] ?? 'bg-muted text-muted-foreground',
            'durationLabel' => $task->duration ? \App\Models\Task::formatDuration($task->duration) : null,
        ];
    })->values()->all();
@endphp

<div
    wire:ignore
    class="mt-1.5 pt-1.5 text-[11px]"
    style="{{ $totalTasks > 0 ? '' : 'display: none;' }}"
    x-data="{
        isOpen: false,
        tasks: @js($tasksForAlpine),
        parentProperty: @js($parentProperty),
        parentId: @js($parentId),
        removeErrorToast: @js(__('Could not remove task from :parent. Try again.', ['parent' => $kind === 'project' ? __('project') : ($kind === 'event' ? __('event') : __('class'))])),
        removeSuccessToast: @js(__('Task removed from :parent.', ['parent' => $kind === 'project' ? __('project') : ($kind === 'event' ? __('event') : __('class'))])),
        removingTaskIds: new Set(),
        defaultStatusClass: @js($statusClassMap[TaskStatus::ToDo->value] ?? 'bg-muted text-muted-foreground'),
        get totalCount() { return this.tasks.length; },
        toggle() {
            this.isOpen = !this.isOpen;
        },
        onSubtaskAdded(detail) {
            if (!detail || detail.taskId == null) return;
            const matchesProject = this.parentProperty === 'projectId' && detail.projectId != null && Number(detail.projectId) === Number(this.parentId);
            const matchesEvent = this.parentProperty === 'eventId' && detail.eventId != null && Number(detail.eventId) === Number(this.parentId);
            const matchesSchoolClass = this.parentProperty === 'schoolClassId' && detail.schoolClassId != null && Number(detail.schoolClassId) === Number(this.parentId);
            if (!matchesProject && !matchesEvent && !matchesSchoolClass) return;
            if (this.tasks.some(t => Number(t.id) === Number(detail.taskId))) return;
            this.tasks = [...this.tasks, {
                id: detail.taskId,
                title: detail.title ?? '',
                statusLabel: detail.statusLabel ?? '',
                statusClass: detail.statusClass ?? this.defaultStatusClass,
                dueLabel: detail.dueLabel ?? null,
                priorityLabel: detail.priorityLabel ?? null,
                priorityClass: detail.priorityClass ?? 'bg-muted text-muted-foreground',
                durationLabel: detail.durationLabel ?? null,
            }];
        },
        onTaskParentSet(detail) {
            if (!detail || detail.taskId == null) return;
            const removedFromThisProject = this.parentProperty === 'projectId' && detail.previousProjectId != null && Number(detail.previousProjectId) === Number(this.parentId);
            const removedFromThisEvent = this.parentProperty === 'eventId' && detail.previousEventId != null && Number(detail.previousEventId) === Number(this.parentId);
            const removedFromThisSchoolClass = this.parentProperty === 'schoolClassId' && detail.previousSchoolClassId != null && Number(detail.previousSchoolClassId) === Number(this.parentId);
            if (removedFromThisProject || removedFromThisEvent || removedFromThisSchoolClass) {
                this.tasks = this.tasks.filter(t => Number(t.id) !== Number(detail.taskId));
            }
        },
        onSubtaskUnbound(detail) {
            if (!detail || detail.taskId == null) return;
            const unboundFromThisProject = this.parentProperty === 'projectId' && detail.unboundProjectId != null && Number(detail.unboundProjectId) === Number(this.parentId);
            const unboundFromThisEvent = this.parentProperty === 'eventId' && detail.unboundEventId != null && Number(detail.unboundEventId) === Number(this.parentId);
            const unboundFromThisSchoolClass = this.parentProperty === 'schoolClassId' && detail.unboundSchoolClassId != null && Number(detail.unboundSchoolClassId) === Number(this.parentId);
            if (unboundFromThisProject || unboundFromThisEvent || unboundFromThisSchoolClass) {
                this.tasks = this.tasks.filter(t => Number(t.id) !== Number(detail.taskId));
            }
        },
        removeTrashedTask(taskId) {
            if (taskId == null || !Number.isFinite(Number(taskId))) return;
            this.tasks = this.tasks.filter((t) => Number(t.id) !== Number(taskId));
        },
        onTaskStatusUpdated(detail) {
            if (!detail || detail.itemId == null) return;
            const id = Number(detail.itemId);
            this.tasks = this.tasks.map((t) =>
                Number(t.id) === id
                    ? { ...t, statusLabel: detail.statusLabel ?? '', statusClass: detail.statusClass ?? this.defaultStatusClass }
                    : t
            );
        },
        async focusTask(task) {
            if (!task || task.id == null) return;
            if (this.removingTaskIds?.has(task.id)) return;
            const instant = typeof window.workspaceCalendarTryInstantFocus === 'function'
                && window.workspaceCalendarTryInstantFocus('task', task.id);
            const shouldShowLoadingSkeleton = !instant;
            if (shouldShowLoadingSkeleton) {
                window.dispatchEvent(new CustomEvent('workspace-focus-navigation-loading-start', { bubbles: true }));
            }
            try {
                await $wire.$parent.$call('focusCalendarAgendaItem', 'task', task.id, !instant);
                if (!instant && typeof window.runWorkspaceFocusToTarget === 'function') {
                    requestAnimationFrame(() => {
                        setTimeout(() => window.runWorkspaceFocusToTarget('task', task.id), 0);
                    });
                }
            } finally {
                if (shouldShowLoadingSkeleton) {
                    window.dispatchEvent(new CustomEvent('workspace-focus-navigation-loading-end', { bubbles: true }));
                }
            }
        },
        async removeFromParent(task) {
            if (this.removingTaskIds.has(task.id)) return;
            this.removingTaskIds.add(task.id);
            // PHASE 1: Snapshot for rollback
            const tasksBackup = [...this.tasks];
            try {
                // PHASE 2: Optimistic update – remove from list and notify task card
                this.tasks = this.tasks.filter(t => t.id !== task.id);
                window.dispatchEvent(new CustomEvent('workspace-subtask-unbound', {
                    detail: {
                        taskId: task.id,
                        unboundProjectId: this.parentProperty === 'projectId' ? this.parentId : null,
                        unboundEventId: this.parentProperty === 'eventId' ? this.parentId : null,
                        unboundSchoolClassId: this.parentProperty === 'schoolClassId' ? this.parentId : null,
                    },
                    bubbles: true,
                }));
                // PHASE 3: Call server asynchronously
                const promise = $wire.$parent.$call('updateTaskProperty', task.id, this.parentProperty, null, true);
                // PHASE 4: Handle response
                await promise;
                $wire.$dispatch('toast', { type: 'success', message: this.removeSuccessToast });
            } catch (error) {
                // PHASE 5: Rollback on error
                this.tasks = tasksBackup;
                $wire.$dispatch('toast', { type: 'error', message: this.removeErrorToast });
            } finally {
                this.removingTaskIds.delete(task.id);
            }
        },
    }"
    @workspace-subtask-trashed.window="removeTrashedTask($event.detail.taskId)"
    @workspace-subtask-added.window="onSubtaskAdded($event.detail)"
    @workspace-task-parent-set.window="onTaskParentSet($event.detail)"
    @workspace-subtask-unbound.window="onSubtaskUnbound($event.detail)"
    @task-status-updated.window="onTaskStatusUpdated($event.detail)"
    x-show="tasks.length > 0"
>
    <button
        type="button"
        class="cursor-pointer inline-flex items-center gap-1.5 rounded-md border border-border/70 bg-background/60 px-2.5 py-1 font-medium text-foreground/80 transition-colors hover:bg-muted/40 hover:border-border dark:bg-muted/20 dark:hover:bg-muted/30"
        @click="toggle()"
        :aria-expanded="isOpen.toString()"
        aria-controls="{{ $subtasksPanelId }}"
    >
        <flux:icon name="clipboard-document-list" class="size-3 text-primary/80" />
        <span class="inline-flex items-baseline gap-1">
            <span class="text-[10px] font-semibold uppercase tracking-wide text-foreground/70">
                {{ __('Tasks') }}
            </span>
            <span class="text-[11px] tabular-nums text-foreground/60">
                (<span x-text="totalCount">{{ $totalTasks }}</span>)
            </span>
        </span>
        <span
            class="inline-flex items-center justify-center transition-transform duration-150 focus-hide-chevron text-foreground/50"
            :class="isOpen ? 'rotate-180' : ''"
        >
            <flux:icon name="chevron-down" class="size-3" />
        </span>
    </button>

    <div
        id="{{ $subtasksPanelId }}"
        x-show="isOpen"
        x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 translate-y-0.5"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-0.5"
        class="mt-1.5 rounded-md border border-border/50 bg-muted/20 py-1"
        role="region"
        :aria-hidden="(!isOpen).toString()"
    >
        <ul class="divide-y divide-border/30">
            <template x-for="task in tasks" :key="task.id">
                <li
                    class="flex items-center gap-2 rounded-sm border border-transparent px-2.5 py-1.5 first:pt-0 last:pb-0 transition-all duration-200 ease-out"
                    :class="removingTaskIds?.has(task.id)
                        ? 'cursor-not-allowed opacity-60'
                        : 'cursor-pointer hover:-translate-y-0.5 hover:scale-[1.01] hover:border-border/70 hover:bg-muted/70 focus-within:-translate-y-0.5 focus-within:scale-[1.01] focus-within:border-border/70 focus-within:bg-muted/70 dark:hover:border-zinc-700/90 dark:hover:bg-zinc-800/80 dark:focus-within:border-zinc-700/90 dark:focus-within:bg-zinc-800/80'"
                    role="button"
                    tabindex="0"
                    @click="focusTask(task)"
                    @keydown.enter.prevent="focusTask(task)"
                    @keydown.space.prevent="focusTask(task)"
                    :aria-disabled="(removingTaskIds?.has(task.id)).toString()"
                    :aria-label="`{{ __('Focus task') }}: ${task.title ?? ''}`"
                >
                    <span class="size-1.5 shrink-0 rounded-full bg-primary/50" aria-hidden="true"></span>
                    <div class="min-w-0 flex-1">
                        <span class="block truncate text-xs font-semibold text-foreground" :title="task.title" x-text="task.title"></span>
                        <div
                            x-show="task.statusLabel || task.dueLabel || task.priorityLabel || task.durationLabel"
                            x-cloak
                            class="mt-0.5 flex min-w-0 flex-wrap items-center gap-1 text-[10px] text-muted-foreground"
                        >
                            <span
                                x-show="task.statusLabel"
                                x-cloak
                                class="inline-flex items-center rounded-full border border-black/10 px-1.5 py-0.5 dark:border-white/10"
                                :class="task.statusClass"
                            >
                                <flux:icon name="check-circle" class="mr-1 size-2.5 opacity-70" />
                                <span class="font-medium">{{ __('Status') }}:</span>
                                <span class="ml-0.5" x-text="task.statusLabel"></span>
                            </span>
                            <span x-show="task.dueLabel" x-cloak class="inline-flex items-center rounded-full border border-border/60 bg-muted/45 px-1.5 py-0.5">
                                <flux:icon name="calendar" class="mr-1 size-2.5 opacity-70" />
                                <span class="font-medium">{{ __('Due') }}:</span>
                                <span class="ml-0.5" x-text="task.dueLabel"></span>
                            </span>
                            <span
                                x-show="task.priorityLabel"
                                x-cloak
                                class="inline-flex items-center rounded-full border border-black/10 px-1.5 py-0.5 dark:border-white/10"
                                :class="task.priorityClass"
                            >
                                <flux:icon name="flag" class="mr-1 size-2.5 opacity-70" />
                                <span class="font-medium">{{ __('Priority') }}:</span>
                                <span class="ml-0.5" x-text="task.priorityLabel"></span>
                            </span>
                            <span x-show="task.durationLabel" x-cloak class="inline-flex items-center rounded-full border border-border/60 bg-muted/45 px-1.5 py-0.5">
                                <flux:icon name="clock" class="mr-1 size-2.5 opacity-70" />
                                <span class="font-medium">{{ __('Duration') }}:</span>
                                <span class="ml-0.5 tabular-nums" x-text="task.durationLabel"></span>
                            </span>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="shrink-0 inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground transition-colors hover:bg-red-500/10 hover:text-red-600 disabled:opacity-50 dark:hover:text-red-400"
                        :disabled="removingTaskIds?.has(task.id)"
                        @click.stop
                        @click.throttle.250ms="removeFromParent(task)"
                        aria-label="{{ __('Remove from :parent', ['parent' => $kind === 'project' ? __('project') : ($kind === 'event' ? __('event') : __('class'))]) }}"
                    >
                        {{ __('Remove') }}
                    </button>
                </li>
            </template>
        </ul>
    </div>
</div>

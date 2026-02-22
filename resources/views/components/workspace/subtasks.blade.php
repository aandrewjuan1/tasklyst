@props([
    'item',
    'kind' => null,
])

@php
    use App\Enums\TaskStatus;

    $parentProperty = $kind === 'project' ? 'projectId' : 'eventId';
    $parentId = $item->id;

    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Task> $tasks */
    $tasks = $item->tasks ?? collect();
    $totalTasks = (int) ($item->tasks_count ?? $tasks->count());
    $kindLabel = match ($kind) {
        'project' => __('Project'),
        'event' => __('Event'),
        default => null,
    };
    $subtasksPanelId = 'subtasks-panel-'.($kind ?? 'item').'-'.$item->id;

    $statusClassMap = [
        TaskStatus::ToDo->value => 'bg-gray-800/10 text-gray-800 dark:bg-gray-300/20 dark:text-gray-300',
        TaskStatus::Doing->value => 'bg-blue-800/10 text-blue-800 dark:bg-blue-300/20 dark:text-blue-300',
        TaskStatus::Done->value => 'bg-green-800/10 text-green-800 dark:bg-green-300/20 dark:text-green-300',
    ];

    $tasksForAlpine = $tasks->map(function (\App\Models\Task $task) use ($statusClassMap): array {
        $statusValue = $task->status?->value ?? '';

        return [
            'id' => $task->id,
            'title' => $task->title,
            'statusLabel' => $task->status?->label() ?? '',
            'statusClass' => $statusClassMap[$statusValue] ?? 'bg-muted text-muted-foreground',
        ];
    })->values()->all();
@endphp

@if($totalTasks > 0)
<div
    wire:ignore
    class="mt-1.5 pt-1.5 text-[11px]"
    x-data="{
        isOpen: false,
        tasks: @js($tasksForAlpine),
        parentProperty: @js($parentProperty),
        parentId: @js($parentId),
        removeErrorToast: @js(__('Could not remove task from :parent. Try again.', ['parent' => $kind === 'project' ? __('project') : __('event')])),
        removeSuccessToast: @js(__('Task removed from :parent.', ['parent' => $kind === 'project' ? __('project') : __('event')])),
        removingTaskIds: new Set(),
        get totalCount() { return this.tasks.length; },
        toggle() {
            this.isOpen = !this.isOpen;
        },
        removeTrashedTask(taskId) {
            const id = Number(taskId);
            if (!Number.isFinite(id)) return;
            this.tasks = this.tasks.filter(t => t.id !== id);
        },
        async removeFromParent(task) {
            if (this.removingTaskIds?.has(task.id)) return;
            this.removingTaskIds = this.removingTaskIds || new Set();
            this.removingTaskIds.add(task.id);
            const snapshot = { ...task };
            const tasksBackup = [...this.tasks];
            try {
                this.tasks = this.tasks.filter(t => t.id !== task.id);
                window.dispatchEvent(new CustomEvent('workspace-subtask-unbound', {
                    detail: {
                        taskId: task.id,
                        unboundProjectId: this.parentProperty === 'projectId' ? this.parentId : null,
                        unboundEventId: this.parentProperty === 'eventId' ? this.parentId : null,
                    },
                    bubbles: true,
                }));
                const promise = $wire.$parent.$call('updateTaskProperty', task.id, this.parentProperty, null, true);
                await promise;
                $wire.$dispatch('toast', { type: 'success', message: this.removeSuccessToast });
            } catch (error) {
                this.tasks = tasksBackup;
                $wire.$dispatch('toast', { type: 'error', message: this.removeErrorToast });
            } finally {
                this.removingTaskIds.delete(task.id);
            }
        },
    }"
    @workspace-subtask-trashed.window="removeTrashedTask($event.detail.taskId)"
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
                <li class="flex items-center gap-2 px-2.5 py-1.5 first:pt-0 last:pb-0">
                    <span class="size-1.5 shrink-0 rounded-full bg-primary/50" aria-hidden="true"></span>
                    <span class="min-w-0 flex-1 truncate text-[11px] text-foreground/90" :title="task.title" x-text="task.title"></span>
                    <span
                        x-show="task.statusLabel"
                        x-cloak
                        class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium"
                        :class="task.statusClass"
                        x-text="task.statusLabel"
                    ></span>
                    <button
                        type="button"
                        class="shrink-0 inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground transition-colors hover:bg-red-500/10 hover:text-red-600 disabled:opacity-50 dark:hover:text-red-400"
                        :disabled="removingTaskIds?.has(task.id)"
                        @click.throttle.250ms="removeFromParent(task)"
                        aria-label="{{ __('Remove from :parent', ['parent' => $kind === 'project' ? __('project') : __('event')]) }}"
                    >
                        {{ __('Remove') }}
                    </button>
                </li>
            </template>
        </ul>
    </div>
</div>
@endif

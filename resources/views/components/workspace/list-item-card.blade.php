@props([
    'kind',
    'item',
    'listFilterDate' => null,
    'filters' => [],
    'availableTags' => [],
    'teachers' => [],
    'isOverdue' => false,
    'activeFocusSession' => null,
    'defaultWorkDurationMinutes' => 25,
    'pomodoroSettings' => null,
    'layout' => 'list',
])

@php
    $vm = new \App\ViewModels\ListItemCardViewModel(
        kind: $kind,
        item: $item,
        listFilterDate: $listFilterDate,
        filters: $filters ?? [],
        availableTags: $availableTags ?? [],
        isOverdue: $isOverdue ?? false,
        activeFocusSession: $activeFocusSession,
        defaultWorkDurationMinutes: $defaultWorkDurationMinutes ?? 25,
        pomodoroSettings: $pomodoroSettings,
    );
    extract($vm->viewData());

    $listItemCardStructural = 'list-item-card flex flex-col gap-2 rounded-xl px-3 py-2 transition-[opacity,transform] duration-200 ease-out';

    $taskStatusValue = $kind === 'task' ? ($effectiveStatus?->value ?? $item->status?->value ?? 'to_do') : null;
    $taskSurfaceClass = match ($taskStatusValue) {
        'doing' => 'lic-surface-task-doing',
        'done' => 'lic-surface-task-done',
        default => 'lic-surface-task-todo',
    };

    $alpineConfig = array_merge($vm->alpineConfig(), [
        'layout' => $layout ?? 'list',
    ]);

    $initialHideCard = (bool) ($alpineConfig['hideCard'] ?? false);
    $isKanbanLayout = ($layout ?? 'list') === 'kanban';

    $listItemCardRootClass = $listItemCardStructural;
    if ($kind === 'task') {
        $taskCardSurfaceClass = $isKanbanLayout ? 'lic-surface-zinc' : $taskSurfaceClass;
        $listItemCardRootClass .= ' '.$taskCardSurfaceClass.($isKanbanLayout ? '' : ' scroll-mt-28');
    } elseif ($kind === 'event' && ! $isKanbanLayout) {
        $listItemCardRootClass .= ' scroll-mt-28 lic-surface-event';
    } elseif ($kind === 'project' && ! $isKanbanLayout) {
        $listItemCardRootClass .= ' scroll-mt-28 lic-surface-project';
    } elseif ($kind === 'schoolclass' && ! $isKanbanLayout) {
        $listItemCardRootClass .= ' scroll-mt-28 lic-surface-school-class';
    } else {
        $listItemCardRootClass .= ($isKanbanLayout ? '' : ' scroll-mt-28').' lic-surface-zinc';
    }

    $hasActiveFocusOnThisTask = $kind === 'task'
        && $activeFocusSession
        && (string) ($activeFocusSession['task_id'] ?? '') === (string) $item->id;
    $hasActiveBreakSession = $activeFocusSession
        && ($activeFocusSession['type'] ?? null) !== 'work'
        && ($activeFocusSession['type'] ?? null) !== null;
@endphp

<div
    {{ $attributes->merge([
        'id' => 'workspace-item-'.$kind.'-'.$item->id,
        'class' => $listItemCardRootClass,
    ]) }}
    wire:ignore
    x-data="listItemCard({{ \Illuminate\Support\Js::from($alpineConfig) }})"
    x-init="alpineReady = true"
    :style="hideCard ? 'display: none;' : ''"
    @if($initialHideCard) style="display: none;" @endif
    x-cleanup="destroy()"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100 scale-100"
    x-transition:leave-end="opacity-0 scale-[0.98]"
    @dropdown-opened="dropdownOpenCount++"
    @dropdown-closed="dropdownOpenCount--"
    @recurring-selection-updated="onRecurringSelectionUpdated($event.detail)"
    @recurring-revert="onRecurringRevert($event.detail)"
    @item-property-updated="onItemPropertyUpdated($event.detail)"
    @item-update-rollback="onItemUpdateRollback()"
    @collaboration-self-left="hideFromList()"
    @workspace-item-property-updated.window="if ($event.detail?.kind === '{{ $kind }}' && String($event.detail.itemId) === String(itemId)) onItemPropertyUpdated($event.detail)"
    @workspace-item-visibility-updated.window="if ($event.detail?.kind === '{{ $kind }}' && String($event.detail.itemId) === String(itemId) && $event.detail.visible === false) hideFromList()"
    @focus-session-updated.window="onFocusSessionUpdated($event.detail?.session ?? $event.detail?.[0] ?? null)"
    @task-duration-updated="onTaskDurationUpdated($event.detail)"
    @if($kind === 'task')
    x-effect="(() => { const m = { to_do: 'lic-surface-task-todo', doing: 'lic-surface-task-doing', done: 'lic-surface-task-done' }; const taskSurfaces = Object.values(m); if (layout === 'kanban') { taskSurfaces.forEach((c) => $el.classList.remove(c)); if (!$el.classList.contains('lic-surface-zinc')) { $el.classList.add('lic-surface-zinc'); } return; } $el.classList.remove('lic-surface-zinc'); const k = taskStatus === 'doing' || taskStatus === 'done' ? taskStatus : 'to_do'; const d = m[k] || m.to_do; taskSurfaces.forEach((c) => { if (c !== d) { $el.classList.remove(c); } }); if (!$el.classList.contains(d)) { $el.classList.add(d); } })()"
    @endif
    :class="{ 'relative z-50': dropdownOpenCount > 0, 'pointer-events-none opacity-60': deletingInProgress, 'is-focus-locked': isCardLockedForFocus }"
>
    {{-- Focus modal: teleported to body; x-if mounts only while open so at most one modal exists in the document. Comments stay on the in-list card only. --}}
    @if($kind === 'task')
    <template x-if="isFocusModalOpen">
        <template x-teleport="body">
            <div
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="focus-modal-shell fixed inset-0 z-100"
                role="dialog"
                aria-modal="true"
                aria-labelledby="focus-modal-title"
            >
            {{-- Backdrop: blocks interaction with page; does not close on click --}}
            <div
                class="focus-modal-backdrop absolute inset-0"
                aria-hidden="true"
            ></div>
            {{-- Modal panel: focus bar + header + task body (comments remain on the list card below the overlay) --}}
            <div
                x-ref="focusModalPanel"
                class="focus-modal-panel relative z-10 flex max-h-[90vh] w-full flex-col overflow-hidden"
                @click.stop
                @keydown.tab="trapFocusInModal($event)"
            >
                @include('components.workspace.list-item-card.focus-bar', [
                    'itemId' => $item->id,
                    'focusModeTypes' => $alpineConfig['focusModeTypes'] ?? [],
                    'pomodoroWorkMin' => $alpineConfig['pomodoroWorkMin'] ?? config('pomodoro.min_duration_minutes', 1),
                    'pomodoroWorkMax' => $alpineConfig['pomodoroWorkMax'] ?? config('pomodoro.max_work_duration_minutes', 120),
                    'pomodoroShortBreakMax' => $alpineConfig['pomodoroShortBreakMax'] ?? 60,
                    'pomodoroLongBreakMax' => $alpineConfig['pomodoroLongBreakMax'] ?? 60,
                    'pomodoroLongBreakAfterMin' => $alpineConfig['pomodoroLongBreakAfterMin'] ?? 2,
                    'pomodoroLongBreakAfterMax' => $alpineConfig['pomodoroLongBreakAfterMax'] ?? 10,
                    'hasActiveFocusOnThisTask' => $hasActiveFocusOnThisTask ?? false,
                    'hasActiveBreakSession' => $hasActiveBreakSession ?? false,
                ])
                {{-- Card body in modal: read-only when focus is active; chevrons hidden via .is-focus-locked --}}
                <div
                    class="focus-modal-content is-focus-locked flex flex-1 flex-col gap-2 overflow-y-auto px-3 pb-3 pt-0 sm:px-4 sm:pb-4"
                    :class="{ 'pointer-events-none select-none': isCardLockedForFocus }"
                >
                    @include('components.workspace.list-item-card.header', [
                        'layout' => 'list',
                        'embedInFocusModal' => true,
                    ])
                    <div class="flex flex-wrap items-center gap-2 pt-0.5 text-xs">
                        @if($kind === 'project')
                            <x-workspace.list-item-project
                                :item="$item"
                                :update-property-method="$updatePropertyMethod"
                                :readonly="!$canEditDates"
                            />
                        @elseif($kind === 'event')
                            <x-workspace.list-item-event
                                :item="$item"
                                :available-tags="$availableTags"
                                :update-property-method="$updatePropertyMethod"
                                :list-filter-date="$listFilterDate"
                                :initial-status="$eventEffectiveStatus?->value ?? $item->status?->value"
                            />
                        @elseif($kind === 'task')
                            <x-workspace.list-item-task
                                :item="$item"
                                :available-tags="$availableTags"
                                :update-property-method="$updatePropertyMethod"
                                :list-filter-date="$listFilterDate"
                                :initial-status="$effectiveStatus?->value ?? $item->status?->value"
                                layout="list"
                                :embed-in-focus-modal="true"
                                :show-focus-trigger="false"
                            />
                        @endif
                    </div>
                </div>
            </div>
        </div>
        </template>
    </template>
    @endif

    {{-- In-list card: lock when focus modal/session active — overlay blocks interaction; chevrons hidden via .is-focus-locked --}}
    <div class="relative">
        {{-- Overlay: blocks all interaction when focus modal or session is active --}}
        <div
            x-show="isCardLockedForFocus"
            x-cloak
            class="absolute inset-0 z-10 cursor-default"
            style="pointer-events: auto; user-select: none; -webkit-user-select: none;"
            aria-hidden="true"
        ></div>
        @include('components.workspace.list-item-card.header', [
            'layout' => $layout,
            'embedInFocusModal' => false,
        ])

        <div @class([
            'min-w-0',
            'border-t border-border/50 pt-2' => $isKanbanLayout,
            'border-t border-border/40 pt-2' => ! $isKanbanLayout,
        ])>
        <div class="flex flex-wrap items-center gap-2 text-xs">
            @if($kind === 'project')
                <x-workspace.list-item-project
                    :item="$item"
                    :update-property-method="$updatePropertyMethod"
                    :readonly="!$canEditDates"
                />
            @elseif($kind === 'event')
                <x-workspace.list-item-event
                    :item="$item"
                    :available-tags="$availableTags"
                    :update-property-method="$updatePropertyMethod"
                    :list-filter-date="$listFilterDate"
                    :initial-status="$eventEffectiveStatus?->value ?? $item->status?->value"
                />
            @elseif($kind === 'task')
                <x-workspace.list-item-task
                    :item="$item"
                    :available-tags="$availableTags"
                    :update-property-method="$updatePropertyMethod"
                    :list-filter-date="$listFilterDate"
                    :initial-status="$effectiveStatus?->value ?? $item->status?->value"
                    :layout="$layout"
                />
            @elseif($kind === 'schoolclass')
                <x-workspace.list-item-school-class
                    :school-class="$item"
                    :teachers="$teachers"
                    :update-property-method="$updatePropertyMethod"
                />
            @endif
        </div>
        </div>

        @if(in_array($kind, ['project', 'event', 'schoolclass'], true))
            <x-workspace.subtasks :item="$item" :kind="$kind" />
        @endif

        @if(in_array($kind, ['task', 'project', 'event'], true))
            <x-workspace.comments :item="$item" :kind="$kind" :layout="$layout" :readonly="!$canEdit" />
        @endif
    </div>
</div>
@props([
    'kind',
    'item',
    'listFilterDate' => null,
    'filters' => [],
    'availableTags' => [],
    'isOverdue' => false,
    'activeFocusSession' => null,
    'defaultWorkDurationMinutes' => 25,
    'pomodoroSettings' => null,
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
    $alpineConfig = $vm->alpineConfig();
    $hasActiveFocusOnThisTask = $kind === 'task'
        && $activeFocusSession
        && (string) ($activeFocusSession['task_id'] ?? '') === (string) $item->id;
    $hasActiveBreakSession = $activeFocusSession
        && ($activeFocusSession['type'] ?? null) !== 'work'
        && ($activeFocusSession['type'] ?? null) !== null;
@endphp

<div
    {{ $attributes->merge([
        'class' => 'list-item-card flex flex-col gap-2 rounded-xl border border-zinc-200 bg-white/95 px-3 py-2 shadow-sm backdrop-blur transition-[opacity,box-shadow,transform,border-color,background-color] duration-200 ease-out',
    ]) }}
    wire:ignore
    x-data="listItemCard({{ \Illuminate\Support\Js::from($alpineConfig) }})"
    x-init="alpineReady = true"
    x-show="!hideCard"
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
    @focus-session-updated.window="onFocusSessionUpdated($event.detail?.session ?? $event.detail?.[0] ?? null)"
    @task-duration-updated="onTaskDurationUpdated($event.detail)"
    :class="{
        'relative z-50': dropdownOpenCount > 0,
        'pointer-events-none opacity-60': deletingInProgress,
        'is-focus-locked': isCardLockedForFocus,
    }"
>
    {{-- Focus modal: teleported to body so it overlays the viewport; state lives on the card. Only tasks can open focus — skip modal DOM for events/projects to reduce payload and Alpine scope. --}}
    @if($kind === 'task')
    <template x-teleport="body">
        <div
            x-show="isFocusModalOpen"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-[100] flex items-center justify-center p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="focus-modal-title"
        >
            {{-- Backdrop: blocks interaction with page; does not close on click --}}
            <div
                class="absolute inset-0 bg-black/50"
                aria-hidden="true"
            ></div>
            {{-- Modal panel: full card (focus bar + header + body + comments), wide so content is not compact --}}
            <div
                x-ref="focusModalPanel"
                class="relative z-10 flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white/95 shadow-xl backdrop-blur dark:border-zinc-700 dark:bg-zinc-900"
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
                    class="is-focus-locked flex flex-1 flex-col gap-2 overflow-y-auto px-3 pb-3 pt-0"
                    :class="{ 'pointer-events-none select-none': isCardLockedForFocus }"
                >
                    @include('components.workspace.list-item-card.header')
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
                                :is-overdue="$isOverdue"
                            />
                        @elseif($kind === 'task')
                            <x-workspace.list-item-task
                                :item="$item"
                                :available-tags="$availableTags"
                                :update-property-method="$updatePropertyMethod"
                                :list-filter-date="$listFilterDate"
                                :initial-status="$effectiveStatus?->value ?? $item->status?->value"
                                :is-overdue="$isOverdue"
                            />
                        @endif
                    </div>
                    <x-workspace.comments :item="$item" :kind="$kind" :readonly="!$canEdit" />
                </div>
            </div>
        </div>
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
        @include('components.workspace.list-item-card.header')

        <div class="flex flex-wrap items-center gap-2 pt-0.5 text-xs">
            @if($kind === 'task' && $canEdit)
                <div class="shrink-0 {{ $hasActiveFocusOnThisTask ? 'hidden' : '' }}">
                    <flux:tooltip :content="__('Start focus mode')">
                        <button
                            type="button"
                            x-ref="focusTrigger"
                            @click.stop="setTimeout(() => enterFocusReady(), 120)"
                            class="inline-flex items-center gap-1.5 rounded-full border border-primary/50 bg-primary/10 px-2.5 py-0.5 font-semibold text-primary transition-[box-shadow,transform] duration-150 ease-out hover:bg-primary/15 hover:border-primary/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <flux:icon name="bolt" class="size-3 shrink-0" />
                            <span>{{ __('Focus') }}</span>
                        </button>
                    </flux:tooltip>
                </div>
            @endif

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
                    :is-overdue="$isOverdue"
                />
            @elseif($kind === 'task')
                <x-workspace.list-item-task
                    :item="$item"
                    :available-tags="$availableTags"
                    :update-property-method="$updatePropertyMethod"
                    :list-filter-date="$listFilterDate"
                    :initial-status="$effectiveStatus?->value ?? $item->status?->value"
                    :is-overdue="$isOverdue"
                />
            @endif
        </div>

        <x-workspace.comments :item="$item" :kind="$kind" :readonly="!$canEdit" />
    </div>
</div>
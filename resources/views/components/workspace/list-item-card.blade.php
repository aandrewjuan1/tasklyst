@props([
    'kind',
    'item',
    'listFilterDate' => null,
    'filters' => [],
    'availableTags' => [],
    'isOverdue' => false,
    'activeFocusSession' => null,
    'defaultWorkDurationMinutes' => 25,
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
    );
    extract($vm->viewData());
    $hasActiveFocusOnThisTask = $kind === 'task'
        && $activeFocusSession
        && (string) ($activeFocusSession['task_id'] ?? '') === (string) $item->id;
@endphp

<div
    {{ $attributes->merge([
        'class' => 'flex flex-col gap-2 rounded-xl border border-zinc-200 bg-white/95 px-3 py-2 shadow-sm backdrop-blur transition-[opacity,box-shadow,border-color,background-color] duration-200 ease-out dark:border-zinc-700 dark:bg-zinc-900/95',
    ]) }}
    wire:ignore
    x-data="listItemCard({{ \Illuminate\Support\Js::from($vm->alpineConfig()) }})"
    x-init="alpineReady = true"
    x-show="!hideCard"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100 scale-100"
    x-transition:leave-end="opacity-0 scale-[0.98]"
    @dropdown-opened="dropdownOpenCount++"
    @dropdown-closed="dropdownOpenCount--"
    @recurring-selection-updated="onRecurringSelectionUpdated($event.detail)"
    @item-property-updated="onItemPropertyUpdated($event.detail)"
    @item-update-rollback="onItemUpdateRollback()"
    @collaboration-self-left="hideFromList()"
    @focus-session-updated.window="onFocusSessionUpdated($event.detail?.session ?? $event.detail?.[0] ?? null)"
    @task-duration-updated="onTaskDurationUpdated($event.detail)"
    x-effect="isFocused && activeFocusSession ? startFocusTicker() : (stopFocusTicker(), sessionComplete = false)"
    :class="{
        'relative z-50': dropdownOpenCount > 0 || isFocused || focusReady,
        'pointer-events-none opacity-60': deletingInProgress,
        'pointer-events-auto': isFocused || focusReady,
        'ring-2 ring-primary/40 border-primary/30 bg-primary/[0.06] shadow-md dark:border-primary/40 dark:bg-primary/10': isFocused || focusReady,
        'pointer-events-none select-none opacity-60': isDimmedByFocus,
    }"
>
    @include('components.workspace.list-item-card.focus-bar', [
        'focusModeTypes' => $vm->alpineConfig()['focusModeTypes'] ?? [],
    ])

    @include('components.workspace.list-item-card.header')

    <div
        class="flex flex-wrap items-center gap-2 pt-0.5 text-xs"
        :class="{ 'pointer-events-none': kind === 'task' && isFocused }"
    >
    @if($kind === 'task' && $canEdit)
        <div
            x-show="!isFocused && !focusReady"
            class="shrink-0 {{ $hasActiveFocusOnThisTask ? 'hidden' : '' }}"
        >
            <flux:tooltip :content="__('Start focus mode')">
                <button
                    type="button"
                    @click.stop="setTimeout(() => enterFocusReady(), 120)"
                    class="inline-flex items-center gap-1.5 rounded-full border border-primary/50 bg-primary/10 px-2.5 py-0.5 font-semibold text-primary transition-[box-shadow,transform] duration-150 ease-out hover:bg-primary/15 hover:border-primary/60 dark:border-primary/40 dark:bg-primary/20 dark:hover:bg-primary/25 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <flux:icon name="bolt" class="size-3 shrink-0" />
                    <span>{{ __('Focus') }}</span>
                </button>
            </flux:tooltip>
        </div>
    @endif
    @if($kind === 'project')
        <x-workspace.list-item-project :item="$item" :update-property-method="$updatePropertyMethod" :readonly="!$canEditDates" />

        <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-amber-500/10 px-2.5 py-0.5 font-medium text-amber-500 dark:border-white/10">
            <flux:icon name="list-bullet" class="size-3" />
            <span class="inline-flex items-baseline gap-1">
                <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                    {{ __('Tasks') }}:
                </span>
                <span>
                    {{ $item->tasks_count }}
                </span>
            </span>
        </span>
    </div>
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
        />
    @endif

    <x-workspace.comments :item="$item" :kind="$kind" :readonly="!$canEdit" />
    </div>
</div>

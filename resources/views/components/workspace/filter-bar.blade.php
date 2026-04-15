@props([
    'filters' => [],
    'tags' => [],
    'showListScopedFilters' => true,
])

@php
    use App\Enums\EventStatus;
    use App\Enums\TaskComplexity;
    use App\Enums\TaskPriority;
    use App\Enums\TaskSourceType;
    use App\Enums\TaskStatus;

    $taskStatuses = collect(TaskStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    $taskPriorities = collect(TaskPriority::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    $taskComplexities = collect(TaskComplexity::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    $eventStatuses = collect(EventStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    $hasActiveFilters = $filters['hasActiveFilters'] ?? false;

    $tags = $tags instanceof \Illuminate\Support\Collection ? $tags : collect($tags);

    $taskStatusOptions = ['' => __('All')] + $taskStatuses;
    $taskPriorityOptions = ['' => __('All')] + $taskPriorities;
    $taskComplexityOptions = ['' => __('All')] + $taskComplexities;
    $eventStatusOptions = ['' => __('All')] + $eventStatuses;
    $recurringOptions = ['' => __('All'), 'recurring' => __('Recurring'), 'oneTime' => __('One-time')];
    $taskSourceOptions = [
        '' => __('All'),
        'brightspace' => TaskSourceType::Brightspace->label(),
        'manual' => TaskSourceType::Manual->label(),
    ];
@endphp

<div
    x-data="{
        hasActiveFilters: @js($hasActiveFilters),
        showListScopedFilters: @js($showListScopedFilters),
        menuOpen: false,
        activeSubmenu: null,
        prefersFineHover: false,
        _submenuLeaveTimer: null,
        _filterOptimisticCleanup: null,
        init() {
            this.prefersFineHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
            const syncHasActiveFromWire = () => {
                const listScoped = this.showListScopedFilters &&
                    ($wire.filterItemType || $wire.filterEventStatus);
                this.hasActiveFilters = !!(listScoped ||
                    $wire.filterTaskStatus || $wire.filterTaskPriority ||
                    $wire.filterTaskComplexity ||
                    $wire.filterTaskSource ||
                    ($wire.filterTagIds?.length > 0) || $wire.filterRecurring);
            };
            const onFilterOptimistic = (e) => {
                if (e?.detail?.key === 'clearAll') {
                    this.hasActiveFilters = false;
                    return;
                }
                const { key, value } = e.detail || {};
                if (key === 'tagIds' && Array.isArray(value) && value.length > 0) {
                    this.hasActiveFilters = true;
                    return;
                }
                if (value !== null && value !== undefined && value !== '') {
                    this.hasActiveFilters = true;
                    return;
                }
                this.$nextTick(() => syncHasActiveFromWire());
            };
            const onWireFilterChanged = () => {
                this.$nextTick(() => syncHasActiveFromWire());
            };
            window.addEventListener('filter-optimistic', onFilterOptimistic);
            this.$watch('$wire.filterItemType', onWireFilterChanged);
            this.$watch('$wire.filterTaskStatus', onWireFilterChanged);
            this.$watch('$wire.filterTaskPriority', onWireFilterChanged);
            this.$watch('$wire.filterTaskComplexity', onWireFilterChanged);
            this.$watch('$wire.filterEventStatus', onWireFilterChanged);
            this.$watch('$wire.filterTagIds', onWireFilterChanged);
            this.$watch('$wire.filterRecurring', onWireFilterChanged);
            this.$watch('$wire.filterTaskSource', onWireFilterChanged);
            this._filterOptimisticCleanup = () => window.removeEventListener('filter-optimistic', onFilterOptimistic);
        },
        destroy() {
            clearTimeout(this._submenuLeaveTimer);
            this._filterOptimisticCleanup?.();
        },
        cancelCloseSubmenu() {
            clearTimeout(this._submenuLeaveTimer);
            this._submenuLeaveTimer = null;
        },
        scheduleCloseSubmenu() {
            this.cancelCloseSubmenu();
            this._submenuLeaveTimer = setTimeout(() => {
                this.activeSubmenu = null;
                this._submenuLeaveTimer = null;
            }, 75);
        },
        openSubmenuFine(key) {
            if (!this.prefersFineHover) {
                return;
            }
            this.cancelCloseSubmenu();
            this.activeSubmenu = key;
        },
        toggleSubmenuKey(key) {
            this.activeSubmenu = this.activeSubmenu === key ? null : key;
        },
        handleCategoryActivate(key, $event) {
            if (this.prefersFineHover && $event.pointerType === 'mouse') {
                return;
            }
            this.toggleSubmenuKey(key);
        },
        toggleMenu() {
            this.menuOpen = !this.menuOpen;
        },
        closeFlyoutOnly() {
            this.cancelCloseSubmenu();
            this.activeSubmenu = null;
        },
        closeMenu() {
            this.cancelCloseSubmenu();
            this.activeSubmenu = null;
            this.menuOpen = false;
        },
        onEscapeMenu() {
            if (this.activeSubmenu) {
                this.cancelCloseSubmenu();
                this.activeSubmenu = null;
            } else if (this.menuOpen) {
                this.closeMenu();
            }
        },
    }"
    class="flex flex-wrap items-center justify-end gap-2"
    @keydown.escape.window="onEscapeMenu()"
>
    <div class="relative shrink-0" @click.outside="closeMenu()">
        <button
            type="button"
            class="workspace-filter-trigger workspace-filter-trigger--primary"
            @click.stop="toggleMenu()"
            :aria-expanded="menuOpen"
            aria-haspopup="menu"
            aria-controls="workspace-filter-menu"
            id="workspace-filter-trigger"
            :class="menuOpen ? 'ring-2 ring-white/35 ring-offset-2 ring-offset-brand-blue dark:ring-brand-light-blue/40 dark:ring-offset-zinc-900' : ''"
        >
            <svg class="size-5 shrink-0 opacity-90" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 01-.659 1.591l-5.432 5.432a2.25 2.25 0 00-.659 1.591v2.927a2.25 2.25 0 01-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 00-.659-1.591L3.659 7.409A2.25 2.25 0 013 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0112 3z" />
            </svg>
            <span>{{ __('Add filters') }}</span>
            <svg class="size-4 shrink-0 opacity-80" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
            </svg>
        </button>

        <div
            x-cloak
            x-show="menuOpen"
            id="workspace-filter-menu"
            role="menu"
            aria-labelledby="workspace-filter-trigger"
            class="workspace-filter-panel workspace-filter-panel--end workspace-filter-panel--root-rail"
        >
            {{-- Task status --}}
            <div
                class="workspace-filter-category-wrap"
                @mouseenter="openSubmenuFine('taskStatus')"
                @mouseleave="prefersFineHover && scheduleCloseSubmenu()"
            >
                <button
                    type="button"
                    class="workspace-filter-category-row workspace-filter-category-row--task-status"
                    :class="activeSubmenu === 'taskStatus' ? 'workspace-filter-category-row--active' : ''"
                    @click="handleCategoryActivate('taskStatus', $event)"
                    :aria-expanded="activeSubmenu === 'taskStatus'"
                    aria-controls="wff-flyout-task-status"
                    id="wff-row-task-status"
                >
                    <span class="workspace-filter-category-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </span>
                    <span class="min-w-0 flex-1 truncate">{{ __('Task status') }}</span>
                    <svg class="size-4 shrink-0 text-zinc-400 dark:text-zinc-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                    </svg>
                </button>
                <div
                    x-cloak
                    x-show="activeSubmenu === 'taskStatus'"
                    id="wff-flyout-task-status"
                    role="group"
                    aria-labelledby="wff-row-task-status"
                    class="workspace-filter-flyout"
                >
                    @foreach ($taskStatusOptions as $value => $label)
                        <label wire:key="fb-ts-{{ $value === '' ? 'all' : $value }}" class="workspace-filter-option" @click="closeFlyoutOnly()">
                            <input
                                type="radio"
                                class="sr-only"
                                wire:model.live="filterTaskStatus"
                                value="{{ $value }}"
                                @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'taskStatus', value: @js($value === '' ? null : $value) } }))"
                            />
                            <span class="min-w-0 flex-1">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- Task priority --}}
            <div
                class="workspace-filter-category-wrap"
                @mouseenter="openSubmenuFine('taskPriority')"
                @mouseleave="prefersFineHover && scheduleCloseSubmenu()"
            >
                <button
                    type="button"
                    class="workspace-filter-category-row workspace-filter-category-row--task-priority"
                    :class="activeSubmenu === 'taskPriority' ? 'workspace-filter-category-row--active' : ''"
                    @click="handleCategoryActivate('taskPriority', $event)"
                    :aria-expanded="activeSubmenu === 'taskPriority'"
                    aria-controls="wff-flyout-task-priority"
                    id="wff-row-task-priority"
                >
                    <span class="workspace-filter-category-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                        </svg>
                    </span>
                    <span class="min-w-0 flex-1 truncate">{{ __('Task priority') }}</span>
                    <svg class="size-4 shrink-0 text-zinc-400 dark:text-zinc-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                    </svg>
                </button>
                <div
                    x-cloak
                    x-show="activeSubmenu === 'taskPriority'"
                    id="wff-flyout-task-priority"
                    role="group"
                    aria-labelledby="wff-row-task-priority"
                    class="workspace-filter-flyout"
                >
                    @foreach ($taskPriorityOptions as $value => $label)
                        <label wire:key="fb-tp-{{ $value === '' ? 'all' : $value }}" class="workspace-filter-option" @click="closeFlyoutOnly()">
                            <input
                                type="radio"
                                class="sr-only"
                                wire:model.live="filterTaskPriority"
                                value="{{ $value }}"
                                @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'taskPriority', value: @js($value === '' ? null : $value) } }))"
                            />
                            <span class="min-w-0 flex-1">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- Task complexity --}}
            <div
                class="workspace-filter-category-wrap"
                @mouseenter="openSubmenuFine('taskComplexity')"
                @mouseleave="prefersFineHover && scheduleCloseSubmenu()"
            >
                <button
                    type="button"
                    class="workspace-filter-category-row workspace-filter-category-row--task-complexity"
                    :class="activeSubmenu === 'taskComplexity' ? 'workspace-filter-category-row--active' : ''"
                    @click="handleCategoryActivate('taskComplexity', $event)"
                    :aria-expanded="activeSubmenu === 'taskComplexity'"
                    aria-controls="wff-flyout-task-complexity"
                    id="wff-row-task-complexity"
                >
                    <span class="workspace-filter-category-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
                        </svg>
                    </span>
                    <span class="min-w-0 flex-1 truncate">{{ __('Task complexity') }}</span>
                    <svg class="size-4 shrink-0 text-zinc-400 dark:text-zinc-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                    </svg>
                </button>
                <div
                    x-cloak
                    x-show="activeSubmenu === 'taskComplexity'"
                    id="wff-flyout-task-complexity"
                    role="group"
                    aria-labelledby="wff-row-task-complexity"
                    class="workspace-filter-flyout"
                >
                    @foreach ($taskComplexityOptions as $value => $label)
                        <label wire:key="fb-tc-{{ $value === '' ? 'all' : $value }}" class="workspace-filter-option" @click="closeFlyoutOnly()">
                            <input
                                type="radio"
                                class="sr-only"
                                wire:model.live="filterTaskComplexity"
                                value="{{ $value }}"
                                @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'taskComplexity', value: @js($value === '' ? null : $value) } }))"
                            />
                            <span class="min-w-0 flex-1">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="mx-1 my-0.5 h-px bg-zinc-200/80 dark:bg-zinc-700/60" aria-hidden="true"></div>

            @if ($showListScopedFilters)
                {{-- Event status --}}
                <div
                    class="workspace-filter-category-wrap"
                    @mouseenter="openSubmenuFine('eventStatus')"
                    @mouseleave="prefersFineHover && scheduleCloseSubmenu()"
                >
                    <button
                        type="button"
                        class="workspace-filter-category-row workspace-filter-category-row--event-status"
                        :class="activeSubmenu === 'eventStatus' ? 'workspace-filter-category-row--active' : ''"
                        @click="handleCategoryActivate('eventStatus', $event)"
                        :aria-expanded="activeSubmenu === 'eventStatus'"
                        aria-controls="wff-flyout-event-status"
                        id="wff-row-event-status"
                    >
                        <span class="workspace-filter-category-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5a2.25 2.25 0 002.25-2.25m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5a2.25 2.25 0 012.25 2.25v7.5" />
                            </svg>
                        </span>
                        <span class="min-w-0 flex-1 truncate">{{ __('Event status') }}</span>
                        <svg class="size-4 shrink-0 text-zinc-400 dark:text-zinc-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                    </button>
                    <div
                        x-cloak
                        x-show="activeSubmenu === 'eventStatus'"
                        id="wff-flyout-event-status"
                        role="group"
                        aria-labelledby="wff-row-event-status"
                        class="workspace-filter-flyout"
                    >
                        @foreach ($eventStatusOptions as $value => $label)
                            <label wire:key="fb-es-{{ $value === '' ? 'all' : $value }}" class="workspace-filter-option" @click="closeFlyoutOnly()">
                                <input
                                    type="radio"
                                    class="sr-only"
                                    wire:model.live="filterEventStatus"
                                    value="{{ $value }}"
                                    @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'eventStatus', value: @js($value === '' ? null : $value) } }))"
                                />
                                <span class="min-w-0 flex-1">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($tags->isNotEmpty())
                <div
                    class="workspace-filter-category-wrap"
                    @mouseenter="openSubmenuFine('tags')"
                    @mouseleave="prefersFineHover && scheduleCloseSubmenu()"
                >
                    <button
                        type="button"
                        class="workspace-filter-category-row workspace-filter-category-row--tags"
                        :class="activeSubmenu === 'tags' ? 'workspace-filter-category-row--active' : ''"
                        @click="handleCategoryActivate('tags', $event)"
                        :aria-expanded="activeSubmenu === 'tags'"
                        aria-controls="wff-flyout-tags"
                        id="wff-row-tags"
                    >
                        <span class="workspace-filter-category-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3zM6.75 7.5a.75.75 0 100-1.5.75.75 0 000 1.5z" />
                            </svg>
                        </span>
                        <span class="min-w-0 flex-1 truncate">{{ __('Tags') }}</span>
                        <svg class="size-4 shrink-0 text-zinc-400 dark:text-zinc-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                    </button>
                    <div
                        x-cloak
                        x-show="activeSubmenu === 'tags'"
                        id="wff-flyout-tags"
                        role="group"
                        aria-labelledby="wff-row-tags"
                        class="workspace-filter-flyout"
                    >
                        <label wire:key="fb-tag-all" class="workspace-filter-option" @click="closeFlyoutOnly()">
                            <input
                                type="radio"
                                class="sr-only"
                                wire:model.live="filterTagId"
                                value=""
                                @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'tagIds', value: [] } }))"
                            />
                            <span class="min-w-0 flex-1">{{ __('All') }}</span>
                        </label>
                        @foreach ($tags as $tag)
                            <label wire:key="fb-tag-{{ $tag->id }}" class="workspace-filter-option" @click="closeFlyoutOnly()">
                                <input
                                    type="radio"
                                    class="sr-only"
                                    wire:model.live="filterTagId"
                                    value="{{ $tag->id }}"
                                    @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'tagIds', value: @js([$tag->id]) } }))"
                                />
                                <span class="min-w-0 flex-1">{{ $tag->name }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

            <div
                class="workspace-filter-category-wrap"
                @mouseenter="openSubmenuFine('recurring')"
                @mouseleave="prefersFineHover && scheduleCloseSubmenu()"
            >
                <button
                    type="button"
                    class="workspace-filter-category-row workspace-filter-category-row--recurring"
                    :class="activeSubmenu === 'recurring' ? 'workspace-filter-category-row--active' : ''"
                    @click="handleCategoryActivate('recurring', $event)"
                    :aria-expanded="activeSubmenu === 'recurring'"
                    aria-controls="wff-flyout-recurring"
                    id="wff-row-recurring"
                >
                    <span class="workspace-filter-category-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                        </svg>
                    </span>
                    <span class="min-w-0 flex-1 truncate">{{ __('Recurring') }}</span>
                    <svg class="size-4 shrink-0 text-zinc-400 dark:text-zinc-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                    </svg>
                </button>
                <div
                    x-cloak
                    x-show="activeSubmenu === 'recurring'"
                    id="wff-flyout-recurring"
                    role="group"
                    aria-labelledby="wff-row-recurring"
                    class="workspace-filter-flyout"
                >
                    @foreach ($recurringOptions as $value => $label)
                        <label wire:key="fb-rec-{{ $value === '' ? 'all' : $value }}" class="workspace-filter-option" @click="closeFlyoutOnly()">
                            <input
                                type="radio"
                                class="sr-only"
                                wire:model.live="filterRecurring"
                                value="{{ $value }}"
                                @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'recurring', value: @js($value === '' ? null : $value) } }))"
                            />
                            <span class="min-w-0 flex-1">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- Task source (Brightspace / Manual) — last filter row before Clear --}}
            <div
                class="workspace-filter-category-wrap"
                @mouseenter="openSubmenuFine('taskSource')"
                @mouseleave="prefersFineHover && scheduleCloseSubmenu()"
            >
                <button
                    type="button"
                    class="workspace-filter-category-row workspace-filter-category-row--task-source"
                    :class="activeSubmenu === 'taskSource' ? 'workspace-filter-category-row--active' : ''"
                    @click="handleCategoryActivate('taskSource', $event)"
                    :aria-expanded="activeSubmenu === 'taskSource'"
                    aria-controls="wff-flyout-task-source"
                    id="wff-row-task-source"
                >
                    <span class="workspace-filter-category-icon" aria-hidden="true">
                        <img src="{{ asset('images/brightspace-icon.png') }}" alt="" class="object-contain" />
                    </span>
                    <span class="min-w-0 flex-1 truncate">{{ __('Source') }}</span>
                    <svg class="size-4 shrink-0 text-zinc-400 dark:text-zinc-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                    </svg>
                </button>
                <div
                    x-cloak
                    x-show="activeSubmenu === 'taskSource'"
                    id="wff-flyout-task-source"
                    role="group"
                    aria-labelledby="wff-row-task-source"
                    class="workspace-filter-flyout"
                >
                    @foreach ($taskSourceOptions as $value => $label)
                        <label wire:key="fb-tsrc-{{ $value === '' ? 'all' : $value }}" class="workspace-filter-option" @click="closeFlyoutOnly()">
                            <input
                                type="radio"
                                class="sr-only"
                                wire:model.live="filterTaskSource"
                                value="{{ $value }}"
                                @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'taskSource', value: @js($value === '' ? null : $value) } }))"
                            />
                            <span class="min-w-0 flex-1">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div x-show="hasActiveFilters" class="mx-1 my-1 border-t border-zinc-200/80 pt-1 dark:border-zinc-700/60">
                <button
                    type="button"
                    role="menuitem"
                    wire:click="clearAllFilters"
                    @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'clearAll' } })); hasActiveFilters = false; closeMenu()"
                    class="workspace-filter-option workspace-filter-option--danger"
                >
                    <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                    <span>{{ __('Clear filters') }}</span>
                </button>
            </div>
        </div>
    </div>
</div>

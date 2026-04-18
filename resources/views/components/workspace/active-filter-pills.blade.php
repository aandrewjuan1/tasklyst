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
    $itemTypeLabels = [
        '' => __('All'),
        'tasks' => __('Tasks'),
        'events' => __('Events'),
        'projects' => __('Projects'),
    ];
    $recurringLabels = [
        'recurring' => __('Recurring'),
        'oneTime' => __('One-time'),
    ];
    $taskSourceLabels = [
        'brightspace' => TaskSourceType::Brightspace->label(),
        'manual' => TaskSourceType::Manual->label(),
    ];

    $tags = $tags instanceof \Illuminate\Support\Collection ? $tags : collect($tags);
    $tagsForJs = $tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values()->all();

    $labels = [
        'itemType' => $itemTypeLabels,
        'taskStatus' => ['' => __('All'), ...$taskStatuses],
        'taskPriority' => ['' => __('All'), ...$taskPriorities],
        'taskComplexity' => ['' => __('All'), ...$taskComplexities],
        'eventStatus' => ['' => __('All'), ...$eventStatuses],
        'recurring' => ['' => __('All'), ...$recurringLabels],
        'taskSource' => ['' => __('All'), ...$taskSourceLabels],
    ];

    $pillLabels = [
        'itemType' => __('Show'),
        'taskStatus' => __('Task status'),
        'taskPriority' => __('Task priority'),
        'taskComplexity' => __('Task complexity'),
        'eventStatus' => __('Event status'),
        'tagIds' => __('Tags'),
        'recurring' => __('Recurring'),
        'taskSource' => __('Source'),
    ];

    $tagIds = $filters['tagIds'] ?? [];
    $tagDisplay = (is_array($tagIds) && $tagIds !== [])
        ? $tags->whereIn('id', $tagIds)->pluck('name')->implode(', ')
        : '';
    $hasOtherActiveFilters = ($filters['taskStatus'] ?? null) || ($filters['taskPriority'] ?? null)
        || ($filters['taskComplexity'] ?? null) || ($filters['eventStatus'] ?? null)
        || (is_array($tagIds) && $tagIds !== []) || ($filters['recurring'] ?? null)
        || ($filters['taskSource'] ?? null);

    $filterPillEnumFillClass = static fn (string $color): string => 'bg-' . $color . '/10 text-' . $color;

    $filterPillTaskStatusColors = collect(TaskStatus::cases())
        ->mapWithKeys(fn (TaskStatus $c): array => [$c->value => $filterPillEnumFillClass($c->color())])
        ->all();

    $filterPillTaskPriorityColors = collect(TaskPriority::cases())
        ->mapWithKeys(fn (TaskPriority $c): array => [$c->value => $filterPillEnumFillClass($c->color())])
        ->all();

    $filterPillTaskComplexityColors = collect(TaskComplexity::cases())
        ->mapWithKeys(fn (TaskComplexity $c): array => [$c->value => $filterPillEnumFillClass($c->color())])
        ->all();

    $filterPillEventStatusColors = collect(EventStatus::cases())
        ->mapWithKeys(fn (EventStatus $c): array => [$c->value => $filterPillEnumFillClass($c->color())])
        ->all();

    $filterPillRecurringRecurring =
        'border-amber-200/55 bg-yellow-50 text-stone-600 shadow-sm dark:border-amber-900/20 dark:bg-yellow-950/12 dark:text-stone-400';

    $filterPillTaskSourceBrightspace =
        'border-blue-500/25 bg-blue-500/10 text-blue-800 shadow-sm dark:border-blue-500/30 dark:bg-blue-500/15 dark:text-blue-200';

    $initialItemType = $filters['itemType'] ?? null;
    $initialTaskStatus = $filters['taskStatus'] ?? null;
    $initialTaskPriority = $filters['taskPriority'] ?? null;
    $initialTaskComplexity = $filters['taskComplexity'] ?? null;
    $initialEventStatus = $filters['eventStatus'] ?? null;
    $initialTaskSource = $filters['taskSource'] ?? null;
    $initialRecurring = $filters['recurring'] ?? null;
    $initialShowCompleted = (bool) ($filters['showCompleted'] ?? false);

    $initialItemTypeClass = match ($initialItemType) {
        'tasks' => 'lic-item-type-pill--task',
        'events' => 'lic-item-type-pill--event',
        'projects' => 'lic-item-type-pill--project',
        default => 'border-zinc-300/90 bg-zinc-100/90 text-zinc-800 dark:border-zinc-600/80 dark:bg-zinc-800/85 dark:text-zinc-100',
    };

    $filterPillDefaultClass = 'bg-muted text-muted-foreground border border-black/10 dark:border-white/10';

    $initialTaskStatusClass = trim(($filterPillTaskStatusColors[$initialTaskStatus] ?? 'bg-muted text-muted-foreground') . ' border border-black/10 dark:border-white/10');
    $initialTaskPriorityClass = trim(($filterPillTaskPriorityColors[$initialTaskPriority] ?? 'bg-muted text-muted-foreground') . ' border border-black/10 dark:border-white/10');
    $initialTaskComplexityClass = trim(($filterPillTaskComplexityColors[$initialTaskComplexity] ?? 'bg-muted text-muted-foreground') . ' border border-black/10 dark:border-white/10');
    $initialEventStatusClass = trim(($filterPillEventStatusColors[$initialEventStatus] ?? 'bg-muted text-muted-foreground') . ' border border-black/10 dark:border-white/10');
    $initialRecurringClass = $initialRecurring === 'recurring'
        ? $filterPillRecurringRecurring
        : $filterPillDefaultClass;
    $initialTaskSourceClass = $initialTaskSource === 'brightspace'
        ? $filterPillTaskSourceBrightspace
        : $filterPillDefaultClass;
@endphp

<div
    x-data="{
        labels: @js($labels),
        pillLabels: @js($pillLabels),
        tags: @js($tagsForJs),
        menus: {
            itemType: false,
            taskStatus: false,
            taskPriority: false,
            taskComplexity: false,
            eventStatus: false,
            tagIds: false,
            recurring: false,
            taskSource: false,
        },
        togglePillMenu(key) {
            const opening = !this.menus[key];
            Object.keys(this.menus).forEach((k) => { this.menus[k] = false; });
            this.menus[key] = opening;
        },
        closePillMenu(key) {
            this.menus[key] = false;
        },
        closeAllPillMenus() {
            Object.keys(this.menus).forEach((k) => { this.menus[k] = false; });
        },
        displayFilters: {
            itemType: @js($filters['itemType'] ?? null),
            taskStatus: @js($filters['taskStatus'] ?? null),
            taskPriority: @js($filters['taskPriority'] ?? null),
            taskComplexity: @js($filters['taskComplexity'] ?? null),
            eventStatus: @js($filters['eventStatus'] ?? null),
            tagIds: @js($filters['tagIds'] ?? []),
            recurring: @js($filters['recurring'] ?? null),
            taskSource: @js($filters['taskSource'] ?? null),
            showCompleted: @js((bool) ($filters['showCompleted'] ?? false)),
        },
        _m(cls) {
            return cls ? cls.trim().split(/\s+/).filter(Boolean) : [];
        },
        filterPillTaskStatusColors: @js($filterPillTaskStatusColors),
        filterPillTaskPriorityColors: @js($filterPillTaskPriorityColors),
        filterPillTaskComplexityColors: @js($filterPillTaskComplexityColors),
        filterPillEventStatusColors: @js($filterPillEventStatusColors),
        filterPillRecurringRecurring: @js($filterPillRecurringRecurring),
        filterPillTaskSourceBrightspace: @js($filterPillTaskSourceBrightspace),
        pillClassesTaskSource() {
            const v = this.displayFilters.taskSource;
            if (v === 'brightspace') {
                return this._m(this.filterPillTaskSourceBrightspace);
            }
            const fill = 'bg-muted text-muted-foreground';
            return [...this._m(fill), 'border', 'border-black/10', 'dark:border-white/10'];
        },
        pillClassesTaskStatus() {
            const v = this.displayFilters.taskStatus;
            const fill = this.filterPillTaskStatusColors[v] ?? 'bg-muted text-muted-foreground';
            return [...this._m(fill), 'border', 'border-black/10', 'dark:border-white/10'];
        },
        pillClassesTaskPriority() {
            const v = this.displayFilters.taskPriority;
            const fill = this.filterPillTaskPriorityColors[v] ?? 'bg-muted text-muted-foreground';
            return [...this._m(fill), 'border', 'border-black/10', 'dark:border-white/10'];
        },
        pillClassesTaskComplexity() {
            const v = this.displayFilters.taskComplexity;
            const fill = this.filterPillTaskComplexityColors[v] ?? 'bg-muted text-muted-foreground';
            return [...this._m(fill), 'border', 'border-black/10', 'dark:border-white/10'];
        },
        pillClassesEventStatus() {
            const v = this.displayFilters.eventStatus;
            const fill = this.filterPillEventStatusColors[v] ?? 'bg-muted text-muted-foreground';
            return [...this._m(fill), 'border', 'border-black/10', 'dark:border-white/10'];
        },
        pillClassesRecurring() {
            const v = this.displayFilters.recurring;
            if (v === 'recurring') {
                return this._m(this.filterPillRecurringRecurring);
            }
            const fill = 'bg-muted text-muted-foreground';
            return [...this._m(fill), 'border', 'border-black/10', 'dark:border-white/10'];
        },
        userManuallySetItemType: false,
        _filterOptimisticCleanup: null,
        syncItemTypeOptimistic() {
            const hasEvent = this.displayFilters.eventStatus != null && this.displayFilters.eventStatus !== '';
            const hasTask = (this.displayFilters.taskStatus != null && this.displayFilters.taskStatus !== '') ||
                (this.displayFilters.taskPriority != null && this.displayFilters.taskPriority !== '') ||
                (this.displayFilters.taskComplexity != null && this.displayFilters.taskComplexity !== '') ||
                (this.displayFilters.taskSource != null && this.displayFilters.taskSource !== '');
            let newVal = null;
            if (hasEvent && hasTask) {
                if (this.userManuallySetItemType) return;
                newVal = null;
            } else if (hasEvent) {
                newVal = 'events';
            } else if (hasTask) {
                newVal = 'tasks';
            } else {
                if (this.userManuallySetItemType) return;
                newVal = null;
            }
            this.userManuallySetItemType = false;
            this.displayFilters.itemType = newVal;
        },
        init() {
            const syncFromWire = () => {
                this.displayFilters.itemType = $wire.filterItemType ?? null;
                this.displayFilters.taskStatus = $wire.filterTaskStatus ?? null;
                this.displayFilters.taskPriority = $wire.filterTaskPriority ?? null;
                this.displayFilters.taskComplexity = $wire.filterTaskComplexity ?? null;
                this.displayFilters.eventStatus = $wire.filterEventStatus ?? null;
                this.displayFilters.tagIds = $wire.filterTagIds ?? [];
                this.displayFilters.recurring = $wire.filterRecurring ?? null;
                this.displayFilters.taskSource = $wire.filterTaskSource ?? null;
                this.displayFilters.showCompleted = ($wire.showCompleted ?? '0') === '1';
            };
            this.$watch('$wire.filterItemType', () => { this.displayFilters.itemType = $wire.filterItemType ?? null; });
            this.$watch('$wire.filterTaskStatus', () => { this.displayFilters.taskStatus = $wire.filterTaskStatus ?? null; });
            this.$watch('$wire.filterTaskPriority', () => { this.displayFilters.taskPriority = $wire.filterTaskPriority ?? null; });
            this.$watch('$wire.filterTaskComplexity', () => { this.displayFilters.taskComplexity = $wire.filterTaskComplexity ?? null; });
            this.$watch('$wire.filterEventStatus', () => { this.displayFilters.eventStatus = $wire.filterEventStatus ?? null; });
            this.$watch('$wire.filterTagIds', () => { this.displayFilters.tagIds = $wire.filterTagIds ?? []; });
            this.$watch('$wire.filterRecurring', () => { this.displayFilters.recurring = $wire.filterRecurring ?? null; });
            this.$watch('$wire.filterTaskSource', () => { this.displayFilters.taskSource = $wire.filterTaskSource ?? null; });
            this.$watch('$wire.showCompleted', () => { this.displayFilters.showCompleted = ($wire.showCompleted ?? '0') === '1'; });
            const typeSpecificKeys = ['taskStatus', 'taskPriority', 'taskComplexity', 'taskSource', 'eventStatus'];
            const handler = (e) => {
                const { key, value } = e.detail || {};
                if (key === 'itemType') {
                    this.userManuallySetItemType = true;
                }
                if (key === 'clearAll') {
                    this.userManuallySetItemType = false;
                    this.displayFilters.itemType = null;
                    this.displayFilters.taskStatus = null;
                    this.displayFilters.taskPriority = null;
                    this.displayFilters.taskComplexity = null;
                    this.displayFilters.eventStatus = null;
                    this.displayFilters.tagIds = [];
                    this.displayFilters.recurring = null;
                    this.displayFilters.taskSource = null;
                } else if (key === 'tagIds') {
                    this.displayFilters.tagIds = Array.isArray(value) ? value : (value ? [value] : []);
                } else if (key) {
                    this.displayFilters[key] = value ?? null;
                }
                if (typeSpecificKeys.includes(key)) {
                    this.syncItemTypeOptimistic();
                }
            };
            window.addEventListener('filter-optimistic', handler);
            this._filterOptimisticCleanup = () => window.removeEventListener('filter-optimistic', handler);
        },
        destroy() {
            this._filterOptimisticCleanup?.();
        },
        showValue(key) {
            const val = this.displayFilters[key];
            if (key === 'tagIds') {
                if (!val || !Array.isArray(val) || val.length === 0) return null;
                const names = val.map(id => this.tags.find(t => t.id == id)?.name).filter(Boolean);
                return names.join(', ') || null;
            }
            if (val == null || val === '') return null;
            return this.labels[key]?.[val] ?? val;
        },
        hasActiveFilters() {
            return this.displayFilters.itemType || this.displayFilters.taskStatus || this.displayFilters.taskPriority ||
                this.displayFilters.taskComplexity || this.displayFilters.eventStatus ||
                (this.displayFilters.tagIds?.length > 0) || this.displayFilters.recurring || this.displayFilters.taskSource;
        },
        hasOtherActiveFilters() {
            return this.displayFilters.taskStatus || this.displayFilters.taskPriority ||
                this.displayFilters.taskComplexity || this.displayFilters.eventStatus ||
                (this.displayFilters.tagIds?.length > 0) || this.displayFilters.recurring || this.displayFilters.taskSource;
        },
        setItemType(value) {
            this.displayFilters.itemType = value || null;
            $wire.set('filterItemType', value || null);
        },
        clearFilter(key) {
            if (key === 'itemType') {
                this.displayFilters.itemType = null;
            } else if (key === 'tagIds') {
                this.displayFilters.tagIds = [];
            } else {
                this.displayFilters[key] = null;
            }
            $wire.clearFilter(key);
        },
        clearAllOptimistic() {
            this.displayFilters.taskStatus = null;
            this.displayFilters.taskPriority = null;
            this.displayFilters.taskComplexity = null;
            this.displayFilters.eventStatus = null;
            this.displayFilters.tagIds = [];
            this.displayFilters.recurring = null;
            this.displayFilters.taskSource = null;
            window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'clearAll' } }));
            $wire.clearAllFilters();
        },
        toggleShowCompleted() {
            this.displayFilters.showCompleted = !this.displayFilters.showCompleted;
            $wire.set('showCompleted', this.displayFilters.showCompleted ? '1' : '0');
        },
    }"
    class="flex min-h-9 flex-wrap items-center gap-2"
    @keydown.escape.window="closeAllPillMenus()"
>
    @if ($showListScopedFilters)
        <div class="inline-flex items-center gap-1.5">
            <flux:tooltip :content="__('Toggle completed items visibility')" position="top">
                <button
                    type="button"
                    @click="toggleShowCompleted()"
                    class="inline-flex size-8 items-center justify-center rounded-full border transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/45 {{ $initialShowCompleted
                        ? 'border-emerald-400/60 bg-emerald-500/12 text-emerald-700 shadow-sm dark:border-emerald-500/45 dark:bg-emerald-500/18 dark:text-emerald-300'
                        : 'border-zinc-300/80 bg-white/90 text-zinc-600 hover:bg-zinc-100 dark:border-zinc-600/80 dark:bg-zinc-800/90 dark:text-zinc-300 dark:hover:bg-zinc-700/90' }}"
                    :class="displayFilters.showCompleted
                        ? 'border-emerald-400/60 bg-emerald-500/12 text-emerald-700 shadow-sm dark:border-emerald-500/45 dark:bg-emerald-500/18 dark:text-emerald-300'
                        : 'border-zinc-300/80 bg-white/90 text-zinc-600 hover:bg-zinc-100 dark:border-zinc-600/80 dark:bg-zinc-800/90 dark:text-zinc-300 dark:hover:bg-zinc-700/90'"
                    :aria-pressed="displayFilters.showCompleted"
                    aria-label="{{ __('Toggle completed items visibility') }}"
                >
                    <flux:icon name="check-badge" class="size-4" />
                </button>
            </flux:tooltip>
            {{-- Show pill: same palette as list-item-card _item-type-pill (lic-item-type-pill--*) --}}
            <span
                class="lic-item-type-pill max-w-full {{ $initialItemTypeClass }}"
                :class="{
                    'lic-item-type-pill--task': displayFilters.itemType === 'tasks',
                    'lic-item-type-pill--event': displayFilters.itemType === 'events',
                    'lic-item-type-pill--project': displayFilters.itemType === 'projects',
                    'border-zinc-300/90 bg-zinc-100/90 text-zinc-800 dark:border-zinc-600/80 dark:bg-zinc-800/85 dark:text-zinc-100': !displayFilters.itemType,
                }"
            >
                <div class="relative min-w-0" @click.outside="closePillMenu('itemType')">
                    <button
                        type="button"
                        class="workspace-filter-pill-trigger"
                        @click.stop="togglePillMenu('itemType')"
                        :aria-expanded="menus.itemType"
                        aria-haspopup="menu"
                    >
                        <span
                            class="truncate"
                            x-text="pillLabels.itemType + ': ' + (labels.itemType[displayFilters.itemType ?? ''] || '{{ __('All') }}')"
                        >{{ $pillLabels['itemType'] }}: {{ $filters['itemType'] ? ($itemTypeLabels[$filters['itemType']] ?? __('All')) : __('All') }}</span>
                        <svg class="size-3.5 shrink-0 opacity-70" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                        </svg>
                    </button>
                    <div
                        x-cloak
                        x-show="menus.itemType"
                        x-transition
                        class="workspace-filter-panel workspace-filter-panel--start top-full z-[60] mt-1"
                        role="menu"
                    >
                        @foreach ($itemTypeLabels as $optValue => $optLabel)
                            <label
                                wire:key="pill-it-{{ $optValue === '' ? 'all' : $optValue }}"
                                class="workspace-filter-option"
                                @click="closePillMenu('itemType')"
                            >
                                <input
                                    type="radio"
                                    class="sr-only"
                                    wire:model.live="filterItemType"
                                    value="{{ $optValue }}"
                                    @click="
                                        const v = '{{ $optValue }}' || null;
                                        displayFilters.itemType = (v === '' || v === null) ? null : v;
                                        window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'itemType', value: (v === '' || v === null) ? null : v } }));
                                    "
                                />
                                <span class="min-w-0 flex-1">{{ $optLabel }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </span>
        </div>
    @else
        {{-- Kanban: tasks only; item type is display-only (not wired to filterItemType). --}}
        <flux:tooltip
            :content="__('Kanban only shows tasks, grouped by task status. Switch to List view to see events and projects.')"
            position="top"
            align="start"
        >
            <span
                tabindex="0"
                class="lic-item-type-pill lic-item-type-pill--task max-w-full cursor-default select-none outline-none focus-visible:ring-2 focus-visible:ring-ring"
                role="status"
                data-workspace-item-type-kanban-readonly
                aria-label="{{ __('Show') }}: {{ __('Tasks') }}"
            >
                <span class="min-w-0 truncate font-normal normal-case">{{ __('Show') }}: {{ __('Tasks') }}</span>
            </span>
        </flux:tooltip>
    @endif

    <span
        class="text-[11px] leading-snug text-muted-foreground/80 sm:text-xs dark:text-zinc-500/90"
        x-show="!hasActiveFilters()"
        style="{{ ($filters['hasActiveFilters'] ?? false) ? 'display:none' : '' }}"
        aria-live="polite"
    >
        {{ __('No active filters.') }}
    </span>

    {{-- Task status --}}
    <span
        x-show="showValue('taskStatus')"
        style="{{ ($filters['taskStatus'] ?? null) ? '' : 'display:none' }}"
        class="workspace-filter-property-pill max-w-full {{ $initialTaskStatusClass }}"
        :class="pillClassesTaskStatus()"
    >
        <div class="relative min-w-0" @click.outside="closePillMenu('taskStatus')">
            <button
                type="button"
                class="workspace-filter-pill-trigger"
                @click.stop="togglePillMenu('taskStatus')"
                :aria-expanded="menus.taskStatus"
                aria-haspopup="menu"
            >
                <span class="truncate" x-text="pillLabels.taskStatus + ': ' + showValue('taskStatus')">{{ $pillLabels['taskStatus'] }}: {{ ($filters['taskStatus'] ?? null) ? ($taskStatuses[$filters['taskStatus']] ?? '') : '' }}</span>
                <svg class="size-3.5 shrink-0 opacity-70" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                </svg>
            </button>
            <div
                x-cloak
                x-show="menus.taskStatus"
                x-transition
                class="workspace-filter-panel workspace-filter-panel--start top-full z-[60] mt-1 max-h-64 overflow-y-auto"
                role="menu"
            >
                @foreach ($taskStatuses as $value => $label)
                    <label wire:key="pill-ts-{{ $value }}" class="workspace-filter-option" @click="closePillMenu('taskStatus')">
                        <input
                            type="radio"
                            class="sr-only"
                            wire:model.live="filterTaskStatus"
                            value="{{ $value }}"
                            @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'taskStatus', value: @js($value) } }))"
                        />
                        <span class="min-w-0 flex-1">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        </div>
        <button
            type="button"
            class="workspace-filter-pill-clear"
            :aria-label="'{{ __('Clear :filter filter', ['filter' => '__PLACEHOLDER__']) }}'.replace('__PLACEHOLDER__', pillLabels.taskStatus)"
            @click.stop="clearFilter('taskStatus')"
        >
            <svg class="size-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </span>

    {{-- Task priority --}}
    <span
        x-show="showValue('taskPriority')"
        style="{{ ($filters['taskPriority'] ?? null) ? '' : 'display:none' }}"
        class="workspace-filter-property-pill max-w-full {{ $initialTaskPriorityClass }}"
        :class="pillClassesTaskPriority()"
    >
        <div class="relative min-w-0" @click.outside="closePillMenu('taskPriority')">
            <button
                type="button"
                class="workspace-filter-pill-trigger"
                @click.stop="togglePillMenu('taskPriority')"
                :aria-expanded="menus.taskPriority"
                aria-haspopup="menu"
            >
                <span class="truncate" x-text="pillLabels.taskPriority + ': ' + showValue('taskPriority')">{{ $pillLabels['taskPriority'] }}: {{ ($filters['taskPriority'] ?? null) ? ($taskPriorities[$filters['taskPriority']] ?? '') : '' }}</span>
                <svg class="size-3.5 shrink-0 opacity-70" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                </svg>
            </button>
            <div
                x-cloak
                x-show="menus.taskPriority"
                x-transition
                class="workspace-filter-panel workspace-filter-panel--start top-full z-[60] mt-1 max-h-64 overflow-y-auto"
                role="menu"
            >
                @foreach ($taskPriorities as $value => $label)
                    <label wire:key="pill-tp-{{ $value }}" class="workspace-filter-option" @click="closePillMenu('taskPriority')">
                        <input
                            type="radio"
                            class="sr-only"
                            wire:model.live="filterTaskPriority"
                            value="{{ $value }}"
                            @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'taskPriority', value: @js($value) } }))"
                        />
                        <span class="min-w-0 flex-1">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        </div>
        <button
            type="button"
            class="workspace-filter-pill-clear"
            :aria-label="'{{ __('Clear :filter filter', ['filter' => '__PLACEHOLDER__']) }}'.replace('__PLACEHOLDER__', pillLabels.taskPriority)"
            @click.stop="clearFilter('taskPriority')"
        >
            <svg class="size-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </span>

    {{-- Task complexity --}}
    <span
        x-show="showValue('taskComplexity')"
        style="{{ ($filters['taskComplexity'] ?? null) ? '' : 'display:none' }}"
        class="workspace-filter-property-pill max-w-full {{ $initialTaskComplexityClass }}"
        :class="pillClassesTaskComplexity()"
    >
        <div class="relative min-w-0" @click.outside="closePillMenu('taskComplexity')">
            <button
                type="button"
                class="workspace-filter-pill-trigger"
                @click.stop="togglePillMenu('taskComplexity')"
                :aria-expanded="menus.taskComplexity"
                aria-haspopup="menu"
            >
                <span class="truncate" x-text="pillLabels.taskComplexity + ': ' + showValue('taskComplexity')">{{ $pillLabels['taskComplexity'] }}: {{ ($filters['taskComplexity'] ?? null) ? ($taskComplexities[$filters['taskComplexity']] ?? '') : '' }}</span>
                <svg class="size-3.5 shrink-0 opacity-70" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                </svg>
            </button>
            <div
                x-cloak
                x-show="menus.taskComplexity"
                x-transition
                class="workspace-filter-panel workspace-filter-panel--start top-full z-[60] mt-1 max-h-64 overflow-y-auto"
                role="menu"
            >
                @foreach ($taskComplexities as $value => $label)
                    <label wire:key="pill-tc-{{ $value }}" class="workspace-filter-option" @click="closePillMenu('taskComplexity')">
                        <input
                            type="radio"
                            class="sr-only"
                            wire:model.live="filterTaskComplexity"
                            value="{{ $value }}"
                            @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'taskComplexity', value: @js($value) } }))"
                        />
                        <span class="min-w-0 flex-1">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        </div>
        <button
            type="button"
            class="workspace-filter-pill-clear"
            :aria-label="'{{ __('Clear :filter filter', ['filter' => '__PLACEHOLDER__']) }}'.replace('__PLACEHOLDER__', pillLabels.taskComplexity)"
            @click.stop="clearFilter('taskComplexity')"
        >
            <svg class="size-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </span>

    {{-- Task source (Brightspace / Manual) --}}
    <span
        x-show="showValue('taskSource')"
        style="{{ ($filters['taskSource'] ?? null) ? '' : 'display:none' }}"
        class="workspace-filter-property-pill max-w-full {{ $initialTaskSourceClass }}"
        :class="pillClassesTaskSource()"
    >
        <div class="relative min-w-0" @click.outside="closePillMenu('taskSource')">
            <button
                type="button"
                class="workspace-filter-pill-trigger"
                @click.stop="togglePillMenu('taskSource')"
                :aria-expanded="menus.taskSource"
                aria-haspopup="menu"
            >
                <span class="inline-flex min-w-0 items-center gap-1">
                    <img
                        x-show="displayFilters.taskSource === 'brightspace'"
                        src="{{ asset('images/brightspace-icon.png') }}"
                        alt=""
                        class="size-3 shrink-0 object-contain"
                        @if(($filters['taskSource'] ?? null) !== 'brightspace') style="display: none" @endif
                    />
                    <span class="truncate" x-text="pillLabels.taskSource + ': ' + showValue('taskSource')">{{ $pillLabels['taskSource'] }}: {{ ($filters['taskSource'] ?? null) ? ($taskSourceLabels[$filters['taskSource']] ?? '') : '' }}</span>
                </span>
                <svg class="size-3.5 shrink-0 opacity-70" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                </svg>
            </button>
            <div
                x-cloak
                x-show="menus.taskSource"
                x-transition
                class="workspace-filter-panel workspace-filter-panel--start top-full z-[60] mt-1"
                role="menu"
            >
                @foreach ($taskSourceLabels as $value => $label)
                    <label wire:key="pill-tsrc-{{ $value }}" class="workspace-filter-option" @click="closePillMenu('taskSource')">
                        <input
                            type="radio"
                            class="sr-only"
                            wire:model.live="filterTaskSource"
                            value="{{ $value }}"
                            @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'taskSource', value: @js($value) } }))"
                        />
                        <span class="min-w-0 flex-1">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        </div>
        <button
            type="button"
            class="workspace-filter-pill-clear"
            :aria-label="'{{ __('Clear :filter filter', ['filter' => '__PLACEHOLDER__']) }}'.replace('__PLACEHOLDER__', pillLabels.taskSource)"
            @click.stop="clearFilter('taskSource')"
        >
            <svg class="size-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </span>

    @if ($showListScopedFilters)
        {{-- Event status --}}
        <span
            x-show="showValue('eventStatus')"
            style="{{ ($filters['eventStatus'] ?? null) ? '' : 'display:none' }}"
            class="workspace-filter-property-pill max-w-full {{ $initialEventStatusClass }}"
            :class="pillClassesEventStatus()"
        >
            <div class="relative min-w-0" @click.outside="closePillMenu('eventStatus')">
                <button
                    type="button"
                    class="workspace-filter-pill-trigger"
                    @click.stop="togglePillMenu('eventStatus')"
                    :aria-expanded="menus.eventStatus"
                    aria-haspopup="menu"
                >
                    <span class="truncate" x-text="pillLabels.eventStatus + ': ' + showValue('eventStatus')">{{ $pillLabels['eventStatus'] }}: {{ ($filters['eventStatus'] ?? null) ? ($eventStatuses[$filters['eventStatus']] ?? '') : '' }}</span>
                    <svg class="size-3.5 shrink-0 opacity-70" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                    </svg>
                </button>
                <div
                    x-cloak
                    x-show="menus.eventStatus"
                    x-transition
                    class="workspace-filter-panel workspace-filter-panel--start top-full z-[60] mt-1 max-h-64 overflow-y-auto"
                    role="menu"
                >
                    @foreach ($eventStatuses as $value => $label)
                        <label wire:key="pill-es-{{ $value }}" class="workspace-filter-option" @click="closePillMenu('eventStatus')">
                            <input
                                type="radio"
                                class="sr-only"
                                wire:model.live="filterEventStatus"
                                value="{{ $value }}"
                                @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'eventStatus', value: @js($value) } }))"
                            />
                            <span class="min-w-0 flex-1">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
            <button
                type="button"
                class="workspace-filter-pill-clear"
                :aria-label="'{{ __('Clear :filter filter', ['filter' => '__PLACEHOLDER__']) }}'.replace('__PLACEHOLDER__', pillLabels.eventStatus)"
                @click.stop="clearFilter('eventStatus')"
            >
                <svg class="size-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </span>
    @endif

    {{-- Tags --}}
    @if ($tags->isNotEmpty())
        <span
            x-show="showValue('tagIds')"
            style="{{ $tagDisplay ? '' : 'display:none' }}"
            class="workspace-filter-property-pill max-w-full border border-black/10 bg-muted text-muted-foreground dark:border-white/10"
        >
            <div class="relative min-w-0" @click.outside="closePillMenu('tagIds')">
                <button
                    type="button"
                    class="workspace-filter-pill-trigger"
                    @click.stop="togglePillMenu('tagIds')"
                    :aria-expanded="menus.tagIds"
                    aria-haspopup="menu"
                >
                    <span class="truncate" x-text="pillLabels.tagIds + ': ' + showValue('tagIds')">{{ $pillLabels['tagIds'] }}: {{ $tagDisplay }}</span>
                    <svg class="size-3.5 shrink-0 opacity-70" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                    </svg>
                </button>
                <div
                    x-cloak
                    x-show="menus.tagIds"
                    x-transition
                    class="workspace-filter-panel workspace-filter-panel--start top-full z-[60] mt-1 max-h-64 overflow-y-auto"
                    role="menu"
                >
                    @foreach ($tags as $tag)
                        <label wire:key="pill-tag-{{ $tag->id }}" class="workspace-filter-option" @click="closePillMenu('tagIds')">
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
            <button
                type="button"
                class="workspace-filter-pill-clear"
                :aria-label="'{{ __('Clear :filter filter', ['filter' => '__PLACEHOLDER__']) }}'.replace('__PLACEHOLDER__', pillLabels.tagIds)"
                @click.stop="clearFilter('tagIds')"
            >
                <svg class="size-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </span>
    @endif

    {{-- Recurring --}}
    <span
        x-show="showValue('recurring')"
        style="{{ ($filters['recurring'] ?? null) ? '' : 'display:none' }}"
        class="workspace-filter-property-pill max-w-full {{ $initialRecurringClass }}"
        :class="pillClassesRecurring()"
    >
        <div class="relative min-w-0" @click.outside="closePillMenu('recurring')">
            <button
                type="button"
                class="workspace-filter-pill-trigger"
                @click.stop="togglePillMenu('recurring')"
                :aria-expanded="menus.recurring"
                aria-haspopup="menu"
            >
                <span class="truncate" x-text="pillLabels.recurring + ': ' + showValue('recurring')">{{ $pillLabels['recurring'] }}: {{ ($filters['recurring'] ?? null) ? ($recurringLabels[$filters['recurring']] ?? '') : '' }}</span>
                <svg class="size-3.5 shrink-0 opacity-70" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                </svg>
            </button>
            <div
                x-cloak
                x-show="menus.recurring"
                x-transition
                class="workspace-filter-panel workspace-filter-panel--start top-full z-[60] mt-1"
                role="menu"
            >
                @foreach (['recurring' => __('Recurring'), 'oneTime' => __('One-time')] as $value => $label)
                    <label wire:key="pill-rec-{{ $value }}" class="workspace-filter-option" @click="closePillMenu('recurring')">
                        <input
                            type="radio"
                            class="sr-only"
                            wire:model.live="filterRecurring"
                            value="{{ $value }}"
                            @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'recurring', value: @js($value) } }))"
                        />
                        <span class="min-w-0 flex-1">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        </div>
        <button
            type="button"
            class="workspace-filter-pill-clear"
            :aria-label="'{{ __('Clear :filter filter', ['filter' => '__PLACEHOLDER__']) }}'.replace('__PLACEHOLDER__', pillLabels.recurring)"
            @click.stop="clearFilter('recurring')"
        >
            <svg class="size-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </span>

    <button
        x-show="hasOtherActiveFilters()"
        style="{{ $hasOtherActiveFilters ? '' : 'display:none' }}"
        type="button"
        @click="clearAllOptimistic()"
        class="shrink-0 text-xs font-medium text-muted-foreground underline-offset-2 transition-colors hover:text-foreground hover:underline"
    >
        {{ __('Clear all') }}
    </button>
</div>

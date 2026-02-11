@props([
    'filters' => [],
    'tags' => [],
])

@php
    use App\Enums\EventStatus;
    use App\Enums\TaskComplexity;
    use App\Enums\TaskPriority;
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

    $tags = $tags instanceof \Illuminate\Support\Collection ? $tags : collect($tags);
    $tagsForJs = $tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values()->all();

    $labels = [
        'itemType' => $itemTypeLabels,
        'taskStatus' => ['' => __('All'), ...$taskStatuses],
        'taskPriority' => ['' => __('All'), ...$taskPriorities],
        'taskComplexity' => ['' => __('All'), ...$taskComplexities],
        'eventStatus' => ['' => __('All'), ...$eventStatuses],
        'recurring' => ['' => __('All'), ...$recurringLabels],
    ];

    $pillLabels = [
        'itemType' => __('Show'),
        'taskStatus' => __('Task status'),
        'taskPriority' => __('Task priority'),
        'taskComplexity' => __('Task complexity'),
        'eventStatus' => __('Event status'),
        'tagIds' => __('Tags'),
        'recurring' => __('Recurring'),
    ];

    $tagIds = $filters['tagIds'] ?? [];
    $tagDisplay = (is_array($tagIds) && $tagIds !== [])
        ? $tags->whereIn('id', $tagIds)->pluck('name')->implode(', ')
        : '';
    $hasOtherActiveFilters = ($filters['taskStatus'] ?? null) || ($filters['taskPriority'] ?? null)
        || ($filters['taskComplexity'] ?? null) || ($filters['eventStatus'] ?? null)
        || (is_array($tagIds) && $tagIds !== []) || ($filters['recurring'] ?? null);
@endphp

<div
    x-data="{
        labels: @js($labels),
        pillLabels: @js($pillLabels),
        tags: @js($tagsForJs),
        displayFilters: {
            itemType: @js($filters['itemType'] ?? null),
            taskStatus: @js($filters['taskStatus'] ?? null),
            taskPriority: @js($filters['taskPriority'] ?? null),
            taskComplexity: @js($filters['taskComplexity'] ?? null),
            eventStatus: @js($filters['eventStatus'] ?? null),
            tagIds: @js($filters['tagIds'] ?? []),
            recurring: @js($filters['recurring'] ?? null),
        },
        userManuallySetItemType: false,
        _filterOptimisticCleanup: null,
        syncItemTypeOptimistic() {
            const hasEvent = this.displayFilters.eventStatus != null && this.displayFilters.eventStatus !== '';
            const hasTask = (this.displayFilters.taskStatus != null && this.displayFilters.taskStatus !== '') ||
                (this.displayFilters.taskPriority != null && this.displayFilters.taskPriority !== '') ||
                (this.displayFilters.taskComplexity != null && this.displayFilters.taskComplexity !== '');
            const prev = this.displayFilters.itemType ?? null;
            let newVal = null;
            if (hasEvent && hasTask) {
                if (this.userManuallySetItemType) return;
                newVal = null;
            } else if (hasEvent) {
                newVal = 'events';
            } else if (hasTask) {
                newVal = 'tasks';
            } else {
                return;
            }
            this.userManuallySetItemType = false;
            this.displayFilters.itemType = newVal;
            if (prev !== newVal) {
                const msg = newVal === null ? '{{ __("Showing all (filtered by events and tasks)") }}' : (newVal === 'events' ? '{{ __("Showing events only (filtered by event status)") }}' : '{{ __("Showing tasks only (filtered by task status)") }}');
                $wire.$dispatch('toast', { type: 'info', message: msg });
            }
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
            };
            this.$watch('$wire.filterItemType', () => { this.displayFilters.itemType = $wire.filterItemType ?? null; });
            this.$watch('$wire.filterTaskStatus', () => { this.displayFilters.taskStatus = $wire.filterTaskStatus ?? null; });
            this.$watch('$wire.filterTaskPriority', () => { this.displayFilters.taskPriority = $wire.filterTaskPriority ?? null; });
            this.$watch('$wire.filterTaskComplexity', () => { this.displayFilters.taskComplexity = $wire.filterTaskComplexity ?? null; });
            this.$watch('$wire.filterEventStatus', () => { this.displayFilters.eventStatus = $wire.filterEventStatus ?? null; });
            this.$watch('$wire.filterTagIds', () => { this.displayFilters.tagIds = $wire.filterTagIds ?? []; });
            this.$watch('$wire.filterRecurring', () => { this.displayFilters.recurring = $wire.filterRecurring ?? null; });
            const typeSpecificKeys = ['taskStatus', 'taskPriority', 'taskComplexity', 'eventStatus'];
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
                (this.displayFilters.tagIds?.length > 0) || this.displayFilters.recurring;
        },
        hasOtherActiveFilters() {
            return this.displayFilters.taskStatus || this.displayFilters.taskPriority ||
                this.displayFilters.taskComplexity || this.displayFilters.eventStatus ||
                (this.displayFilters.tagIds?.length > 0) || this.displayFilters.recurring;
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
            window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'clearAll' } }));
            $wire.clearAllFilters();
        },
    }"
    class="flex flex-wrap items-center gap-2"
>
    {{-- Show pill - clickable dropdown --}}
    <flux:dropdown position="bottom" align="start">
        <flux:button
            variant="ghost"
            size="xs"
            icon:trailing="chevron-down"
            class="inline-flex items-center gap-1.5 rounded-full border border-primary/30 bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-foreground shadow-none ring-0 hover:bg-primary/20 dark:border-primary/40 dark:bg-primary/20 dark:text-foreground dark:hover:bg-primary/30"
        >
            <span x-text="pillLabels.itemType + ': ' + (labels.itemType[displayFilters.itemType ?? ''] || '{{ __('All') }}')">{{ $pillLabels['itemType'] }}: {{ $filters['itemType'] ? ($itemTypeLabels[$filters['itemType']] ?? __('All')) : __('All') }}</span>
        </flux:button>
        <flux:menu class="min-w-[8rem]">
            <flux:menu.radio.group wire:model.change.live="filterItemType">
                @foreach ($itemTypeLabels as $optValue => $optLabel)
                    <flux:menu.radio
                        value="{{ $optValue }}"
                        @click="
                            const v = '{{ $optValue }}' || null;
                            displayFilters.itemType = (v === '' || v === null) ? null : v;
                            window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'itemType', value: (v === '' || v === null) ? null : v } }));
                        "
                    >{{ $optLabel }}</flux:menu.radio>
                @endforeach
            </flux:menu.radio.group>
        </flux:menu>
    </flux:dropdown>

    {{-- Task status pill - clickable dropdown with X to clear --}}
    <span
        x-show="showValue('taskStatus')"
        style="{{ ($filters['taskStatus'] ?? null) ? '' : 'display:none' }}"
        class="inline-flex items-center gap-1.5 rounded-full border border-primary/30 bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-foreground shadow-none dark:border-primary/40 dark:bg-primary/20 dark:text-foreground"
    >
        <flux:dropdown position="bottom" align="start">
            <flux:button
                variant="ghost"
                size="xs"
                icon:trailing="chevron-down"
                class="min-w-0 border-0 bg-transparent px-0 shadow-none ring-0 hover:bg-transparent dark:hover:bg-transparent"
            >
                <span x-text="pillLabels.taskStatus + ': ' + showValue('taskStatus')">{{ $pillLabels['taskStatus'] }}: {{ ($filters['taskStatus'] ?? null) ? ($taskStatuses[$filters['taskStatus']] ?? '') : '' }}</span>
            </flux:button>
                <flux:menu class="min-w-[8rem]">
                    <flux:menu.radio.group wire:model.change.live="filterTaskStatus">
                        @foreach ($taskStatuses as $value => $label)
                            <flux:menu.radio value="{{ $value }}" @click="displayFilters.taskStatus = '{{ $value }}'; window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'taskStatus', value: '{{ $value }}' } }))">{{ $label }}</flux:menu.radio>
                        @endforeach
                    </flux:menu.radio.group>
                </flux:menu>
            </flux:dropdown>
            <button
                type="button"
                class="shrink-0 rounded-full p-0.5 transition-colors hover:bg-primary/30 hover:ring-2 hover:ring-primary/30 dark:hover:bg-primary/40 dark:hover:ring-primary/40"
                :aria-label="'{{ __('Clear :filter filter', ['filter' => '__PLACEHOLDER__']) }}'.replace('__PLACEHOLDER__', pillLabels.taskStatus)"
                @click.stop="clearFilter('taskStatus')"
            >
                <flux:icon name="x-mark" class="size-3" />
            </button>
    </span>

    {{-- Task priority pill - clickable dropdown with X to clear --}}
    <span
        x-show="showValue('taskPriority')"
        style="{{ ($filters['taskPriority'] ?? null) ? '' : 'display:none' }}"
        class="inline-flex items-center gap-1.5 rounded-full border border-primary/30 bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-foreground shadow-none dark:border-primary/40 dark:bg-primary/20 dark:text-foreground"
    >
        <flux:dropdown position="bottom" align="start">
            <flux:button
                variant="ghost"
                size="xs"
                icon:trailing="chevron-down"
                class="min-w-0 border-0 bg-transparent px-0 shadow-none ring-0 hover:bg-transparent dark:hover:bg-transparent"
            >
                <span x-text="pillLabels.taskPriority + ': ' + showValue('taskPriority')">{{ $pillLabels['taskPriority'] }}: {{ ($filters['taskPriority'] ?? null) ? ($taskPriorities[$filters['taskPriority']] ?? '') : '' }}</span>
            </flux:button>
                <flux:menu class="min-w-[8rem]">
                    <flux:menu.radio.group wire:model.change.live="filterTaskPriority">
                        @foreach ($taskPriorities as $value => $label)
                            <flux:menu.radio value="{{ $value }}" @click="displayFilters.taskPriority = '{{ $value }}'; window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'taskPriority', value: '{{ $value }}' } }))">{{ $label }}</flux:menu.radio>
                        @endforeach
                    </flux:menu.radio.group>
            </flux:menu>
        </flux:dropdown>
        <button
            type="button"
            class="shrink-0 rounded-full p-0.5 transition-colors hover:bg-primary/30 hover:ring-2 hover:ring-primary/30 dark:hover:bg-primary/40 dark:hover:ring-primary/40"
            :aria-label="'{{ __('Clear :filter filter', ['filter' => '__PLACEHOLDER__']) }}'.replace('__PLACEHOLDER__', pillLabels.taskPriority)"
                @click.stop="clearFilter('taskPriority')"
            >
                <flux:icon name="x-mark" class="size-3" />
            </button>
    </span>

    {{-- Task complexity pill - clickable dropdown with X to clear --}}
    <span
        x-show="showValue('taskComplexity')"
        style="{{ ($filters['taskComplexity'] ?? null) ? '' : 'display:none' }}"
        class="inline-flex items-center gap-1.5 rounded-full border border-primary/30 bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-foreground shadow-none dark:border-primary/40 dark:bg-primary/20 dark:text-foreground"
    >
            <flux:dropdown position="bottom" align="start">
                <flux:button
                    variant="ghost"
                    size="xs"
                    icon:trailing="chevron-down"
                    class="min-w-0 border-0 bg-transparent px-0 shadow-none ring-0 hover:bg-transparent dark:hover:bg-transparent"
                >
                    <span x-text="pillLabels.taskComplexity + ': ' + showValue('taskComplexity')">{{ $pillLabels['taskComplexity'] }}: {{ ($filters['taskComplexity'] ?? null) ? ($taskComplexities[$filters['taskComplexity']] ?? '') : '' }}</span>
                </flux:button>
                <flux:menu class="min-w-[8rem]">
                    <flux:menu.radio.group wire:model.change.live="filterTaskComplexity">
                        @foreach ($taskComplexities as $value => $label)
                            <flux:menu.radio value="{{ $value }}" @click="displayFilters.taskComplexity = '{{ $value }}'; window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'taskComplexity', value: '{{ $value }}' } }))">{{ $label }}</flux:menu.radio>
                        @endforeach
                    </flux:menu.radio.group>
                </flux:menu>
            </flux:dropdown>
            <button
                type="button"
                class="shrink-0 rounded-full p-0.5 transition-colors hover:bg-primary/30 hover:ring-2 hover:ring-primary/30 dark:hover:bg-primary/40 dark:hover:ring-primary/40"
                :aria-label="'{{ __('Clear :filter filter', ['filter' => '__PLACEHOLDER__']) }}'.replace('__PLACEHOLDER__', pillLabels.taskComplexity)"
                @click.stop="clearFilter('taskComplexity')"
            >
                <flux:icon name="x-mark" class="size-3" />
            </button>
    </span>

    {{-- Event status pill - clickable dropdown with X to clear --}}
    <span
        x-show="showValue('eventStatus')"
        style="{{ ($filters['eventStatus'] ?? null) ? '' : 'display:none' }}"
        class="inline-flex items-center gap-1.5 rounded-full border border-primary/30 bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-foreground shadow-none dark:border-primary/40 dark:bg-primary/20 dark:text-foreground"
    >
            <flux:dropdown position="bottom" align="start">
                <flux:button
                    variant="ghost"
                    size="xs"
                    icon:trailing="chevron-down"
                    class="min-w-0 border-0 bg-transparent px-0 shadow-none ring-0 hover:bg-transparent dark:hover:bg-transparent"
                >
                    <span x-text="pillLabels.eventStatus + ': ' + showValue('eventStatus')">{{ $pillLabels['eventStatus'] }}: {{ ($filters['eventStatus'] ?? null) ? ($eventStatuses[$filters['eventStatus']] ?? '') : '' }}</span>
                </flux:button>
                <flux:menu class="min-w-[8rem]">
                    <flux:menu.radio.group wire:model.change.live="filterEventStatus">
                        @foreach ($eventStatuses as $value => $label)
                            <flux:menu.radio value="{{ $value }}" @click="displayFilters.eventStatus = '{{ $value }}'; window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'eventStatus', value: '{{ $value }}' } }))">{{ $label }}</flux:menu.radio>
                        @endforeach
                    </flux:menu.radio.group>
                </flux:menu>
            </flux:dropdown>
            <button
                type="button"
                class="shrink-0 rounded-full p-0.5 transition-colors hover:bg-primary/30 hover:ring-2 hover:ring-primary/30 dark:hover:bg-primary/40 dark:hover:ring-primary/40"
                :aria-label="'{{ __('Clear :filter filter', ['filter' => '__PLACEHOLDER__']) }}'.replace('__PLACEHOLDER__', pillLabels.eventStatus)"
                @click.stop="clearFilter('eventStatus')"
            >
                <flux:icon name="x-mark" class="size-3" />
            </button>
    </span>

    {{-- Tags pill - clickable dropdown with X to clear --}}
    @if ($tags->isNotEmpty())
        <span
            x-show="showValue('tagIds')"
            style="{{ $tagDisplay ? '' : 'display:none' }}"
            class="inline-flex items-center gap-1.5 rounded-full border border-primary/30 bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-foreground shadow-none dark:border-primary/40 dark:bg-primary/20 dark:text-foreground"
        >
                <flux:dropdown position="bottom" align="start">
                    <flux:button
                        variant="ghost"
                        size="xs"
                        icon:trailing="chevron-down"
                        class="min-w-0 border-0 bg-transparent px-0 shadow-none ring-0 hover:bg-transparent dark:hover:bg-transparent"
                    >
                        <span x-text="pillLabels.tagIds + ': ' + showValue('tagIds')">{{ $pillLabels['tagIds'] }}: {{ $tagDisplay }}</span>
                    </flux:button>
                    <flux:menu class="min-w-[8rem]">
                        <flux:menu.radio.group wire:model="filterTagId">
                            @foreach ($tags as $tag)
                                <flux:menu.radio value="{{ $tag->id }}" wire:click="setTagFilter({{ $tag->id }})" @click="displayFilters.tagIds = [{{ $tag->id }}]; window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'tagIds', value: [{{ $tag->id }}] } }))">{{ $tag->name }}</flux:menu.radio>
                            @endforeach
                        </flux:menu.radio.group>
                    </flux:menu>
                </flux:dropdown>
                <button
                    type="button"
                    class="shrink-0 rounded-full p-0.5 transition-colors hover:bg-primary/30 hover:ring-2 hover:ring-primary/30 dark:hover:bg-primary/40 dark:hover:ring-primary/40"
                    :aria-label="'{{ __('Clear :filter filter', ['filter' => '__PLACEHOLDER__']) }}'.replace('__PLACEHOLDER__', pillLabels.tagIds)"
                    @click.stop="clearFilter('tagIds')"
            >
                <flux:icon name="x-mark" class="size-3" />
            </button>
        </span>
    @endif

    {{-- Recurring pill - clickable dropdown with X to clear --}}
    <span
        x-show="showValue('recurring')"
        style="{{ ($filters['recurring'] ?? null) ? '' : 'display:none' }}"
        class="inline-flex items-center gap-1.5 rounded-full border border-primary/30 bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-foreground shadow-none dark:border-primary/40 dark:bg-primary/20 dark:text-foreground"
    >
            <flux:dropdown position="bottom" align="start">
                <flux:button
                    variant="ghost"
                    size="xs"
                    icon:trailing="chevron-down"
                    class="min-w-0 border-0 bg-transparent px-0 shadow-none ring-0 hover:bg-transparent dark:hover:bg-transparent"
                >
                    <span x-text="pillLabels.recurring + ': ' + showValue('recurring')">{{ $pillLabels['recurring'] }}: {{ ($filters['recurring'] ?? null) ? ($recurringLabels[$filters['recurring']] ?? '') : '' }}</span>
                </flux:button>
                <flux:menu class="min-w-[8rem]">
                    <flux:menu.radio.group wire:model.change.live="filterRecurring">
                        <flux:menu.radio value="recurring" @click="displayFilters.recurring = 'recurring'; window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'recurring', value: 'recurring' } }))">{{ __('Recurring') }}</flux:menu.radio>
                        <flux:menu.radio value="oneTime" @click="displayFilters.recurring = 'oneTime'; window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'recurring', value: 'oneTime' } }))">{{ __('One-time') }}</flux:menu.radio>
                    </flux:menu.radio.group>
                </flux:menu>
            </flux:dropdown>
            <button
                type="button"
                class="shrink-0 rounded-full p-0.5 transition-colors hover:bg-primary/30 hover:ring-2 hover:ring-primary/30 dark:hover:bg-primary/40 dark:hover:ring-primary/40"
                :aria-label="'{{ __('Clear :filter filter', ['filter' => '__PLACEHOLDER__']) }}'.replace('__PLACEHOLDER__', pillLabels.recurring)"
                @click.stop="clearFilter('recurring')"
            >
                <flux:icon name="x-mark" class="size-3" />
            </button>
    </span>

    <button
        x-show="hasOtherActiveFilters()"
        style="{{ $hasOtherActiveFilters ? '' : 'display:none' }}"
        type="button"
        @click="clearAllOptimistic()"
        class="text-xs font-medium text-muted-foreground transition-colors hover:text-foreground"
    >
        {{ __('Clear all') }}
    </button>
</div>

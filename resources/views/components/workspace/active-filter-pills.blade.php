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
            const handler = (e) => {
                const { key, value } = e.detail || {};
                if (key === 'clearAll') {
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
            };
            window.addEventListener('filter-optimistic', handler);
            this.$cleanup(() => window.removeEventListener('filter-optimistic', handler));
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

    {{-- Other filter pills (rendered from Alpine state for optimistic updates) --}}
    <template x-for="(key, index) in ['taskStatus','taskPriority','taskComplexity','eventStatus','tagIds','recurring']" :key="key">
        <template x-if="showValue(key)">
            <span
                class="inline-flex items-center gap-1.5 rounded-full border border-primary/30 bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-foreground dark:border-primary/40 dark:bg-primary/20 dark:text-foreground"
            >
                <span x-text="pillLabels[key] + ': ' + showValue(key)"></span>
                <button
                    type="button"
                    class="shrink-0 rounded-full p-0.5 transition-colors hover:bg-primary/20 dark:hover:bg-primary/30"
                    :aria-label="'{{ __('Clear :filter filter', ['filter' => '__PLACEHOLDER__']) }}'.replace('__PLACEHOLDER__', pillLabels[key])"
                    @click="clearFilter(key)"
                >
                    <flux:icon name="x-mark" class="size-3" />
                </button>
            </span>
        </template>
    </template>

    <template x-if="hasActiveFilters()">
        <button
            type="button"
            @click="clearAllOptimistic()"
            class="text-xs font-medium text-muted-foreground transition-colors hover:text-foreground"
        >
            {{ __('Clear all') }}
        </button>
    </template>
</div>

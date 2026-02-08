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
    $currentItemType = $filters['itemType'] ?? null;
    $itemTypeLabels = [
        'tasks' => __('Tasks'),
        'events' => __('Events'),
        'projects' => __('Projects'),
    ];
    $currentTaskStatus = $filters['taskStatus'] ?? null;
    $currentTaskPriority = $filters['taskPriority'] ?? null;
    $currentTaskComplexity = $filters['taskComplexity'] ?? null;
    $currentEventStatus = $filters['eventStatus'] ?? null;
    $currentTagIds = $filters['tagIds'] ?? [];
    $currentRecurring = $filters['recurring'] ?? null;
    $hasActiveFilters = $filters['hasActiveFilters'] ?? false;

    $recurringLabels = [
        'recurring' => __('Recurring'),
        'oneTime' => __('One-time'),
    ];

    $tags = $tags instanceof \Illuminate\Support\Collection ? $tags : collect($tags);

    $selectedTags = $tags->filter(fn ($t) => in_array($t->id, $currentTagIds, true));

    $activeFilters = [];
    if ($currentItemType) {
        $activeFilters[] = ['key' => 'itemType', 'label' => __('Show'), 'value' => $itemTypeLabels[$currentItemType] ?? $currentItemType];
    }
    if ($currentTaskStatus) {
        $activeFilters[] = ['key' => 'taskStatus', 'label' => __('Task status'), 'value' => $taskStatuses[$currentTaskStatus] ?? $currentTaskStatus];
    }
    if ($currentTaskPriority) {
        $activeFilters[] = ['key' => 'taskPriority', 'label' => __('Task priority'), 'value' => $taskPriorities[$currentTaskPriority] ?? $currentTaskPriority];
    }
    if ($currentTaskComplexity) {
        $activeFilters[] = ['key' => 'taskComplexity', 'label' => __('Task complexity'), 'value' => $taskComplexities[$currentTaskComplexity] ?? $currentTaskComplexity];
    }
    if ($currentEventStatus) {
        $activeFilters[] = ['key' => 'eventStatus', 'label' => __('Event status'), 'value' => $eventStatuses[$currentEventStatus] ?? $currentEventStatus];
    }
    if (! empty($currentTagIds) && $selectedTags->isNotEmpty()) {
        $activeFilters[] = ['key' => 'tagIds', 'label' => __('Tags'), 'value' => $selectedTags->pluck('name')->join(', ')];
    }
    if ($currentRecurring) {
        $activeFilters[] = ['key' => 'recurring', 'label' => __('Recurring'), 'value' => $recurringLabels[$currentRecurring] ?? $currentRecurring];
    }
@endphp

<div class="flex flex-wrap items-center gap-2">
    {{-- Single Filter dropdown --}}
    <flux:dropdown position="bottom" align="start">
        <flux:button
            variant="outline"
            size="sm"
            icon="funnel"
            icon:trailing="chevron-down"
            class="{{ $hasActiveFilters ? 'ring-2 ring-primary' : '' }}"
        >
            {{ __('Filter') }}
        </flux:button>
        <flux:menu keep-open class="min-w-[12rem] max-h-[min(70vh,32rem)] overflow-y-auto">
            {{-- Show --}}
            <flux:menu.submenu heading="{{ __('Show') }}" keep-open>
                <flux:menu.radio.group wire:model="filterItemType" keep-open>
                    <flux:menu.radio value="" wire:click="setFilter('itemType', null)">{{ __('All') }}</flux:menu.radio>
                    <flux:menu.radio value="tasks" wire:click="setFilter('itemType', 'tasks')">{{ __('Tasks') }}</flux:menu.radio>
                    <flux:menu.radio value="events" wire:click="setFilter('itemType', 'events')">{{ __('Events') }}</flux:menu.radio>
                    <flux:menu.radio value="projects" wire:click="setFilter('itemType', 'projects')">{{ __('Projects') }}</flux:menu.radio>
                </flux:menu.radio.group>
            </flux:menu.submenu>

            <flux:menu.separator />

            {{-- Task status --}}
            <flux:menu.submenu heading="{{ __('Task status') }}" keep-open>
                <flux:menu.radio.group wire:model="filterTaskStatus" keep-open>
                    <flux:menu.radio value="" wire:click="clearFilter('taskStatus')">{{ __('All') }}</flux:menu.radio>
                    @foreach ($taskStatuses as $value => $label)
                        <flux:menu.radio value="{{ $value }}" wire:click="setFilter('taskStatus', '{{ $value }}')">{{ $label }}</flux:menu.radio>
                    @endforeach
                </flux:menu.radio.group>
            </flux:menu.submenu>

            {{-- Task priority --}}
            <flux:menu.submenu heading="{{ __('Task priority') }}" keep-open>
                <flux:menu.radio.group wire:model="filterTaskPriority" keep-open>
                    <flux:menu.radio value="" wire:click="clearFilter('taskPriority')">{{ __('All') }}</flux:menu.radio>
                    @foreach ($taskPriorities as $value => $label)
                        <flux:menu.radio value="{{ $value }}" wire:click="setFilter('taskPriority', '{{ $value }}')">{{ $label }}</flux:menu.radio>
                    @endforeach
                </flux:menu.radio.group>
            </flux:menu.submenu>

            {{-- Task complexity --}}
            <flux:menu.submenu heading="{{ __('Task complexity') }}" keep-open>
                <flux:menu.radio.group wire:model="filterTaskComplexity" keep-open>
                    <flux:menu.radio value="" wire:click="clearFilter('taskComplexity')">{{ __('All') }}</flux:menu.radio>
                    @foreach ($taskComplexities as $value => $label)
                        <flux:menu.radio value="{{ $value }}" wire:click="setFilter('taskComplexity', '{{ $value }}')">{{ $label }}</flux:menu.radio>
                    @endforeach
                </flux:menu.radio.group>
            </flux:menu.submenu>

            <flux:menu.separator />

            {{-- Event status --}}
            <flux:menu.submenu heading="{{ __('Event status') }}" keep-open>
                <flux:menu.radio.group wire:model="filterEventStatus" keep-open>
                    <flux:menu.radio value="" wire:click="clearFilter('eventStatus')">{{ __('All') }}</flux:menu.radio>
                    @foreach ($eventStatuses as $value => $label)
                        <flux:menu.radio value="{{ $value }}" wire:click="setFilter('eventStatus', '{{ $value }}')">{{ $label }}</flux:menu.radio>
                    @endforeach
                </flux:menu.radio.group>
            </flux:menu.submenu>

            @if ($tags->isNotEmpty())
                <flux:menu.separator />

                {{-- Tags --}}
                <flux:menu.submenu heading="{{ __('Tags') }}" keep-open>
                    <flux:menu.radio.group wire:model="filterTagId" keep-open>
                        <flux:menu.radio value="" wire:click="clearFilter('tagIds')">{{ __('All') }}</flux:menu.radio>
                        @foreach ($tags as $tag)
                            <flux:menu.radio value="{{ $tag->id }}" wire:click="setTagFilter({{ $tag->id }})">{{ $tag->name }}</flux:menu.radio>
                        @endforeach
                    </flux:menu.radio.group>
                </flux:menu.submenu>
            @endif

            <flux:menu.separator />

            {{-- Recurring --}}
            <flux:menu.submenu heading="{{ __('Recurring') }}" keep-open>
                <flux:menu.radio.group wire:model="filterRecurring" keep-open>
                    <flux:menu.radio value="" wire:click="clearFilter('recurring')">{{ __('All') }}</flux:menu.radio>
                    <flux:menu.radio value="recurring" wire:click="setFilter('recurring', 'recurring')">{{ __('Recurring') }}</flux:menu.radio>
                    <flux:menu.radio value="oneTime" wire:click="setFilter('recurring', 'oneTime')">{{ __('One-time') }}</flux:menu.radio>
                </flux:menu.radio.group>
            </flux:menu.submenu>

            @if ($hasActiveFilters)
                <flux:menu.separator />
                <flux:menu.item variant="danger" icon="x-mark" wire:click="clearAllFilters">
                    {{ __('Clear filters') }}
                </flux:menu.item>
            @endif
        </flux:menu>
    </flux:dropdown>

    {{-- Active filters display --}}
    @if ($hasActiveFilters)
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs font-medium text-muted-foreground">{{ __('Active') }}:</span>
            @foreach ($activeFilters as $filter)
                <span
                    class="inline-flex items-center gap-1.5 rounded-full border border-primary/30 bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-foreground dark:border-primary/40 dark:bg-primary/20 dark:text-foreground"
                >
                    <span>{{ $filter['label'] }}: {{ $filter['value'] }}</span>
                    <button
                        type="button"
                        class="shrink-0 rounded-full p-0.5 transition-colors hover:bg-primary/20 dark:hover:bg-primary/30"
                        aria-label="{{ __('Clear :filter filter', ['filter' => $filter['label']]) }}"
                        @click="$wire.clearFilter('{{ $filter['key'] }}')"
                    >
                        <flux:icon name="x-mark" class="size-3" />
                    </button>
                </span>
            @endforeach
        </div>
    @endif
</div>

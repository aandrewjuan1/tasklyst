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
@endphp

<div class="flex flex-wrap items-center gap-2">
    <div class="flex flex-wrap items-center gap-2">
        {{-- Item Type --}}
        <flux:dropdown position="bottom" align="start">
            <flux:button
                variant="outline"
                size="sm"
                icon:trailing="chevron-down"
                class="{{ $currentItemType ? 'ring-2 ring-primary' : '' }}"
            >
                {{ $currentItemType ? ($itemTypeLabels[$currentItemType] ?? $currentItemType) : __('Show') }}
            </flux:button>
            <flux:menu>
                <flux:menu.item @click="$wire.clearFilter('itemType')">
                    {{ __('All') }}
                </flux:menu.item>
                <flux:menu.item @click="$wire.setFilter('itemType', 'tasks')">
                    {{ __('Tasks') }}
                </flux:menu.item>
                <flux:menu.item @click="$wire.setFilter('itemType', 'events')">
                    {{ __('Events') }}
                </flux:menu.item>
                <flux:menu.item @click="$wire.setFilter('itemType', 'projects')">
                    {{ __('Projects') }}
                </flux:menu.item>
            </flux:menu>
        </flux:dropdown>

        {{-- Task Status --}}
        <flux:dropdown position="bottom" align="start">
            <flux:button
                variant="outline"
                size="sm"
                icon:trailing="chevron-down"
                class="{{ $currentTaskStatus ? 'ring-2 ring-primary' : '' }}"
            >
                {{ $currentTaskStatus ? ($taskStatuses[$currentTaskStatus] ?? $currentTaskStatus) : __('Status') }}
            </flux:button>
            <flux:menu>
                <flux:menu.item @click="$wire.clearFilter('taskStatus')">
                    {{ __('All') }}
                </flux:menu.item>
                @foreach ($taskStatuses as $value => $label)
                    <flux:menu.item @click="$wire.setFilter('taskStatus', '{{ $value }}')">
                        {{ $label }}
                    </flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>

        {{-- Task Priority --}}
        <flux:dropdown position="bottom" align="start">
            <flux:button
                variant="outline"
                size="sm"
                icon:trailing="chevron-down"
                class="{{ $currentTaskPriority ? 'ring-2 ring-primary' : '' }}"
            >
                {{ $currentTaskPriority ? ($taskPriorities[$currentTaskPriority] ?? $currentTaskPriority) : __('Priority') }}
            </flux:button>
            <flux:menu>
                <flux:menu.item @click="$wire.clearFilter('taskPriority')">
                    {{ __('All') }}
                </flux:menu.item>
                @foreach ($taskPriorities as $value => $label)
                    <flux:menu.item @click="$wire.setFilter('taskPriority', '{{ $value }}')">
                        {{ $label }}
                    </flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>

        {{-- Task Complexity --}}
        <flux:dropdown position="bottom" align="start">
            <flux:button
                variant="outline"
                size="sm"
                icon:trailing="chevron-down"
                class="{{ $currentTaskComplexity ? 'ring-2 ring-primary' : '' }}"
            >
                {{ $currentTaskComplexity ? ($taskComplexities[$currentTaskComplexity] ?? $currentTaskComplexity) : __('Complexity') }}
            </flux:button>
            <flux:menu>
                <flux:menu.item @click="$wire.clearFilter('taskComplexity')">
                    {{ __('All') }}
                </flux:menu.item>
                @foreach ($taskComplexities as $value => $label)
                    <flux:menu.item @click="$wire.setFilter('taskComplexity', '{{ $value }}')">
                        {{ $label }}
                    </flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>

        {{-- Event Status --}}
        <flux:dropdown position="bottom" align="start">
            <flux:button
                variant="outline"
                size="sm"
                icon:trailing="chevron-down"
                class="{{ $currentEventStatus ? 'ring-2 ring-primary' : '' }}"
            >
                {{ $currentEventStatus ? ($eventStatuses[$currentEventStatus] ?? $currentEventStatus) : __('Event status') }}
            </flux:button>
            <flux:menu>
                <flux:menu.item @click="$wire.clearFilter('eventStatus')">
                    {{ __('All') }}
                </flux:menu.item>
                @foreach ($eventStatuses as $value => $label)
                    <flux:menu.item @click="$wire.setFilter('eventStatus', '{{ $value }}')">
                        {{ $label }}
                    </flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>

        {{-- Tags --}}
        @if ($tags->isNotEmpty())
            <flux:dropdown position="bottom" align="start">
                <flux:button
                    variant="outline"
                    size="sm"
                    icon:trailing="chevron-down"
                    class="{{ ! empty($currentTagIds) ? 'ring-2 ring-primary' : '' }}"
                >
                    @if (! empty($currentTagIds))
                        {{ $selectedTags->pluck('name')->join(', ') }}
                    @else
                        {{ __('Tags') }}
                    @endif
                </flux:button>
                <flux:menu>
                    <flux:menu.item @click="$wire.setFilter('tagIds', null)">
                        {{ __('All') }}
                    </flux:menu.item>
                    @foreach ($tags as $tag)
                        <flux:menu.item @click="$wire.setFilter('tagIds', [{{ $tag->id }}])">
                            {{ $tag->name }}
                        </flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>
        @endif

        {{-- Recurring --}}
        <flux:dropdown position="bottom" align="start">
            <flux:button
                variant="outline"
                size="sm"
                icon:trailing="chevron-down"
                class="{{ $currentRecurring ? 'ring-2 ring-primary' : '' }}"
            >
                {{ $currentRecurring ? ($recurringLabels[$currentRecurring] ?? $currentRecurring) : __('Recurring') }}
            </flux:button>
            <flux:menu>
                <flux:menu.item @click="$wire.clearFilter('recurring')">
                    {{ __('All') }}
                </flux:menu.item>
                <flux:menu.item @click="$wire.setFilter('recurring', 'recurring')">
                    {{ __('Recurring') }}
                </flux:menu.item>
                <flux:menu.item @click="$wire.setFilter('recurring', 'oneTime')">
                    {{ __('One-time') }}
                </flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </div>

    {{-- Clear all --}}
    @if ($hasActiveFilters)
        <flux:button
            variant="ghost"
            size="sm"
            icon="x-mark"
            @click="$wire.clearAllFilters()"
        >
            {{ __('Clear filters') }}
        </flux:button>
    @endif
</div>

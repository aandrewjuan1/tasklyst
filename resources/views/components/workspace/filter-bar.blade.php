@props([
    'filters' => [],
])

@php
    use App\Enums\EventStatus;
    use App\Enums\TaskPriority;
    use App\Enums\TaskStatus;

    $taskStatuses = collect(TaskStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    $taskPriorities = collect(TaskPriority::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    $eventStatuses = collect(EventStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    $currentTaskStatus = $filters['taskStatus'] ?? null;
    $currentTaskPriority = $filters['taskPriority'] ?? null;
    $currentEventStatus = $filters['eventStatus'] ?? null;
    $hasActiveFilters = $filters['hasActiveFilters'] ?? false;
@endphp

<div class="flex flex-wrap items-center gap-2">
    <div class="flex flex-wrap items-center gap-2">
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

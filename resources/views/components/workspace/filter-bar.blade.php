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
    $hasActiveFilters = $filters['hasActiveFilters'] ?? false;

    $tags = $tags instanceof \Illuminate\Support\Collection ? $tags : collect($tags);
@endphp

<div
    x-data="{
        hasActiveFilters: @js($hasActiveFilters),
        init() {
            const handler = (e) => {
                if (e?.detail?.key === 'clearAll') {
                    this.hasActiveFilters = false;
                    return;
                }
                this.$nextTick(() => {
                    this.hasActiveFilters = !!($wire.filterItemType || $wire.filterTaskStatus || $wire.filterTaskPriority ||
                        $wire.filterTaskComplexity || $wire.filterEventStatus ||
                        ($wire.filterTagIds?.length > 0) || $wire.filterRecurring);
                });
            };
            window.addEventListener('filter-optimistic', handler);
            this.$watch('$wire.filterItemType', handler);
            this.$watch('$wire.filterTaskStatus', handler);
            this.$watch('$wire.filterTaskPriority', handler);
            this.$watch('$wire.filterTaskComplexity', handler);
            this.$watch('$wire.filterEventStatus', handler);
            this.$watch('$wire.filterTagIds', handler);
            this.$watch('$wire.filterRecurring', handler);
            this.$cleanup(() => window.removeEventListener('filter-optimistic', handler));
        },
    }"
    class="flex flex-wrap items-center gap-2"
>
    {{-- Single Filter dropdown --}}
    <flux:dropdown position="bottom" align="start">
        <flux:button
            variant="outline"
            size="sm"
            icon="funnel"
            icon:trailing="chevron-down"
            x-bind:class="hasActiveFilters ? 'ring-2 ring-primary' : ''"
        >
            {{ __('Filter') }}
        </flux:button>
        <flux:menu keep-open class="min-w-[12rem] max-h-[min(70vh,32rem)] overflow-y-auto">
            {{-- Show --}}
            <flux:menu.submenu heading="{{ __('Show') }}" keep-open>
                <flux:menu.radio.group wire:model.change.live="filterItemType" keep-open>
                    <flux:menu.radio value="" @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'itemType', value: null } }))">{{ __('All') }}</flux:menu.radio>
                    <flux:menu.radio value="tasks" @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'itemType', value: 'tasks' } }))">{{ __('Tasks') }}</flux:menu.radio>
                    <flux:menu.radio value="events" @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'itemType', value: 'events' } }))">{{ __('Events') }}</flux:menu.radio>
                    <flux:menu.radio value="projects" @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'itemType', value: 'projects' } }))">{{ __('Projects') }}</flux:menu.radio>
                </flux:menu.radio.group>
            </flux:menu.submenu>

            <flux:menu.separator />

            {{-- Task status --}}
            <flux:menu.submenu heading="{{ __('Task status') }}" keep-open>
                <flux:menu.radio.group wire:model.change.live="filterTaskStatus" keep-open>
                    <flux:menu.radio value="" @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'taskStatus', value: null } }))">{{ __('All') }}</flux:menu.radio>
                    @foreach ($taskStatuses as $value => $label)
                        <flux:menu.radio value="{{ $value }}" @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'taskStatus', value: '{{ $value }}' } }))">{{ $label }}</flux:menu.radio>
                    @endforeach
                </flux:menu.radio.group>
            </flux:menu.submenu>

            {{-- Task priority --}}
            <flux:menu.submenu heading="{{ __('Task priority') }}" keep-open>
                <flux:menu.radio.group wire:model.change.live="filterTaskPriority" keep-open>
                    <flux:menu.radio value="" @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'taskPriority', value: null } }))">{{ __('All') }}</flux:menu.radio>
                    @foreach ($taskPriorities as $value => $label)
                        <flux:menu.radio value="{{ $value }}" @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'taskPriority', value: '{{ $value }}' } }))">{{ $label }}</flux:menu.radio>
                    @endforeach
                </flux:menu.radio.group>
            </flux:menu.submenu>

            {{-- Task complexity --}}
            <flux:menu.submenu heading="{{ __('Task complexity') }}" keep-open>
                <flux:menu.radio.group wire:model.change.live="filterTaskComplexity" keep-open>
                    <flux:menu.radio value="" @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'taskComplexity', value: null } }))">{{ __('All') }}</flux:menu.radio>
                    @foreach ($taskComplexities as $value => $label)
                        <flux:menu.radio value="{{ $value }}" @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'taskComplexity', value: '{{ $value }}' } }))">{{ $label }}</flux:menu.radio>
                    @endforeach
                </flux:menu.radio.group>
            </flux:menu.submenu>

            <flux:menu.separator />

            {{-- Event status --}}
            <flux:menu.submenu heading="{{ __('Event status') }}" keep-open>
                <flux:menu.radio.group wire:model.change.live="filterEventStatus" keep-open>
                    <flux:menu.radio value="" @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'eventStatus', value: null } }))">{{ __('All') }}</flux:menu.radio>
                    @foreach ($eventStatuses as $value => $label)
                        <flux:menu.radio value="{{ $value }}" @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'eventStatus', value: '{{ $value }}' } }))">{{ $label }}</flux:menu.radio>
                    @endforeach
                </flux:menu.radio.group>
            </flux:menu.submenu>

            @if ($tags->isNotEmpty())
                <flux:menu.separator />

                {{-- Tags --}}
                <flux:menu.submenu heading="{{ __('Tags') }}" keep-open>
                    <flux:menu.radio.group wire:model="filterTagId" keep-open>
                        <flux:menu.radio value="" wire:click="clearFilter('tagIds')" @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'tagIds', value: [] } }))">{{ __('All') }}</flux:menu.radio>
                        @foreach ($tags as $tag)
                            <flux:menu.radio value="{{ $tag->id }}" wire:click="setTagFilter({{ $tag->id }})" @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'tagIds', value: [{{ $tag->id }}] } }))">{{ $tag->name }}</flux:menu.radio>
                        @endforeach
                    </flux:menu.radio.group>
                </flux:menu.submenu>
            @endif

            <flux:menu.separator />

            {{-- Recurring --}}
            <flux:menu.submenu heading="{{ __('Recurring') }}" keep-open>
                <flux:menu.radio.group wire:model.change.live="filterRecurring" keep-open>
                    <flux:menu.radio value="" @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'recurring', value: null } }))">{{ __('All') }}</flux:menu.radio>
                    <flux:menu.radio value="recurring" @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'recurring', value: 'recurring' } }))">{{ __('Recurring') }}</flux:menu.radio>
                    <flux:menu.radio value="oneTime" @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'recurring', value: 'oneTime' } }))">{{ __('One-time') }}</flux:menu.radio>
                </flux:menu.radio.group>
            </flux:menu.submenu>

            <template x-if="hasActiveFilters">
                <flux:menu.separator />
            </template>
            <template x-if="hasActiveFilters">
                <flux:menu.item variant="danger" icon="x-mark" wire:click="clearAllFilters" @click="window.dispatchEvent(new CustomEvent('filter-optimistic', { detail: { key: 'clearAll' } })); hasActiveFilters = false">
                    {{ __('Clear filters') }}
                </flux:menu.item>
            </template>
        </flux:menu>
    </flux:dropdown>
</div>

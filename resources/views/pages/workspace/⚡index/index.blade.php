<section class="space-y-6" x-data x-on:list-refresh-requested.window="$wire.incrementListRefresh()">
    <div class="flex items-center justify-between">
        <div class="space-y-2">
            <flux:heading size="lg">
                {{ __('Workspace') }}
            </flux:heading>
            <flux:subheading>
                {{ __('Your tasks, projects, and events') }}
            </flux:subheading>

            <x-workspace.date-switcher :selected-date="$this->selectedDate" />
        </div>
    </div>

    <x-workspace.filter-bar
        :filters="$this->getFilters()"
        :tags="$this->tags"
    />

    @php
        $listLoadingTargets = 'selectedDate,filterItemType,filterTaskStatus,filterTaskPriority,filterTaskComplexity,filterEventStatus,filterTagId,filterRecurring,setFilter,clearFilter,setTagFilter,clearAllFilters';
    @endphp

    {{-- Real list - hidden during filter/date refresh --}}
    <div
        wire:loading.delay.remove
        wire:target="{{ $listLoadingTargets }}"
        class="w-full"
    >
        <livewire:pages::workspace.list
            :key="'workspace-list-'.$this->selectedDate.'-'.$this->listRefresh"
            :selected-date="$this->selectedDate"
            :projects="$this->projects"
            :events="$this->events"
            :tasks="$this->tasks"
            :overdue="$this->overdue"
            :tags="$this->tags"
            :filters="$this->getFilters()"
        />
    </div>

    {{-- Skeleton placeholder - shown during filter/date refresh --}}
    <div
        wire:loading.delay.block
        wire:target="{{ $listLoadingTargets }}"
        class="hidden w-full space-y-4"
    >
        @foreach (range(1, 3) as $i)
            <flux:skeleton.group animate="shimmer" class="flex flex-col gap-3 rounded-xl border border-border/60 bg-background/60 px-4 py-3">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex-1 space-y-2">
                        <flux:skeleton.line class="w-3/4" />
                        <flux:skeleton.line class="w-1/2" />
                    </div>
                    <flux:skeleton class="size-8 shrink-0 rounded" />
                </div>
                <div class="flex flex-wrap gap-2">
                    <flux:skeleton class="h-6 w-20 rounded-full" />
                    <flux:skeleton class="h-6 w-24 rounded-full" />
                    <flux:skeleton class="h-6 w-16 rounded-full" />
                </div>
                <div class="flex flex-wrap gap-2 pt-1">
                    <flux:skeleton.line class="w-1/4" />
                    <flux:skeleton.line class="w-1/3" />
                </div>
            </flux:skeleton.group>
        @endforeach
    </div>
</section>

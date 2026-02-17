<section
    class="space-y-6"
    x-data="{ get focusModeActive() { const s = Alpine.store('focusSession'); return !!(s?.session || s?.focusReady); } }"
    x-init="Alpine.store('focusSession', Alpine.store('focusSession') ?? { session: @js($this->activeFocusSession), focusReady: false })"
    @focus-session-updated.window="Alpine.store('focusSession', { ...Alpine.store('focusSession'), session: $event.detail?.session ?? $event.detail?.[0] ?? null, focusReady: false })"
>
    {{-- Centered date switcher on its own row (dim/disable when focus mode) --}}
    <div class="flex w-full justify-center transition-opacity duration-200 ease-out" :class="{ 'pointer-events-none select-none opacity-60': focusModeActive }">
        <x-workspace.date-switcher :selected-date="$this->selectedDate" />
    </div>

    {{-- Filters / pending invitations / add filter / trash (dim/disable when focus mode) --}}
    <div class="flex flex-wrap items-center justify-between gap-2 transition-opacity duration-200 ease-out" :class="{ 'pointer-events-none select-none opacity-60': focusModeActive }">
        <div class="flex flex-wrap items-center gap-2">
            @auth
                <x-workspace.pending-invitations-popover :invitations="$this->pendingInvitationsForUser" />
            @endauth
            <x-workspace.active-filter-pills
                :filters="$this->getFilters()"
                :tags="$this->tags"
            />
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-workspace.trash-popover />
            <x-workspace.filter-bar
                :filters="$this->getFilters()"
                :tags="$this->tags"
            />
        </div>
    </div>

    @php
        $listLoadingTargets = 'selectedDate,filterItemType,filterTaskStatus,filterTaskPriority,filterTaskComplexity,filterEventStatus,filterTagId,filterRecurring,setFilter,clearFilter,setTagFilter,clearAllFilters,acceptCollaborationInvitation,restoreTrashItem,restoreTrashItems';
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
            :active-focus-session="$this->activeFocusSession"
            :pomodoro-settings="$this->pomodoroSettings"
        />
    </div>

    {{-- Skeleton placeholder - shown during filter/date refresh --}}
    <div
        wire:loading.delay.block
        wire:target="{{ $listLoadingTargets }}"
        class="hidden w-full space-y-4"
        role="status"
        aria-busy="true"
        aria-live="polite"
        aria-label="{{ __('Loading workspace list...') }}"
        x-data="{
            skeletonItems: [0, 1, 2],
            _resizeCleanup: null,
            init() {
                const updateCount = () => {
                    const itemHeight = 120;
                    const minCount = 3;
                    const maxCount = 10;
                    const viewportHeight = window.innerHeight;
                    const availableHeight = viewportHeight - 320;
                    const count = Math.min(maxCount, Math.max(minCount, Math.ceil(availableHeight / itemHeight)));
                    this.skeletonItems = Array.from({ length: count }, (_, i) => i);
                };
                updateCount();
                window.addEventListener('resize', updateCount);
                this._resizeCleanup = () => window.removeEventListener('resize', updateCount);
            },
            destroy() {
                this._resizeCleanup?.();
            },
        }"
    >
        <span class="sr-only">{{ __('Loading workspace list...') }}</span>
        <template x-for="i in skeletonItems" :key="i">
            <div>
                <flux:skeleton.group animate="shimmer" class="flex flex-col gap-2 rounded-xl border border-border/60 bg-background/60 px-3 py-2 shadow-sm backdrop-blur">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0 flex-1 space-y-2">
                            <flux:skeleton.line class="w-4/5" size="lg" />
                            <flux:skeleton.line class="w-2/3" />
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <flux:skeleton class="h-6 w-14 rounded-full" />
                            <flux:skeleton class="size-8 shrink-0 rounded" />
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 pt-0.5">
                        <flux:skeleton class="h-5 w-16 rounded-full" />
                        <flux:skeleton class="h-5 w-20 rounded-full" />
                        <flux:skeleton class="h-5 w-14 rounded-full" />
                    </div>
                </flux:skeleton.group>
            </div>
        </template>
    </div>
</section>

<section
    class="space-y-6"
    x-data="{}"
    x-init="Alpine.store('focusSession', Alpine.store('focusSession') ?? { session: @js($this->activeFocusSession), focusReady: false })"
    @focus-session-updated.window="Alpine.store('focusSession', { ...Alpine.store('focusSession'), session: $event.detail?.session ?? $event.detail?.[0] ?? null, focusReady: false })"
>
    {{--
        wire:loading targets for the main list/kanban skeleton. Match any Livewire property or method
        that should show the full-area placeholder while the workspace Index re-renders.

        Covered: date (selectedDate), search (searchQuery, searchScope), filters (filter* and set/clear helpers),
        collaboration accept, trash restore.

        Intentionally omitted: viewMode (tab switch stays snappy), loadMoreItems / getMoreItemsHtml (append-only, no full flash).
    --}}
    @php
        $listLoadingTargets = 'selectedDate,searchQuery,searchScope,filterItemType,filterTaskStatus,filterTaskPriority,filterTaskComplexity,filterEventStatus,filterTagId,filterRecurring,setFilter,clearFilter,setTagFilter,clearAllFilters,acceptCollaborationInvitation,restoreTrashItem,restoreTrashItems';
    @endphp

    {{-- Main Content: 80/20 Split Layout --}}
    <div class="grid w-full gap-6 lg:grid-cols-[minmax(0,4fr)_minmax(260px,1fr)]">
        {{-- Left Side: List (80%) --}}
        <div class="relative min-w-0 space-y-6">
            {{-- Hero Navigation Card --}}
            <div
                class="rounded-2xl border border-brand-blue/20 bg-linear-to-r from-brand-blue/12 via-brand-purple/8 to-brand-green/12 p-4 shadow-sm ring-1 ring-brand-purple/12 backdrop-blur sm:p-5"
            >
                <div
                    x-data="{
                        pendingViewMode: null,
                        isSwitching: false,
                        switchTimeoutId: null,
                        activeViewMode() {
                            return this.pendingViewMode ?? $wire.viewMode;
                        },
                        clearSwitchingState() {
                            this.pendingViewMode = null;
                            this.isSwitching = false;
                            if (this.switchTimeoutId !== null) {
                                clearTimeout(this.switchTimeoutId);
                                this.switchTimeoutId = null;
                            }
                        },
                        setSwitchFallbackTimeout() {
                            if (this.switchTimeoutId !== null) {
                                clearTimeout(this.switchTimeoutId);
                            }

                            this.switchTimeoutId = setTimeout(() => {
                                this.clearSwitchingState();
                            }, 6000);
                        },
                        setView(mode) {
                            if (mode === this.activeViewMode()) {
                                return;
                            }

                            this.pendingViewMode = mode;
                            this.isSwitching = true;
                            this.setSwitchFallbackTimeout();

                            $wire.set('viewMode', mode);
                            const u = new URL(window.location.href);
                            u.searchParams.set('view', mode);
                            history.replaceState(null, '', u.pathname + u.search);
                            if (window.Alpine?.store) {
                                let store = Alpine.store('workspaceView');
                                if (!store || typeof store !== 'object') {
                                    Alpine.store('workspaceView', { mode });
                                } else {
                                    store.mode = mode;
                                }
                            }
                        },
                    }"
                    x-init="
                        if (window.Alpine?.store) {
                            let store = Alpine.store('workspaceView');
                            const initialMode = $wire.viewMode;
                            if (!store || typeof store !== 'object') {
                                Alpine.store('workspaceView', { mode: initialMode });
                            } else {
                                store.mode = initialMode;
                            }
                        }
                    "
                    x-effect="
                        if (pendingViewMode !== null && $wire.viewMode === pendingViewMode) {
                            clearSwitchingState();
                        }
                    "
                    class="flex w-full flex-col gap-4"
                >
                    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(24rem,34rem)] xl:items-end">
                        <div class="space-y-1">
                            <h1 class="text-2xl font-semibold tracking-tight text-foreground sm:text-3xl">
                                {{ __('Workspace') }}
                            </h1>
                            <p class="text-sm text-muted-foreground">
                                {{ __('Plan and manage your day') }}
                            </p>
                        </div>

                        <div class="flex w-full flex-col gap-2">
                            <div class="flex w-full min-w-0 items-center gap-2">
                                <flux:input
                                    type="search"
                                    wire:model.live.debounce.300ms="searchQuery"
                                    :loading="false"
                                    placeholder="{{ __('Search tasks, events, projects…') }}"
                                    aria-label="{{ __('Search tasks, events, and projects') }}"
                                    autocomplete="off"
                                    class="w-full min-w-0"
                                />
                                <flux:tooltip
                                    :content="$this->searchScope === 'selected_date'
                                        ? __('Currently searching selected date only. (Click to search all items.)')
                                        : __('Currently searching all items. (Click to search selected date only.)')"
                                >
                                    <flux:button
                                        type="button"
                                        variant="ghost"
                                        size="xs"
                                        :loading="false"
                                        :icon="$this->searchScope === 'selected_date' ? 'calendar-days' : 'globe-alt'"
                                        aria-label="{{ __('Toggle search scope') }}"
                                        class="size-8 shrink-0"
                                        wire:click="$wire.set('searchScope', $wire.searchScope === 'selected_date' ? 'all_items' : 'selected_date')"
                                        wire:loading.attr="disabled"
                                        wire:target="searchScope"
                                    />
                                </flux:tooltip>
                                <div class="shrink-0 self-end">
                                    <x-workspace.date-switcher :selected-date="$this->selectedDate" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-brand-blue/20 pt-3">
                        <div class="flex justify-center xl:justify-start">
                            <div class="flex rounded-lg border border-border/60 bg-background/55 p-0.5 shadow-xs" role="tablist" aria-label="{{ __('Workspace view') }}">
                                <button
                                    type="button"
                                    role="tab"
                                    :aria-selected="activeViewMode() === 'list'"
                                    aria-controls="workspace-list-panel"
                                    id="workspace-view-list"
                                    class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
                                    :class="activeViewMode() === 'list' ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                                    @click="setView('list')"
                                >
                                    {{ __('List') }}
                                </button>
                                <button
                                    type="button"
                                    role="tab"
                                    :aria-selected="activeViewMode() === 'kanban'"
                                    aria-controls="workspace-kanban-panel"
                                    id="workspace-view-kanban"
                                    class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
                                    :class="activeViewMode() === 'kanban' ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                                    @click="setView('kanban')"
                                >
                                    {{ __('Kanban') }}
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- Search, filters / pending invitations / add filter / trash --}}
                    <div class="flex flex-col gap-3 border-t border-brand-blue/20 pt-3 xl:flex-row xl:items-start xl:justify-between">
                        <div class="min-w-0 flex flex-wrap items-center gap-2">
                            @auth
                                <x-workspace.pending-invitations-popover :invitations="$this->pendingInvitationsForUser" />
                            @endauth
                            <x-workspace.active-filter-pills
                                :filters="$this->getFilters()"
                                :tags="$this->tags"
                            />
                        </div>

                        <div class="flex flex-wrap items-center gap-2 xl:justify-end">
                            <x-workspace.trash-popover />
                            <x-workspace.filter-bar
                                :filters="$this->getFilters()"
                                :tags="$this->tags"
                            />
                            @auth
                                <flux:modal.trigger name="task-assistant-chat">
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="chat-bubble-left-right"
                                        class="shrink-0"
                                        aria-label="{{ __('Open task assistant') }}"
                                    >
                                        {{ __('Assistant') }}
                                    </flux:button>
                                </flux:modal.trigger>
                            @endauth
                        </div>
                    </div>
                </div>
            </div>

            {{-- Real content - hidden during filter/date/view refresh --}}
            <div
                wire:loading.remove
                wire:target="{{ $listLoadingTargets }}"
                class="w-full"
            >
                <div
                    id="workspace-list-panel"
                    role="tabpanel"
                    aria-labelledby="workspace-view-list"
                    class="w-full"
                    style="{{ $viewMode !== 'list' ? 'display: none' : '' }}"
                    x-show="$wire.viewMode === 'list'"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                >
                    @if ($this->viewMode === 'list')
                        <livewire:pages::workspace.list
                            wire:key="workspace-list-{{ $this->workspaceItemsFingerprint() }}"
                            :selected-date="$this->selectedDate"
                            :items-page="$this->itemsPage"
                            :items-per-page="$this->itemsPerPage"
                            :projects="$this->projects"
                            :events="$this->events"
                            :tasks="$this->tasks"
                            :overdue="$this->overdue"
                            :tags="$this->tags"
                            :filters="$this->getFilters()"
                            :active-focus-session="$this->activeFocusSession"
                            :pomodoro-settings="$this->pomodoroSettings"
                            :has-more-items="($this->hasMoreTasks ?? false) || ($this->hasMoreEvents ?? false) || ($this->hasMoreProjects ?? false)"
                        />
                    @endif
                </div>
                <div
                    id="workspace-kanban-panel"
                    role="tabpanel"
                    aria-labelledby="workspace-view-kanban"
                    class="w-full"
                    style="{{ $viewMode !== 'kanban' ? 'display: none' : '' }}"
                    x-show="$wire.viewMode === 'kanban'"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                >
                    @if ($this->viewMode === 'kanban')
                        <livewire:pages::workspace.kanban
                            wire:key="workspace-kanban-{{ $this->workspaceItemsFingerprint() }}"
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
                    @endif
                </div>
            </div>

            {{-- Skeleton placeholder - shown during filter/date refresh --}}
            <div
                wire:loading.block
                wire:target="{{ $listLoadingTargets }}"
                class="hidden w-full space-y-4"
                role="status"
                aria-busy="true"
                aria-live="polite"
                aria-label="{{ __('Loading workspace') }}"
                x-data="{
                    skeletonItems: [0, 1, 2],
                    _resizeCleanup: null,
                    init() {
                        const updateCount = () => {
                            const itemHeight = 120;
                            const minCount = 6;
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
                <span
                    class="sr-only"
                    x-text="$wire.viewMode === 'kanban' ? '{{ __('Loading workspace kanban...') }}' : '{{ __('Loading workspace list...') }}'"
                ></span>

                {{-- List view skeleton --}}
                <template x-if="$wire.viewMode === 'list'">
                    <div class="space-y-4">
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
                </template>

                {{-- Kanban view skeleton --}}
                <template x-if="$wire.viewMode === 'kanban'">
                    <div class="w-full min-w-0">
                        <div class="grid min-h-[50vh] w-full gap-3 sm:gap-4 md:grid-cols-3" style="min-width: min-content;">
                            <template x-for="col in [0, 1, 2]" :key="col">
                                <div class="flex w-full flex-col rounded-xl border border-border/60 bg-muted/30 shadow-sm">
                                    <div class="flex items-center justify-between gap-2 border-b border-border/60 px-3 py-2">
                                        <flux:skeleton.line class="w-1/2" />
                                        <flux:skeleton class="h-5 w-10 rounded-full" />
                                    </div>
                                    <div class="flex min-h-[140px] flex-1 flex-col gap-2.5 overflow-visible p-2.5 sm:min-h-[160px] sm:gap-3 sm:p-3">
                                        <template x-for="card in [0, 1, 2, 3, 4, 5]" :key="card">
                                            <flux:skeleton.group animate="shimmer" class="flex flex-col gap-2 rounded-xl border border-border/60 bg-background/60 px-2.5 py-1.5 shadow-sm backdrop-blur">
                                                <div class="flex items-start justify-between gap-2">
                                                    <div class="min-w-0 flex-1 space-y-2">
                                                        <flux:skeleton.line class="w-4/5" />
                                                        <flux:skeleton.line class="w-3/5" />
                                                    </div>
                                                    <div class="flex shrink-0 items-center gap-2">
                                                        <flux:skeleton class="h-5 w-12 rounded-full" />
                                                    </div>
                                                </div>
                                                <div class="flex flex-wrap items-center gap-1.5 pt-0.5">
                                                    <flux:skeleton class="h-4 w-12 rounded-full" />
                                                    <flux:skeleton class="h-4 w-10 rounded-full" />
                                                </div>
                                            </flux:skeleton.group>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            {{-- View switch overlay skeleton (keeps current content visible underneath) --}}
            <div
                x-cloak
                x-show="isSwitching"
                x-transition.opacity.duration.150ms
                wire:loading.flex
                wire:target="viewMode"
                class="pointer-events-none absolute inset-0 z-20 hidden flex-col gap-3 rounded-xl border border-border/60 bg-background/75 p-3 backdrop-blur-sm"
                role="status"
                aria-live="polite"
            >
                <template x-if="activeViewMode() === 'list'">
                    <div class="space-y-3">
                        <template x-for="i in [0, 1, 2]" :key="`switch-list-${i}`">
                            <flux:skeleton.group animate="shimmer" class="flex flex-col gap-2 rounded-xl border border-border/60 bg-background/60 px-3 py-2 shadow-sm">
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
                        </template>
                    </div>
                </template>
                <template x-if="activeViewMode() === 'kanban'">
                    <div class="w-full min-w-0">
                        <div class="grid min-h-[50vh] w-full gap-3 sm:gap-4 md:grid-cols-3">
                            <template x-for="col in [0, 1, 2]" :key="`switch-kanban-col-${col}`">
                                <div class="flex w-full flex-col rounded-xl border border-border/60 bg-muted/30 shadow-sm">
                                    <div class="flex items-center justify-between gap-2 border-b border-border/60 px-3 py-2">
                                        <flux:skeleton.line class="w-1/2" />
                                        <flux:skeleton class="h-5 w-10 rounded-full" />
                                    </div>
                                    <div class="flex min-h-[140px] flex-1 flex-col gap-2.5 overflow-visible p-2.5 sm:min-h-[160px] sm:gap-3 sm:p-3">
                                        <template x-for="card in [0, 1, 2, 3, 4]" :key="`switch-kanban-card-${col}-${card}`">
                                            <flux:skeleton.group animate="shimmer" class="flex flex-col gap-2 rounded-xl border border-border/60 bg-background/60 px-2.5 py-1.5 shadow-sm">
                                                <div class="flex items-start justify-between gap-2">
                                                    <div class="min-w-0 flex-1 space-y-2">
                                                        <flux:skeleton.line class="w-4/5" />
                                                        <flux:skeleton.line class="w-3/5" />
                                                    </div>
                                                    <div class="flex shrink-0 items-center gap-2">
                                                        <flux:skeleton class="h-5 w-12 rounded-full" />
                                                    </div>
                                                </div>
                                                <div class="flex flex-wrap items-center gap-1.5 pt-0.5">
                                                    <flux:skeleton class="h-4 w-12 rounded-full" />
                                                    <flux:skeleton class="h-4 w-10 rounded-full" />
                                                </div>
                                            </flux:skeleton.group>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
                <span
                    class="sr-only"
                    x-text="activeViewMode() === 'kanban' ? '{{ __('Switching to Kanban...') }}' : '{{ __('Switching to List...') }}'"
                ></span>
            </div>
        </div>

        {{-- Right Side: Calendar (20%) --}}
        <div class="hidden lg:block lg:min-w-[260px]">
            <div class="sticky top-6 mt-4" data-focus-lock-viewport>
                <x-workspace.calendar
                    :selected-date="$this->selectedDate"
                    :current-month="$this->calendarMonth"
                    :current-year="$this->calendarYear"
                    :month-meta="$this->calendarMonthMeta"
                    :selected-day-agenda="$this->selectedDayAgenda"
                    :source-filter="$this->calendarSourceFilter"
                />

                @auth
                    <div class="mt-4">
                        <x-workspace.calendar-feeds-popover />
                    </div>
                @endauth

                <div class="mt-4">
                    <x-workspace.upcoming
                        :items="$this->upcoming"
                        :selected-date="$this->selectedDate"
                    />
                </div>
            </div>
        </div>
    </div>

        </div>
    {{-- /viewMode Alpine scope --}}

    @auth
        <flux:modal name="task-assistant-chat" flyout position="right" class="h-full max-h-full w-full max-w-lg">
            <livewire:assistant.chat-flyout />
        </flux:modal>
    @endauth
</section>

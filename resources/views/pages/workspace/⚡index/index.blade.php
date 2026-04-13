<section
    class="space-y-6"
    x-data="{}"
    x-init="Alpine.store('focusSession', Alpine.store('focusSession') ?? { session: @js($this->activeFocusSession), focusReady: false })"
    @focus-session-updated.window="Alpine.store('focusSession', { ...Alpine.store('focusSession'), session: $event.detail?.session ?? $event.detail?.[0] ?? null, focusReady: false })"
>
    {{--
        wire:loading targets for the main list/kanban skeleton. Match any Livewire property or method
        that should show the full-area placeholder while the workspace Index re-renders.

        Covered: date (selectedDate), search (searchQuery, searchScope), view (viewMode), filters (filter* and set/clear helpers).

        Intentionally omitted: loadMoreItems / getMoreItemsHtml (append-only),
        collaboration invite accept/decline (list remounts via workspaceItemsVersion without skeleton),
        trash restore (same: afterTrashRestored bumps workspaceItemsVersion; no full-area skeleton).
    --}}
    @php
        $listLoadingTargets = 'selectedDate,searchQuery,searchScope,viewMode,filterItemType,filterTaskStatus,filterTaskPriority,filterTaskComplexity,filterEventStatus,filterTagId,filterRecurring,setFilter,clearFilter,setTagFilter,clearAllFilters';
    @endphp

    {{-- Main Content: 80/20 Split Layout --}}
    <div class="grid w-full gap-6 lg:grid-cols-[minmax(0,4fr)_minmax(260px,1fr)]">
        {{-- Left Side: List (80%) --}}
        <div
            class="min-w-0 space-y-6 overflow-visible"
            x-data="{
                pendingViewMode: null,
                initialViewMode: @js($this->viewMode),
                activeViewMode() {
                    if (this.pendingViewMode !== null) {
                        return this.pendingViewMode;
                    }

                    return this.$wire?.viewMode ?? this.initialViewMode;
                },
                setView(mode) {
                    if (mode === this.activeViewMode()) {
                        return;
                    }

                    this.pendingViewMode = mode;

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
                    const initialMode = $wire.viewMode ?? $data.initialViewMode;
                    if (!store || typeof store !== 'object') {
                        Alpine.store('workspaceView', { mode: initialMode });
                    } else {
                        store.mode = initialMode;
                    }
                }
            "
            x-effect="
                if (this.pendingViewMode !== null && $wire.viewMode === this.pendingViewMode) {
                    this.pendingViewMode = null;
                }
            "
        >
            {{-- Workspace hero panel (same shell + inner rhythm as dashboard hero) --}}
            <div class="hero-brand-gradient-shell">
                <div
                    class="pointer-events-none absolute inset-0 overflow-hidden rounded-2xl"
                    aria-hidden="true"
                >
                    <div class="absolute inset-0 bg-linear-to-r from-brand-blue/15 via-brand-purple/10 to-brand-green/15"></div>
                    <div class="absolute -right-4 -top-4 flex size-48 items-center justify-center rounded-full bg-brand-blue/15 blur-2xl"></div>
                </div>
                <div class="hero-brand-gradient-glass" aria-hidden="true"></div>
                <div class="relative z-10 flex w-full min-w-0 flex-col gap-2">
                    @php
                        $greetingName = auth()->user()?->firstName() ?? '';
                    @endphp
                    <div class="flex w-full min-w-0 items-center justify-between gap-3 sm:gap-4">
                        <h2 class="min-w-0 flex-1 text-2xl font-semibold tracking-tight text-foreground sm:text-3xl">
                            @if ($greetingName !== '')
                                {{ __('Workspace — Hello, :name!', ['name' => $greetingName]) }}
                            @else
                                {{ __('Workspace — Hello!') }}
                            @endif
                        </h2>
                        <div class="inline-flex shrink-0 items-center">
                            <x-notifications.bell-cluster variant="hero" />
                        </div>
                    </div>
                    <div class="w-full min-w-0">
                        {{-- Toolbar: List/Kanban + search + search scope (global vs selected date); unified h-10 --}}
                        <div class="pt-2 flex w-full min-w-0 flex-wrap items-center gap-2">
                            {{-- wire:ignore: avoid Livewire morphing tabs on viewMode (fixes white/empty flash). Loading state on wrapper only. --}}
                            <div
                                class="inline-flex shrink-0 transition-opacity duration-150 ease-out"
                                wire:loading.class="pointer-events-none opacity-70"
                                wire:target="viewMode"
                            >
                                <div
                                    wire:ignore
                                    class="inline-flex h-10 items-stretch gap-0.5 rounded-xl border border-white/50 bg-white/80 p-1 shadow-sm ring-1 ring-brand-purple/10 dark:border-zinc-600/70 dark:bg-zinc-900/55 dark:ring-zinc-700/40"
                                    role="tablist"
                                    aria-label="{{ __('Workspace view') }}"
                                >
                                    <button
                                        type="button"
                                        role="tab"
                                        :aria-selected="activeViewMode() === 'list'"
                                        aria-controls="workspace-list-panel"
                                        id="workspace-view-list"
                                        class="inline-flex h-full min-w-[3.25rem] items-center justify-center rounded-lg px-3 text-sm font-semibold transition-colors duration-150 ease-out focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/50"
                                        :class="activeViewMode() === 'list'
                                            ? 'bg-brand-blue text-white shadow-sm hover:bg-brand-blue'
                                            : 'text-muted-foreground hover:bg-white/90 hover:text-foreground dark:text-zinc-300 dark:hover:bg-zinc-800/90 dark:hover:text-zinc-100'"
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
                                        class="inline-flex h-full min-w-[3.25rem] items-center justify-center rounded-lg px-3 text-sm font-semibold transition-colors duration-150 ease-out focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/50"
                                        :class="activeViewMode() === 'kanban'
                                            ? 'bg-brand-blue text-white shadow-sm hover:bg-brand-blue'
                                            : 'text-muted-foreground hover:bg-white/90 hover:text-foreground dark:text-zinc-300 dark:hover:bg-zinc-800/90 dark:hover:text-zinc-100'"
                                        @click="setView('kanban')"
                                    >
                                        {{ __('Kanban') }}
                                    </button>
                                </div>
                            </div>
                            <div
                                class="flex min-h-10 min-w-[min(100%,12rem)] flex-1 items-stretch overflow-hidden rounded-xl border border-white/50 bg-white/80 shadow-sm ring-1 ring-brand-purple/10 dark:border-zinc-600/70 dark:bg-zinc-900/55 dark:ring-zinc-700/40"
                                role="group"
                                aria-label="{{ __('Search and scope') }}"
                            >
                                <input
                                    type="search"
                                    wire:model.live.debounce.300ms="searchQuery"
                                    placeholder="{{ __('Search tasks, events, projects…') }}"
                                    aria-label="{{ __('Search tasks, events, and projects') }}"
                                    autocomplete="off"
                                    class="h-10 min-h-10 min-w-0 flex-1 border-0 bg-transparent px-3 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-inset focus:ring-brand-blue/30 dark:text-zinc-100 dark:placeholder:text-zinc-400 dark:focus:ring-brand-blue/40"
                                />
                                <div class="flex shrink-0 border-s border-white/45 dark:border-zinc-600/55">
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
                                            class="size-10 h-10 shrink-0 rounded-none border-0 bg-transparent text-zinc-800 shadow-none ring-0 transition hover:bg-white/85 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-blue/35 dark:text-zinc-100 dark:hover:bg-zinc-800/85 dark:focus-visible:ring-brand-blue/40"
                                            wire:click="$wire.set('searchScope', $wire.searchScope === 'selected_date' ? 'all_items' : 'selected_date')"
                                            wire:loading.attr="disabled"
                                            wire:target="searchScope"
                                        />
                                    </flux:tooltip>
                                </div>
                            </div>
                            <div class="inline-flex shrink-0 items-center gap-2">
                                @auth
                                    <livewire:workspace.trash-popover />
                                @endauth
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Filters + active pills: one strip, aligned row (pills left / Add filters right on md+) --}}
            <div
                class="workspace-filter-strip overflow-visible rounded-xl border border-border/60 bg-muted/30 px-3 py-2.5 shadow-sm ring-1 ring-brand-purple/10 dark:border-border/50 dark:bg-muted/15 dark:ring-zinc-600/35 sm:px-4 sm:py-3.5"
            >
                <div class="flex flex-col gap-3 md:flex-row md:flex-row-reverse md:items-center md:justify-between md:gap-5">
                    <div class="flex shrink-0 items-center md:justify-end">
                        <x-workspace.filter-bar
                            :filters="$this->getFilters()"
                            :tags="$this->tags"
                            :show-list-scoped-filters="$this->viewMode !== 'kanban'"
                        />
                    </div>
                    <div class="min-w-0 flex-1">
                        <x-workspace.active-filter-pills
                            :filters="$this->getFilters()"
                            :tags="$this->tags"
                            :show-list-scoped-filters="$this->viewMode !== 'kanban'"
                        />
                    </div>
                </div>
            </div>

            {{-- List/kanban region only: loading skeletons must not cover the nav strip above --}}
            <div class="relative min-w-0 w-full">
            {{-- Real content - hidden during list/kanban loading targets (see $listLoadingTargets) --}}
            <div
                wire:loading.delay.shorter.remove
                wire:target="{{ $listLoadingTargets }}"
                class="w-full"
            >
                <div
                    id="workspace-list-panel"
                    role="tabpanel"
                    aria-labelledby="workspace-view-list"
                    class="w-full"
                    style="{{ $this->viewMode !== 'list' ? 'display: none' : '' }}"
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
                            :list-entries="$this->getAllListEntries()"
                            :projects="$this->projects"
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
                    style="{{ $this->viewMode !== 'kanban' ? 'display: none' : '' }}"
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

            {{-- Skeleton: same wire:target as wire:loading.remove on real content above --}}
            <div
                wire:loading.delay.shorter.block
                wire:target="{{ $listLoadingTargets }}"
                class="hidden w-full"
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

                <template x-if="$wire.viewMode === 'list'">
                    <div class="space-y-4">
                        <template x-for="i in skeletonItems" :key="i">
                            <x-workspace.skeleton-list-item-card />
                        </template>
                    </div>
                </template>

                <template x-if="$wire.viewMode === 'kanban'">
                    <div class="grid min-h-[50vh] w-full min-w-0 gap-3 sm:gap-4 md:grid-cols-3" style="min-width: min-content;">
                        <template x-for="col in [0, 1, 2]" :key="col">
                            <x-workspace.skeleton-kanban-column>
                                <template x-for="card in [0, 1, 2, 3, 4, 5]" :key="card">
                                    <x-workspace.skeleton-list-item-card compact />
                                </template>
                            </x-workspace.skeleton-kanban-column>
                        </template>
                    </div>
                </template>
            </div>
            </div>
        </div>

        {{-- Right Side: Calendar (20%) --}}
        <div class="hidden lg:block lg:min-w-[260px]">
            <div class="sticky top-6" data-focus-lock-viewport>
                <x-workspace.calendar
                    agenda-context="workspace"
                    :selected-date="$this->selectedDate"
                    :current-month="$this->calendarMonth"
                    :current-year="$this->calendarYear"
                    :month-meta="$this->calendarMonthMeta"
                    :selected-day-agenda="$this->selectedDayAgenda"
                />

                @auth
                    <div class="mt-4">
                        <x-workspace.calendar-feeds-popover />
                    </div>
                @endauth
            </div>
        </div>
    </div>

</section>

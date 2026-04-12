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
            <div
                class="relative flex min-h-56 w-full items-center rounded-2xl border border-brand-blue/25 px-5 py-5 shadow-sm ring-1 ring-brand-purple/15 lg:min-h-60 lg:px-7 dark:ring-brand-purple/20"
            >
                <div
                    class="pointer-events-none absolute inset-0 overflow-hidden rounded-2xl"
                    aria-hidden="true"
                >
                    <div class="absolute inset-0 bg-linear-to-r from-brand-blue/15 via-brand-purple/10 to-brand-green/15"></div>
                    <div class="absolute -right-4 -top-4 flex size-48 items-center justify-center rounded-full bg-brand-blue/15 blur-2xl"></div>
                </div>
                <div class="relative z-10 flex w-full min-w-0 flex-col gap-2">
                    @php
                        $greetingName = auth()->user()?->firstName() ?? '';
                    @endphp
                    <div class="flex w-full min-w-0 items-center justify-between gap-3 sm:gap-4">
                        <p class="min-w-0 flex-1 text-xs font-semibold uppercase leading-tight tracking-[0.14em] text-brand-blue/90 sm:text-sm">
                            @if ($greetingName !== '')
                                {{ __('Workspace — Hello, :name!', ['name' => $greetingName]) }}
                            @else
                                {{ __('Workspace — Hello!') }}
                            @endif
                        </p>
                        <div class="inline-flex shrink-0 items-center">
                            <x-notifications.bell-cluster variant="hero" />
                        </div>
                    </div>
                    <div class="w-full min-w-0 space-y-2">
                        <h2 class="max-w-xl text-2xl font-semibold tracking-tight text-foreground sm:text-3xl">
                            {{ __('Tasks, events, and projects in one place.') }}
                        </h2>
                        {{-- Toolbar: scope + search + List/Kanban; unified h-10 --}}
                        <div class="pt-2 flex w-full min-w-0 flex-wrap items-center gap-2">
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
                                    class="size-10 h-10 w-10 shrink-0 rounded-xl border border-white/50 bg-white/80 text-zinc-900 shadow-sm transition hover:bg-white focus-visible:ring-2 focus-visible:ring-brand-blue/40 dark:border-zinc-600/70 dark:bg-zinc-900/55 dark:text-zinc-100 dark:hover:bg-zinc-800/80"
                                    wire:click="$wire.set('searchScope', $wire.searchScope === 'selected_date' ? 'all_items' : 'selected_date')"
                                    wire:loading.attr="disabled"
                                    wire:target="searchScope"
                                />
                            </flux:tooltip>
                            <flux:input
                                type="search"
                                wire:model.live.debounce.300ms="searchQuery"
                                :loading="false"
                                placeholder="{{ __('Search tasks, events, projects…') }}"
                                aria-label="{{ __('Search tasks, events, and projects') }}"
                                autocomplete="off"
                                class="h-10 min-h-10 min-w-[min(100%,12rem)] flex-1 rounded-xl border border-white/50 bg-white/80 text-foreground shadow-sm placeholder:text-muted-foreground focus:border-brand-blue/40 focus:ring-brand-blue/25 dark:border-zinc-600/70 dark:bg-zinc-900/55 dark:focus:border-brand-blue/50 [&_input]:h-10 [&_input]:min-h-0"
                            />
                            <div
                                class="inline-flex h-10 shrink-0 items-stretch gap-0.5 rounded-xl border border-white/50 bg-white/80 p-1 shadow-sm ring-1 ring-brand-purple/10 dark:border-zinc-600/70 dark:bg-zinc-900/55 dark:ring-zinc-700/40"
                                role="tablist"
                                aria-label="{{ __('Workspace view') }}"
                            >
                                <button
                                    type="button"
                                    role="tab"
                                    :aria-selected="activeViewMode() === 'list'"
                                    aria-controls="workspace-list-panel"
                                    id="workspace-view-list"
                                    @class([
                                        'inline-flex h-full min-w-[3.25rem] items-center justify-center rounded-lg px-3 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/50',
                                        'bg-brand-blue text-white shadow-sm' => $this->viewMode === 'list',
                                        'text-muted-foreground hover:bg-white/90 hover:text-foreground dark:hover:bg-zinc-800/90' => $this->viewMode !== 'list',
                                    ])
                                    :class="activeViewMode() === 'list'
                                        ? 'bg-brand-blue text-white shadow-sm'
                                        : 'text-muted-foreground hover:bg-white/90 hover:text-foreground dark:hover:bg-zinc-800/90'"
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
                                    @class([
                                        'inline-flex h-full min-w-[3.25rem] items-center justify-center rounded-lg px-3 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/50',
                                        'bg-brand-blue text-white shadow-sm' => $this->viewMode === 'kanban',
                                        'text-muted-foreground hover:bg-white/90 hover:text-foreground dark:hover:bg-zinc-800/90' => $this->viewMode !== 'kanban',
                                    ])
                                    :class="activeViewMode() === 'kanban'
                                        ? 'bg-brand-blue text-white shadow-sm'
                                        : 'text-muted-foreground hover:bg-white/90 hover:text-foreground dark:hover:bg-zinc-800/90'"
                                    @click="setView('kanban')"
                                >
                                    {{ __('Kanban') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Filters + active pills: one strip, aligned row (pills left / Add filters right on md+) --}}
            <div
                class="overflow-visible rounded-xl border border-border/60 bg-muted/25 px-3 py-2.5 shadow-sm dark:border-border/50 dark:bg-muted/15 sm:px-4 sm:py-3"
            >
                <div class="flex flex-col gap-3 md:flex-row md:flex-row-reverse md:items-center md:justify-between md:gap-4">
                    <div class="flex shrink-0 items-center md:justify-end">
                        <x-workspace.filter-bar
                            :filters="$this->getFilters()"
                            :tags="$this->tags"
                        />
                    </div>
                    <div class="min-w-0 flex-1">
                        <x-workspace.active-filter-pills
                            :filters="$this->getFilters()"
                            :tags="$this->tags"
                        />
                    </div>
                </div>
            </div>

            {{-- List/kanban region only: loading skeletons must not cover the nav strip above --}}
            <div class="relative min-w-0 w-full">
            {{-- Real content - hidden during list/kanban loading targets (see $listLoadingTargets) --}}
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
                wire:loading.block
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

<section
    class="space-y-6"
    x-data="{ focusNavigationLoading: false }"
    x-init="Alpine.store('focusSession', Alpine.store('focusSession') ?? { session: @js($this->activeFocusSession), focusReady: false })"
    @focus-session-updated.window="Alpine.store('focusSession', { ...Alpine.store('focusSession'), session: $event.detail?.session ?? $event.detail?.[0] ?? null, focusReady: false })"
    @workspace-focus-navigation-loading-start.window="focusNavigationLoading = true"
    @workspace-focus-navigation-loading-end.window="focusNavigationLoading = false"
>
    {{--
        List/kanban region: full skeleton for filter/search/view changes and for selectedDate changes.

        Intentionally omitted: loadMoreItems / getMoreItemsHtml (append-only),
        collaboration invite accept/decline (list remounts via workspaceItemsVersion without skeleton),
        trash restore (same: afterTrashRestored bumps workspaceItemsVersion; no full-area skeleton).
    --}}
    @php
        $listHeavyLoadingTargets = 'searchQuery,searchScope,showCompleted,viewMode,filterItemType,filterTaskStatus,filterTaskPriority,filterTaskComplexity,filterTaskSource,filterEventStatus,filterTagId,filterRecurring,filterDueState,setFilter,clearFilter,setTagFilter,clearAllFilters,applyQuickFilterPreset';
        $selectedDateLoadingTarget = 'selectedDate,jumpCalendarToToday';
        $listRegionLoadingTargets = $listHeavyLoadingTargets.','.$selectedDateLoadingTarget;
        $workspaceMobileSelectedLabel = \Illuminate\Support\Carbon::parse($this->selectedDate)->translatedFormat('D, M j, Y');
    @endphp

    {{-- Main Content: 80/20 Split Layout --}}
    <div class="grid w-full gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(260px,1fr)]">
        {{-- Left Side: List (80%) --}}
        <div class="order-2 min-w-0 space-y-6 overflow-visible lg:order-1">
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
                    <div class="flex w-full min-w-0 items-start justify-between gap-3 sm:gap-4">
                        <div class="min-w-0 flex-1 space-y-1">
                            <h2 class="min-w-0 text-2xl font-semibold tracking-tight text-foreground sm:text-3xl">
                                @if ($greetingName !== '')
                                    {{ __('Workspace — Hello, :name!', ['name' => $greetingName]) }}
                                @else
                                    {{ __('Workspace — Hello!') }}
                                @endif
                            </h2>
                            <p class="max-w-2xl text-sm text-muted-foreground">
                                {{ __('Build your day in one place: add tasks, classes, and deadlines, then use filters to focus on what matters next.') }}
                            </p>
                        </div>
                        <div class="inline-flex shrink-0 items-center">
                            <x-notifications.bell-cluster variant="hero" />
                        </div>
                    </div>
                    <div class="w-full min-w-0">
                        {{-- Toolbar: List/Kanban + search + search scope (global vs selected date); unified h-10 --}}
                        <div class="pt-2 flex w-full min-w-0 flex-wrap items-center gap-2">
                            <x-workspace.view-mode-switcher :view-mode="$this->viewMode" />
                            <div
                                class="flex min-h-10 min-w-[min(100%,12rem)] flex-1 items-stretch overflow-hidden rounded-xl border border-white/50 bg-white/80 shadow-sm ring-1 ring-brand-purple/10 dark:border-zinc-600/70 dark:bg-zinc-900/55 dark:ring-zinc-700/40"
                                role="group"
                                aria-label="{{ __('Search and scope') }}"
                            >
                                <input
                                    type="search"
                                    wire:model.live.debounce.300ms="searchQuery"
                                    placeholder="{{ __('Search tasks, events, projects, classes…') }}"
                                    aria-label="{{ __('Search tasks, events, projects, and classes') }}"
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

            <div id="workspace-mobile-calendar-anchor" class="scroll-mt-4 lg:hidden">
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

            {{-- Filters + active pills: one strip, aligned row (pills left / Add filters right on md+) --}}
            <div
                class="workspace-filter-strip overflow-visible rounded-xl border border-border/60 bg-muted/30 px-3 py-2.5 shadow-sm ring-1 ring-brand-purple/10 dark:border-border/50 dark:bg-muted/15 dark:ring-zinc-600/35 sm:px-4 sm:py-3.5"
            >
                <div class="mb-3 flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        wire:click="applyQuickFilterPreset('exams_only')"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold transition',
                            'border-amber-300/60 bg-amber-100 text-amber-800 dark:border-amber-900/45 dark:bg-amber-950/50 dark:text-amber-200' => $this->isQuickFilterPresetActive('exams_only'),
                            'border-border/70 bg-background text-foreground hover:bg-muted/70 dark:bg-zinc-900/70' => ! $this->isQuickFilterPresetActive('exams_only'),
                        ])
                    >
                        <svg class="size-3.5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14.25L3 9.75l9-4.5 9 4.5-9 4.5z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 9.75v4.5L12 18.75l9-4.5v-4.5" />
                        </svg>
                        {{ __('Exams only') }}
                    </button>
                    <button
                        type="button"
                        wire:click="applyQuickFilterPreset('quizzes_activities_only')"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold transition',
                            'border-blue-300/60 bg-blue-100 text-blue-800 dark:border-blue-900/45 dark:bg-blue-950/50 dark:text-blue-200' => $this->isQuickFilterPresetActive('quizzes_activities_only'),
                            'border-border/70 bg-background text-foreground hover:bg-muted/70 dark:bg-zinc-900/70' => ! $this->isQuickFilterPresetActive('quizzes_activities_only'),
                        ])
                    >
                        <svg class="size-3.5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75A2.25 2.25 0 016 4.5h12a2.25 2.25 0 012.25 2.25v10.5A2.25 2.25 0 0118 19.5H6a2.25 2.25 0 01-2.25-2.25V6.75z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 9h9M7.5 12h6M7.5 15h4.5" />
                        </svg>
                        {{ __('Quizzes/Activities only') }}
                    </button>
                    <button
                        type="button"
                        wire:click="applyQuickFilterPreset('brightspace_only')"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold transition',
                            'border-blue-500/35 bg-blue-500/12 text-blue-800 dark:border-blue-500/45 dark:bg-blue-500/18 dark:text-blue-200' => $this->isQuickFilterPresetActive('brightspace_only'),
                            'border-border/70 bg-background text-foreground hover:bg-muted/70 dark:bg-zinc-900/70' => ! $this->isQuickFilterPresetActive('brightspace_only'),
                        ])
                    >
                        <img src="{{ asset('images/brightspace-icon.png') }}" alt="" class="size-3.5 shrink-0 object-contain" />
                        {{ __('Brightspace only') }}
                    </button>
                    <button
                        type="button"
                        wire:click="applyQuickFilterPreset('recurring_only')"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold transition',
                            'border-amber-300/60 bg-amber-100 text-amber-800 dark:border-amber-900/45 dark:bg-amber-950/50 dark:text-amber-200' => $this->isQuickFilterPresetActive('recurring_only'),
                            'border-border/70 bg-background text-foreground hover:bg-muted/70 dark:bg-zinc-900/70' => ! $this->isQuickFilterPresetActive('recurring_only'),
                        ])
                    >
                        <svg class="size-3.5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                        </svg>
                        {{ __('Recurring only') }}
                    </button>
                </div>
                <div class="flex flex-col gap-3 md:flex-row-reverse md:items-center md:justify-between md:gap-5">
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

            <div
                class="lg:hidden sticky top-0 z-20 -mx-0.5 mb-2 flex items-center justify-between gap-2 rounded-xl border border-border/55 bg-background/90 px-3 py-2 shadow-sm backdrop-blur-md supports-backdrop-filter:bg-background/75 dark:border-zinc-600/45 dark:bg-zinc-950/90"
                role="status"
                aria-live="polite"
                data-testid="workspace-mobile-selected-date-bar"
                wire:key="workspace-mobile-selected-{{ $this->selectedDate }}"
            >
                <div class="flex min-w-0 items-center gap-2">
                    <flux:icon name="calendar-days" class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <span class="truncate text-xs font-semibold tabular-nums text-foreground">{{ $workspaceMobileSelectedLabel }}</span>
                </div>
                <button
                    type="button"
                    class="shrink-0 rounded-lg border border-border/60 bg-muted/50 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-foreground transition hover:bg-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/35 dark:border-zinc-600 dark:bg-zinc-800/80"
                    onclick="document.getElementById('workspace-mobile-calendar-anchor')?.scrollIntoView({ behavior: 'smooth', block: 'start' })"
                >
                    {{ __('Calendar') }}
                </button>
            </div>

            {{-- List/kanban region only: loading skeletons must not cover the nav strip above --}}
            <div class="relative min-w-0 w-full">
            <div
                wire:loading.delay.shorter.remove
                wire:target="{{ $listRegionLoadingTargets }}"
                class="w-full"
                x-show="!focusNavigationLoading"
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
                            :completed-entries="$this->completedListEntries()"
                            :projects="$this->projects"
                            :tags="$this->tags"
                            :teachers="$this->teachers"
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
                            :completed-entries="$this->completedListEntries()"
                            :tags="$this->tags"
                            :teachers="$this->teachers"
                            :filters="$this->getFilters()"
                            :active-focus-session="$this->activeFocusSession"
                            :pomodoro-settings="$this->pomodoroSettings"
                        />
                    @endif
                </div>
            </div>

            {{-- Skeleton: filters, search, view mode, and selected date --}}
            <div
                wire:loading.delay.shorter.block
                wire:target="{{ $listRegionLoadingTargets }}"
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

            {{-- Skeleton: focus navigation from subtasks when target is not instantly focusable in current DOM --}}
            <div
                x-cloak
                x-show="focusNavigationLoading"
                class="w-full"
                role="status"
                aria-busy="true"
                aria-live="polite"
                aria-label="{{ __('Loading workspace') }}"
            >
                <span
                    class="sr-only"
                    x-text="$wire.viewMode === 'kanban' ? '{{ __('Loading workspace kanban...') }}' : '{{ __('Loading workspace list...') }}'"
                ></span>

                <template x-if="$wire.viewMode === 'list'">
                    <div class="space-y-4">
                        @for ($i = 0; $i < 8; $i++)
                            <x-workspace.skeleton-list-item-card />
                        @endfor
                    </div>
                </template>

                <template x-if="$wire.viewMode === 'kanban'">
                    <div class="grid min-h-[50vh] w-full min-w-0 gap-3 sm:gap-4 md:grid-cols-3" style="min-width: min-content;">
                        @for ($col = 0; $col < 3; $col++)
                            <x-workspace.skeleton-kanban-column>
                                @for ($card = 0; $card < 6; $card++)
                                    <x-workspace.skeleton-list-item-card compact />
                                @endfor
                            </x-workspace.skeleton-kanban-column>
                        @endfor
                    </div>
                </template>
            </div>
            </div>
        </div>

        {{-- Right Side: Calendar (20%) --}}
        <div class="order-1 hidden lg:order-2 lg:block lg:min-w-[260px]">
            <div data-focus-lock-viewport>
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

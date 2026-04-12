<div class="space-y-4">
    <x-workspace.item-creation
        :tags="$tags"
        :projects="$projects"
        :active-focus-session="$activeFocusSession"
        mode="list"
    />


    @php
        $date = $selectedDate ? \Illuminate\Support\Carbon::parse($selectedDate) : now();
        $emptyDateLabel = $date->isToday()
            ? __('today')
            : ($date->isTomorrow()
                ? __('tomorrow')
                : ($date->isYesterday()
                    ? __('yesterday')
                    : $date->translatedFormat('l, F j, Y')));

        $overdueItems = $overdue->map(fn (array $entry) => array_merge($entry, ['isOverdue' => true]));

        $dateItems = collect()
            ->merge($projects->map(fn ($item) => ['kind' => 'project', 'item' => $item, 'isOverdue' => $item->end_datetime ? $item->end_datetime->isPast() : false]))
            ->merge($events->map(fn ($item) => ['kind' => 'event', 'item' => $item, 'isOverdue' => $item->end_datetime ? $item->end_datetime->isPast() : false]))
            ->merge($tasks->map(fn ($item) => ['kind' => 'task', 'item' => $item, 'isOverdue' => $item->end_datetime ? $item->end_datetime->isPast() : false]))
            ->sortByDesc(fn (array $entry) => $entry['item']->created_at)
            ->values();

        $allItems = $overdueItems->merge($dateItems)->values();

        $effectiveItemsPerPage = $itemsPerPage > 0 ? $itemsPerPage : 10;
        $effectiveItemsPage = $itemsPage > 0 ? $itemsPage : 1;
        $maxVisibleItems = $effectiveItemsPerPage * $effectiveItemsPage;

        $items = $allItems->take($maxVisibleItems);
        $totalItemsCount = $items->count();

        $hasMoreFromCollections = $allItems->count() > $items->count();
        $shouldShowLoadMore = $hasMoreItems || $hasMoreFromCollections;

        $hasActiveFilters = $filters['hasActiveFilters'] ?? false;
        $hasActiveSearch = $filters['hasActiveSearch'] ?? false;
        $searchQueryDisplay = $filters['searchQuery'] ?? null;
        $itemTypeLabels = [
            'tasks' => __('Tasks'),
            'events' => __('Events'),
            'projects' => __('Projects'),
        ];
        $activeFilterParts = array_filter([
            ($filters['itemType'] ?? null)
                ? __('Show') . ': ' . ($itemTypeLabels[$filters['itemType']] ?? $filters['itemType'])
                : null,
            ($filters['taskStatus'] ?? null)
                ? __('Status') . ': ' . (\App\Enums\TaskStatus::tryFrom($filters['taskStatus'])?->label() ?? $filters['taskStatus'])
                : null,
            ($filters['taskPriority'] ?? null)
                ? __('Priority') . ': ' . (\App\Enums\TaskPriority::tryFrom($filters['taskPriority'])?->label() ?? $filters['taskPriority'])
                : null,
            ($filters['eventStatus'] ?? null)
                ? __('Event status') . ': ' . (\App\Enums\EventStatus::tryFrom($filters['eventStatus'])?->label() ?? $filters['eventStatus'])
                : null,
        ]);

    @endphp
    @if ($items->isEmpty() && $overdue->isEmpty())
        <div class="mt-6 flex flex-col gap-2 rounded-xl border border-border/60 bg-background/60 px-3 py-2 shadow-sm backdrop-blur">
            <div class="flex items-center gap-2">
                <flux:icon name="calendar-days" class="size-5 text-muted-foreground/50" />
                <flux:text class="text-sm font-medium text-muted-foreground">
                    {{ __('No tasks, projects, or events for :date', ['date' => $emptyDateLabel]) }}
                </flux:text>
            </div>
            @if ($hasActiveSearch && $searchQueryDisplay)
                <flux:text class="text-xs text-muted-foreground/70">
                    {{ __('No results for “:query”. Try a different search or clear the search.', ['query' => $searchQueryDisplay]) }}
                </flux:text>
            @endif
            @if ($hasActiveFilters && $activeFilterParts !== [])
                <flux:text class="text-xs text-muted-foreground/70">
                    {{ __('Active filters') }}: {{ implode(', ', $activeFilterParts) }}
                </flux:text>
                <flux:text class="text-xs text-muted-foreground/70">
                    {{ __('Try adjusting filters or add a new task, project, or event for this day') }}
                </flux:text>
            @elseif (!$hasActiveSearch)
                <flux:text class="text-xs text-muted-foreground/70">
                    {{ __('Add a task, project, or event for this day to get started') }}
                </flux:text>
            @endif
        </div>
    @else
        <div
            class="space-y-4"
            x-data="{
                visibleItemCount: {{ $totalItemsCount }},
                showEmptyState: false,
                emptyStateTimeout: null,
                init() {
                    this.$watch('visibleItemCount', () => this.syncEmptyState());
                    this.syncEmptyState();
                },
                syncEmptyState() {
                    const shouldBeEmpty = this.visibleItemCount === 0;
                    if (shouldBeEmpty) {
                        if (this.emptyStateTimeout) return;
                        this.emptyStateTimeout = setTimeout(() => {
                            this.showEmptyState = true;
                            this.emptyStateTimeout = null;
                        }, 200);
                    } else {
                        if (this.emptyStateTimeout) {
                            clearTimeout(this.emptyStateTimeout);
                            this.emptyStateTimeout = null;
                        }
                        this.showEmptyState = false;
                    }
                },
                handleListItemHidden() {
                    this.visibleItemCount--;
                },
                handleListItemShown(e) {
                    this.visibleItemCount++;
                }
            }"
            @list-item-hidden.window="handleListItemHidden()"
            @list-item-shown.window="handleListItemShown($event)"
        >
            <div
                x-show="showEmptyState"
                x-cloak
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 scale-[0.98]"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-[0.98]"
                class="mt-6 flex flex-col gap-2 rounded-xl border border-border/60 bg-background/60 px-3 py-2 shadow-sm backdrop-blur"
            >
                <div class="flex items-center gap-2">
                    <flux:icon name="calendar-days" class="size-5 text-muted-foreground/50" />
                    <flux:text class="text-sm font-medium text-muted-foreground">
                        {{ __('No tasks, projects, or events for :date', ['date' => $emptyDateLabel]) }}
                    </flux:text>
                </div>
                @if ($hasActiveSearch && $searchQueryDisplay)
                    <flux:text class="text-xs text-muted-foreground/70">
                        {{ __('No results for “:query”. Try a different search or clear the search.', ['query' => $searchQueryDisplay]) }}
                    </flux:text>
                @endif
                @if ($hasActiveFilters && $activeFilterParts !== [])
                    <flux:text class="text-xs text-muted-foreground/70">
                        {{ __('Active filters') }}: {{ implode(', ', $activeFilterParts) }}
                    </flux:text>
                    <flux:text class="text-xs text-muted-foreground/70">
                        {{ __('Try adjusting filters or add a new task, project, or event for this day') }}
                    </flux:text>
                @elseif (!$hasActiveSearch)
                    <flux:text class="text-xs text-muted-foreground/70">
                        {{ __('Add a task, project, or event for this day to get started') }}
                    </flux:text>
                @endif
            </div>
            @php
                $defaultWorkDurationMinutes = config('focus.default_duration_minutes', config('pomodoro.defaults.work_duration_minutes', 25));
            @endphp
            <div x-show="visibleItemCount > 0" class="space-y-4">
                <div class="space-y-3" id="workspace-list-items-inner">
                    @foreach ($items as $entry)
                        <x-workspace.list-item-card
                            :kind="$entry['kind']"
                            :item="$entry['item']"
                            :list-filter-date="$entry['isOverdue'] ? null : $selectedDate"
                            :filters="$filters"
                            :available-tags="$tags"
                            :is-overdue="$entry['isOverdue']"
                            :active-focus-session="$activeFocusSession ?? null"
                            :default-work-duration-minutes="$defaultWorkDurationMinutes"
                            :pomodoro-settings="$this->pomodoroSettings"
                            wire:key="{{ $entry['kind'] }}-{{ $entry['item']->id }}"
                        />
                    @endforeach
                </div>
                @if ($shouldShowLoadMore)
                    <div
                        class="flex flex-col items-center justify-center py-4 text-[11px] text-muted-foreground/80"
                        x-data="{
                            loadingMore: false,
                            hasMore: @js($shouldShowLoadMore),
                            async triggerLoadMore() {
                                if (this.loadingMore || !this.hasMore) return;
                                this.loadingMore = true;
                                try {
                                    const result = await $wire.$parent.getMoreItemsHtml();
                                    if (result && result.html) {
                                        const container = document.getElementById('workspace-list-items-inner');
                                        if (container) {
                                            const temp = document.createElement('div');
                                            temp.innerHTML = result.html;
                                            const newNodes = [...temp.children];
                                            newNodes.forEach(el => container.appendChild(el));
                                            newNodes.forEach(el => typeof Alpine !== 'undefined' && Alpine.initTree && Alpine.initTree(el));
                                        }
                                    }
                                    if (result && result.hasMore === false) this.hasMore = false;
                                } finally {
                                    this.loadingMore = false;
                                }
                            }
                        }"
                        x-show="hasMore || loadingMore"
                        x-intersect.threshold.25="triggerLoadMore()"
                    >
                        <div
                            x-show="loadingMore"
                            class="mt-2 w-full space-y-2"
                            aria-hidden="true"
                        >
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
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>

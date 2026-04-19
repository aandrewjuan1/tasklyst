<div class="space-y-4">
    @php
        $date = $selectedDate ? \Illuminate\Support\Carbon::parse($selectedDate) : now();
        $emptyDateLabel = $date->isToday()
            ? __('today')
            : ($date->isTomorrow()
                ? __('tomorrow')
                : ($date->isYesterday()
                    ? __('yesterday')
                    : $date->translatedFormat('l, F j, Y')));

        $allItems = $listEntries->values();

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
        $visibleItemsInitial = $allItems->isEmpty() ? 0 : $totalItemsCount;
        $showCompleted = (bool) ($filters['showCompleted'] ?? false);
        $completedItems = $showCompleted ? $completedEntries->values() : collect();
        $hasAnyListContent = $allItems->isNotEmpty() || $completedItems->isNotEmpty();
        $scheduledFocusGroups = is_array($scheduledFocusPlanGroups ?? null) ? $scheduledFocusPlanGroups : ['today' => [], 'tomorrow' => [], 'upcoming' => []];
        $scheduledFocusTotalCount = (int) ($scheduledFocusPlanTotalCount ?? 0);
        $hasScheduledFocusPanel = $scheduledFocusTotalCount > 0;
    @endphp

    <x-workspace.item-creation
        :tags="$tags"
        :projects="$projects"
        :active-focus-session="$activeFocusSession"
        mode="list"
        :empty-date-label="$emptyDateLabel"
        :has-active-search="$hasActiveSearch"
        :has-active-filters="$hasActiveFilters"
        :search-query-display="$searchQueryDisplay"
        :visible-items-initial="$visibleItemsInitial"
    />

    @if ($hasScheduledFocusPanel)
        <x-workspace.scheduled-focus-plan
            :groups="$scheduledFocusGroups"
            :total-count="$scheduledFocusTotalCount"
        />
    @endif

    @if ($hasAnyListContent)
        <div
            class="space-y-4"
            data-workspace-list-scope
            x-data="{
                visibleItemCount: {{ $totalItemsCount }},
                loadMoreLoading: false,
                loadMoreHasMore: @js($shouldShowLoadMore),
                broadcastVisibleCount() {
                    window.dispatchEvent(new CustomEvent('workspace-list-visible-count', { detail: { count: this.visibleItemCount } }));
                },
                init() {
                    this.broadcastVisibleCount();
                },
                handleListItemHidden() {
                    this.visibleItemCount = Math.max(0, this.visibleItemCount - 1);
                    this.broadcastVisibleCount();
                },
                handleListItemShown() {
                    this.visibleItemCount++;
                    this.broadcastVisibleCount();
                },
                async triggerLoadMore() {
                    if (this.loadMoreLoading || !this.loadMoreHasMore) return;
                    this.loadMoreLoading = true;
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
                                const n = newNodes.length;
                                if (n > 0) {
                                    this.visibleItemCount += n;
                                    this.broadcastVisibleCount();
                                }
                            }
                        }
                        if (result && result.hasMore === false) {
                            this.loadMoreHasMore = false;
                        }
                    } finally {
                        this.loadMoreLoading = false;
                    }
                },
            }"
            @list-item-hidden.window="handleListItemHidden()"
            @list-item-shown.window="handleListItemShown()"
        >
            @php
                $defaultWorkDurationMinutes = config('focus.default_duration_minutes', config('pomodoro.defaults.work_duration_minutes', 25));
            @endphp
            <div x-show="visibleItemCount > 0" class="space-y-4">
                <div class="space-y-3" id="workspace-list-items-inner">
                    @foreach ($items as $entry)
                        <x-workspace.list-item-entry
                            :entry="$entry"
                            :list-filter-date="$entry['isOverdue'] ? null : $selectedDate"
                            :filters="$filters"
                            :tags="$tags"
                            :active-focus-session="$activeFocusSession ?? null"
                            :default-work-duration-minutes="$defaultWorkDurationMinutes"
                            :pomodoro-settings="$this->pomodoroSettings"
                        />
                    @endforeach
                </div>
                @if ($shouldShowLoadMore)
                    <div
                        class="flex flex-col items-center justify-center py-4 text-[11px] text-muted-foreground/80"
                        x-show="loadMoreHasMore || loadMoreLoading"
                        x-intersect.threshold.25="triggerLoadMore()"
                    >
                        <div
                            x-show="loadMoreLoading"
                            class="mt-2 w-full"
                            aria-hidden="true"
                        >
                            <x-workspace.skeleton-list-item-card />
                        </div>
                    </div>
                @endif
            </div>
            @if ($completedItems->isNotEmpty())
                <div class="space-y-3 border-t border-border/50 pt-4">
                    <div class="px-1">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            {{ __('Completed') }} ({{ $completedItems->count() }})
                        </h3>
                    </div>
                    <div class="space-y-3">
                        @foreach ($completedItems as $entry)
                            <x-workspace.list-item-entry
                                :entry="$entry"
                                :list-filter-date="$selectedDate"
                                :filters="$filters"
                                :tags="$tags"
                                :active-focus-session="$activeFocusSession ?? null"
                                :default-work-duration-minutes="$defaultWorkDurationMinutes"
                                :pomodoro-settings="$this->pomodoroSettings"
                                key-prefix="completed"
                                :completed-strip="true"
                            />
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>

@props([
    'item',
    'kind' => null,
    'position' => 'top',
    'align' => 'end',
])

@php
    /** @var \Illuminate\Database\Eloquent\Model|\App\Models\Task|\App\Models\Project|\App\Models\Event $item */
    $logs = $item->activityLogs ?? collect();

    $logsCount = $logs->count();
    $totalLogs = (int) ($item->activity_logs_count ?? $logsCount);
    $initialHasMore = $totalLogs > $logsCount;

    $logsForJs = $logs
        ->map(function (\App\Models\ActivityLog $log): array {
            $actorEmail = $log->user?->email;
            $actorDisplay = $actorEmail ?? __('Unknown user');

            return [
                'id' => $log->id,
                'action' => $log->action->value,
                'actionLabel' => $log->action->label(),
                'message' => $log->message(),
                'payload' => $log->payload ?? [],
                'createdAt' => $log->created_at?->toIso8601String() ?? '',
                'createdDisplay' => $log->created_at?->translatedFormat('M j, Y g:i A') ?? '',
                'actorEmail' => $actorDisplay,
            ];
        })
        ->values();

    $itemName = match (strtolower((string) $kind)) {
        'project' => $item->name ?? null,
        'task', 'event' => $item->title ?? null,
        default => $item->title ?? $item->name ?? null,
    };

    if (! $itemName && method_exists($item, 'getAttribute')) {
        $itemName = $item->getAttribute('title') ?? $item->getAttribute('name');
    }

    $itemName = $itemName ?: __('this item');

    $loggableType = get_class($item);
@endphp

<div
    wire:ignore
    x-data="{
        open: false,
        placementVertical: @js($position),
        placementHorizontal: @js($align),
        panelHeightEst: 320,
        panelWidthEst: 320,
        logs: @js($logsForJs),
        totalCount: @js($totalLogs),
        loggableType: @js($loggableType),
        loggableId: @js($item->id),
        loadingMore: false,
        hasMore: @js($initialHasMore),
        loadMoreErrorToast: @js(__('Could not load more activity. Please try again.')),
        panelPlacementClassesValue: 'absolute bottom-full right-0 mb-1',
        openedAt: 0,

        openFromMenu() {
            if (this.open) {
                return;
            }

            const button = this.$refs.button;
            if (!button) {
                this.open = true;
                this.openedAt = Date.now();
                this.$dispatch('dropdown-opened');

                return;
            }

            const vh = window.innerHeight;
            const vw = window.innerWidth;
            const rect = button.getBoundingClientRect();
            const contentLeft = vw < 768 ? 16 : 320;
            const effectivePanelWidth = Math.min(this.panelWidthEst, vw - 32);

            const spaceBelow = vh - rect.bottom;
            const spaceAbove = rect.top;

            if (spaceBelow >= this.panelHeightEst || spaceBelow >= spaceAbove) {
                this.placementVertical = 'bottom';
            } else {
                this.placementVertical = 'top';
            }

            const endFits = rect.right <= vw && rect.right - effectivePanelWidth >= contentLeft;
            const startFits = rect.left >= contentLeft && rect.left + effectivePanelWidth <= vw;

            if (rect.left < contentLeft) {
                this.placementHorizontal = 'start';
            } else if (endFits) {
                this.placementHorizontal = 'end';
            } else if (startFits) {
                this.placementHorizontal = 'start';
            } else {
                this.placementHorizontal = rect.right > vw ? 'start' : 'end';
            }

            const v = this.placementVertical;
            const h = this.placementHorizontal;
            if (vw <= 480) {
                this.panelPlacementClassesValue = 'fixed inset-x-3 bottom-4 max-h-[min(70vh,22rem)]';
            } else if (v === 'top' && h === 'end') {
                this.panelPlacementClassesValue = 'absolute bottom-full right-0 mb-1';
            } else if (v === 'top' && h === 'start') {
                this.panelPlacementClassesValue = 'absolute bottom-full left-0 mb-1';
            } else if (v === 'bottom' && h === 'end') {
                this.panelPlacementClassesValue = 'absolute top-full right-0 mt-1';
            } else if (v === 'bottom' && h === 'start') {
                this.panelPlacementClassesValue = 'absolute top-full left-0 mt-1';
            } else {
                this.panelPlacementClassesValue = 'absolute bottom-full right-0 mb-1';
            }

            this.open = true;
            this.openedAt = Date.now();
            this.$dispatch('dropdown-opened');
        },

        close(focusAfter) {
            if (!this.open) return;

            this.open = false;

            const leaveMs = 50;
            setTimeout(() => this.$dispatch('dropdown-closed'), leaveMs);

            focusAfter && focusAfter.focus();
        },

        async loadMore() {
            if (this.loadingMore || !this.hasMore) {
                return;
            }

            this.loadingMore = true;

            try {
                const response = await $wire.$parent.$call('loadMoreActivityLogs', this.loggableType, this.loggableId, this.logs.length);
                const newLogs = (response?.logs ?? []).map((log) => {
                    const email = log.user?.email || log.user?.display || '{{ __('Unknown user') }}';

                    return {
                        id: log.id,
                        action: log.action,
                        actionLabel: log.actionLabel,
                        message: log.message,
                        payload: log.payload || {},
                        createdAt: log.createdAt || '',
                        createdDisplay: log.createdDisplay || '',
                        actorEmail: email,
                    };
                });

                if (newLogs.length) {
                    this.logs = [...this.logs, ...newLogs];
                }

                this.hasMore = Boolean(response?.hasMore);
            } catch (e) {
                this.hasMore = false;
                $wire.$dispatch('toast', { type: 'error', message: this.loadMoreErrorToast });
            } finally {
                this.loadingMore = false;
            }
        },
    }"
    @keydown.escape.prevent.stop="close($refs.button)"
    @focusin.window="($refs.panel && !$refs.panel.contains($event.target) && (Date.now() - openedAt > 200)) && close($refs.button)"
    @workspace-open-activity-logs.window="
        if ($event.detail && Number($event.detail.id ?? null) === Number(loggableId)) {
            openFromMenu();
        }
    "
>
    {{-- Invisible anchor button used for popover positioning and focus --}}
    <button
        x-ref="button"
        type="button"
        class="absolute right-0 top-0 h-0 w-0 opacity-0 pointer-events-none"
        tabindex="-1"
        aria-hidden="true"
    ></button>

    <div
        x-ref="panel"
        x-show="open"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-cloak
        @click.outside="close($refs.button)"
        @click.stop
        :class="panelPlacementClassesValue"
        class="z-50 flex min-w-72 max-w-md flex-col overflow-hidden rounded-md border border-border bg-white text-foreground shadow-md dark:bg-zinc-900 contain-[paint]"
        role="dialog"
        aria-modal="true"
    >
        <div class="flex items-center justify-between gap-2 border-b border-border/60 px-3 py-2.5">
            <div class="flex items-center gap-2">
                <div class="flex h-6 w-6 items-center justify-center rounded-full bg-muted text-[11px] text-muted-foreground">
                    <flux:icon name="clock" class="size-3" />
                </div>
                <div class="flex flex-col">
                    <span class="text-xs font-semibold tracking-wide text-muted-foreground">
                        {{ __('Activity logs for :name', ['name' => $itemName]) }}
                    </span>
                </div>
            </div>

            <button
                type="button"
                class="inline-flex h-6 w-6 items-center justify-center rounded-full text-muted-foreground hover:bg-muted/60 hover:text-foreground"
                @click="close($refs.button)"
                aria-label="{{ __('Close activity log') }}"
            >
                <flux:icon name="x-mark" class="size-3" />
            </button>
        </div>

        <div class="max-h-80 space-y-2 overflow-y-auto px-3 py-2.5 text-[11px]">
            <template x-if="logs.length === 0">
                <p class="text-muted-foreground/80">
                    {{ __('No activity yet.') }}
                </p>
            </template>

            <template x-if="logs.length > 0">
                <div class="space-y-1.5">
                    <template x-for="(log, index) in logs" :key="log.id ?? index">
                        <div class="flex items-start gap-2 rounded-md bg-muted/60 px-2 py-1.5">
                            <div class="min-w-0 flex-1 space-y-0.5">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="truncate text-[11px] font-semibold text-foreground/90" x-text="log.actorEmail"></p>
                                    <span class="shrink-0 text-[10px] text-muted-foreground/80" x-text="log.createdDisplay"></span>
                                </div>
                                <p class="text-[11px] text-muted-foreground/90" x-text="log.message || log.actionLabel"></p>
                            </div>
                        </div>
                    </template>
                </div>
            </template>
        </div>

        <div class="border-t border-border/60 px-3 py-1.5">
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium text-primary hover:text-primary/80 disabled:opacity-70"
                :class="{ 'animate-pulse': loadingMore }"
                x-show="hasMore"
                x-cloak
                :disabled="loadingMore"
                @click="loadMore()"
            >
                <flux:icon name="chevron-down" class="size-3" />
                <span x-text="loadingMore ? '{{ __('Loading...') }}' : '{{ __('Load more activity') }}'"></span>
            </button>
        </div>
    </div>
</div>


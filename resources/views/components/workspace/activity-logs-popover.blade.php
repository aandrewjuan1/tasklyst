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
    class="relative"
    x-data="{
        open: false,
        logs: @js($logsForJs),
        loggableType: @js($loggableType),
        loggableId: @js($item->id),
        kind: @js(strtolower((string) ($kind ?? ''))),
        loadingMore: false,
        hasMore: @js($initialHasMore),
        loadMoreErrorToast: @js(__('Could not load more activity. Please try again.')),
        panelStyle: {},
        openedAt: 0,
        alignPreference: @js(($align ?? 'end') === 'end' ? 'end' : 'start'),
        panelWidthEst: 384,
        panelHeightEst: 320,

        maybeLoadInitialLogs() {
            if (this.logs.length === 0 && this.hasMore && !this.loadingMore) {
                this.loadMore();
            }
        },

        openFromMenu() {
            if (this.open) {
                return;
            }

            const button = this.$refs.button;
            if (!button) {
                const vw = window.innerWidth;
                const vh = window.innerHeight;
                const margin = 12;
                const maxW = Math.min(this.panelWidthEst, vw - 2 * margin);
                this.panelStyle = {
                    top: `${margin}px`,
                    left: `${margin}px`,
                    width: `${Math.round(maxW)}px`,
                    maxHeight: `${Math.round(vh - 2 * margin)}px`,
                };
                this.open = true;
                this.openedAt = Date.now();
                this.$dispatch('dropdown-opened');
                this.maybeLoadInitialLogs();

                return;
            }

            const rect = button.getBoundingClientRect();
            const vh = window.innerHeight;
            const vw = window.innerWidth;
            const margin = 12;
            const gap = 8;
            const contentLeft = vw < 768 ? 16 : 320;

            const maxW = Math.min(this.panelWidthEst, vw - 2 * margin);
            const maxH = vh - 2 * margin;
            const estH = Math.min(this.panelHeightEst, maxH);

            let top;
            if (rect.bottom + this.panelHeightEst > vh && rect.top > this.panelHeightEst) {
                top = rect.top - estH - gap;
            } else {
                top = rect.bottom + gap;
            }
            const minTop = margin;
            const maxTop = vh - margin - estH;
            top = Math.max(minTop, Math.min(top, maxTop));

            const endFits = rect.right <= vw && rect.right - maxW >= contentLeft;
            const startFits = rect.left >= contentLeft && rect.left + maxW <= vw;

            let horizontal = this.alignPreference;
            if (horizontal === 'end' && !endFits && startFits) {
                horizontal = 'start';
            } else if (horizontal === 'start' && !startFits && endFits) {
                horizontal = 'end';
            } else if (!endFits && !startFits) {
                horizontal = rect.left + rect.width / 2 < vw / 2 ? 'start' : 'end';
            }

            let left;
            if (horizontal === 'end') {
                left = rect.right - maxW;
            } else {
                left = rect.left;
            }
            left = Math.max(margin, Math.min(left, vw - margin - maxW));

            this.panelStyle = {
                top: `${Math.round(top)}px`,
                left: `${Math.round(left)}px`,
                width: `${Math.round(maxW)}px`,
                maxHeight: `${Math.round(maxH)}px`,
            };

            this.open = true;
            this.openedAt = Date.now();
            this.$dispatch('dropdown-opened');
            this.maybeLoadInitialLogs();
        },

        close(focusAfter) {
            if (!this.open) return;

            this.open = false;

            const leaveMs = 100;
            setTimeout(() => {
                this.panelStyle = {};
                this.$dispatch('dropdown-closed');
            }, leaveMs);

            focusAfter && focusAfter.focus();
        },

        handleWindowFocus(event) {
            if (!this.open) return;

            const panel = this.$refs.panel;
            if (!panel) return;

            const enoughTimePassed = Date.now() - this.openedAt > 200;
            if (enoughTimePassed && !panel.contains(event.target)) {
                this.close(this.$refs.button);
            }
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
                    this.logs.push(...newLogs);
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
    @focusin.window="handleWindowFocus($event)"
    @workspace-open-activity-logs.window="
        if ($event.detail && Number($event.detail.id ?? null) === Number(loggableId) && (!$event.detail.kind || $event.detail.kind === kind)) {
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
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-cloak
        @click.outside="close($refs.button)"
        @click.stop
        :style="panelStyle"
        class="fixed z-50 flex min-w-72 max-w-md flex-col overflow-hidden rounded-md border border-border bg-white text-foreground shadow-md dark:bg-zinc-900 contain-[paint]"
        role="dialog"
        aria-modal="true"
    >
        <div class="flex shrink-0 items-center justify-between gap-2 border-b border-border/60 px-3 py-2.5">
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

        {{-- ~4 log rows visible; rest scrolls (avoids huge panel + keeps load-more footer visible). --}}
        <div class="min-h-0 max-h-56 flex-1 overflow-y-auto overscroll-contain px-3 py-2.5 text-[11px] [scrollbar-gutter:stable]">
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

        <div class="shrink-0 border-t border-border/60 bg-white px-3 py-2 dark:bg-zinc-900">
            <button
                type="button"
                class="inline-flex w-full items-center justify-center gap-1.5 rounded-md border border-border/60 bg-muted/40 px-3 py-2 text-[11px] font-medium text-foreground transition-colors hover:bg-muted/70 disabled:opacity-70 dark:border-zinc-600/60 dark:bg-zinc-800/80 dark:hover:bg-zinc-800"
                :class="{ 'animate-pulse': loadingMore }"
                x-show="hasMore"
                x-cloak
                :disabled="loadingMore"
                @click="loadMore()"
            >
                <flux:icon name="chevron-down" class="size-3.5 shrink-0" />
                <span x-text="loadingMore ? '{{ __('Loading...') }}' : '{{ __('Load more activity') }}'"></span>
            </button>
        </div>
    </div>
</div>


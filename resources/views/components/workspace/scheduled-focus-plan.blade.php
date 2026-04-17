@props([
    'groups' => ['today' => [], 'tomorrow' => [], 'upcoming' => []],
    'totalCount' => 0,
])

@php
    $todayItems = is_array($groups['today'] ?? null) ? $groups['today'] : [];
    $tomorrowItems = is_array($groups['tomorrow'] ?? null) ? $groups['tomorrow'] : [];
    $upcomingItems = is_array($groups['upcoming'] ?? null) ? $groups['upcoming'] : [];
@endphp

<section
    x-data="{
        expanded: true,
        storageKey: 'workspace-scheduled-focus-collapsed-v1',
        init() {
            const raw = window.localStorage.getItem(this.storageKey);
            if (raw === 'true') {
                this.expanded = false;
            }
        },
        toggle() {
            this.expanded = !this.expanded;
            window.localStorage.setItem(this.storageKey, this.expanded ? 'false' : 'true');
        },
    }"
    class="rounded-xl border border-brand-blue/20 bg-white/90 px-3 py-3 shadow-sm ring-1 ring-brand-blue/10 dark:border-brand-blue/30 dark:bg-zinc-900/55 dark:ring-white/5 sm:px-4 sm:py-4"
>
    <div class="flex items-center justify-between gap-3">
        <div class="min-w-0">
            <flux:text class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-blue dark:text-brand-light-blue">
                {{ __('Scheduled focus') }}
            </flux:text>
            <flux:heading size="sm" class="mt-0.5">
                {{ __('Assistant plan') }}
            </flux:heading>
        </div>
        <div class="inline-flex items-center gap-2">
            <span class="inline-flex items-center rounded-full bg-brand-light-blue/70 px-2 py-0.5 text-[11px] font-semibold text-brand-navy-blue dark:bg-brand-blue/20 dark:text-brand-light-blue">
                {{ trans_choice(':count item|:count items', (int) $totalCount, ['count' => (int) $totalCount]) }}
            </span>
            <flux:button
                size="xs"
                variant="ghost"
                class="rounded-lg border border-border/60 bg-white/80 px-2.5 py-1 text-xs font-medium text-zinc-700 shadow-none hover:bg-white dark:border-border/60 dark:bg-zinc-900/30 dark:text-zinc-200 dark:hover:bg-zinc-900/50"
                x-on:click="toggle()"
            >
                <span x-text="expanded ? '{{ __('Hide') }}' : '{{ __('Show') }}'"></span>
            </flux:button>
        </div>
    </div>

    <div x-cloak x-show="expanded" x-transition.opacity.duration.150ms class="mt-3 space-y-3">
        @foreach ([
            'today' => ['label' => __('Today'), 'items' => $todayItems],
            'tomorrow' => ['label' => __('Tomorrow'), 'items' => $tomorrowItems],
            'upcoming' => ['label' => __('Upcoming'), 'items' => $upcomingItems],
        ] as $group)
            @if (count($group['items']) > 0)
                <div class="space-y-2">
                    <flux:text class="text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
                        {{ $group['label'] }}
                    </flux:text>
                    <div class="space-y-2">
                        @foreach ($group['items'] as $item)
                            <article
                                class="rounded-lg border border-border/60 bg-background/70 px-3 py-2.5 dark:border-zinc-700/60 dark:bg-zinc-900/40"
                                x-data="{
                                    rescheduleOpen: false,
                                    start: '',
                                    end: '',
                                    init() {
                                        this.start = '{{ isset($item['planned_start_at']) ? e((string) $item['planned_start_at']) : '' }}'.slice(0, 16);
                                        this.end = '{{ isset($item['planned_end_at']) ? e((string) $item['planned_end_at']) : '' }}'.slice(0, 16);
                                    },
                                    submitReschedule(id) {
                                        $wire.$parent.rescheduleScheduledFocusItem(id, this.start, this.end || null);
                                        this.rescheduleOpen = false;
                                    },
                                }"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <flux:text class="line-clamp-2 text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                            {{ (string) ($item['title'] ?? __('Scheduled item')) }}
                                        </flux:text>
                                        <flux:text class="mt-1 text-xs text-zinc-600 dark:text-zinc-400">
                                            {{ (string) ($item['time_range_label'] ?? __('No time set')) }}
                                            @if (isset($item['duration_label']) && is_string($item['duration_label']) && $item['duration_label'] !== '')
                                                · {{ $item['duration_label'] }}
                                            @endif
                                        </flux:text>
                                    </div>
                                    <span class="inline-flex shrink-0 items-center rounded-full bg-zinc-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.06em] text-zinc-700 dark:bg-zinc-800/70 dark:text-zinc-300">
                                        {{ (string) ($item['entity_label'] ?? __('Item')) }}
                                    </span>
                                </div>

                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        class="rounded-md border border-border/60 px-2 py-1 text-[11px]"
                                        wire:click="$parent.openScheduledFocusItem({{ (int) ($item['id'] ?? 0) }})"
                                    >
                                        {{ __('Open') }}
                                    </flux:button>
                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        class="rounded-md border border-border/60 px-2 py-1 text-[11px]"
                                        wire:click="$parent.markScheduledFocusInProgress({{ (int) ($item['id'] ?? 0) }})"
                                    >
                                        {{ __('Doing') }}
                                    </flux:button>
                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        class="rounded-md border border-border/60 px-2 py-1 text-[11px]"
                                        wire:click="$parent.markScheduledFocusDone({{ (int) ($item['id'] ?? 0) }})"
                                    >
                                        {{ __('Done') }}
                                    </flux:button>
                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        class="rounded-md border border-border/60 px-2 py-1 text-[11px]"
                                        x-on:click="rescheduleOpen = !rescheduleOpen"
                                    >
                                        {{ __('Reschedule') }}
                                    </flux:button>
                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        class="rounded-md border border-red-200 px-2 py-1 text-[11px] text-red-700 dark:border-red-700/60 dark:text-red-300"
                                        wire:click="$parent.dismissScheduledFocusItem({{ (int) ($item['id'] ?? 0) }})"
                                    >
                                        {{ __('Dismiss') }}
                                    </flux:button>
                                </div>

                                <div x-cloak x-show="rescheduleOpen" x-transition.opacity.duration.150ms class="mt-2 space-y-2 rounded-md border border-border/60 p-2">
                                    <div class="grid gap-2 sm:grid-cols-2">
                                        <label class="space-y-1">
                                            <span class="text-[11px] text-zinc-600 dark:text-zinc-400">{{ __('Start') }}</span>
                                            <input type="datetime-local" x-model="start" class="w-full rounded-md border border-border/70 bg-background px-2 py-1 text-xs">
                                        </label>
                                        <label class="space-y-1">
                                            <span class="text-[11px] text-zinc-600 dark:text-zinc-400">{{ __('End') }}</span>
                                            <input type="datetime-local" x-model="end" class="w-full rounded-md border border-border/70 bg-background px-2 py-1 text-xs">
                                        </label>
                                    </div>
                                    <div class="flex justify-end gap-1.5">
                                        <flux:button size="xs" variant="ghost" class="rounded-md border border-border/60 px-2 py-1 text-[11px]" x-on:click="rescheduleOpen = false">
                                            {{ __('Cancel') }}
                                        </flux:button>
                                        <flux:button
                                            size="xs"
                                            variant="primary"
                                            class="rounded-md px-2 py-1 text-[11px]"
                                            x-on:click="submitReschedule({{ (int) ($item['id'] ?? 0) }})"
                                        >
                                            {{ __('Save') }}
                                        </flux:button>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
    </div>
</section>

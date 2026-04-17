@props([
    'groups' => ['today' => [], 'tomorrow' => [], 'upcoming' => []],
    'totalCount' => 0,
])

@php
    $todayItems = is_array($groups['today'] ?? null) ? $groups['today'] : [];
    $tomorrowItems = is_array($groups['tomorrow'] ?? null) ? $groups['tomorrow'] : [];
    $upcomingItems = is_array($groups['upcoming'] ?? null) ? $groups['upcoming'] : [];
@endphp

<script>
    (() => {
        const key = 'workspace-scheduled-focus-collapsed-v1';

        try {
            document.documentElement.dataset.workspaceScheduledFocusCollapsed = window.localStorage.getItem(key) === 'true' ? 'true' : 'false';
        } catch (error) {
            document.documentElement.dataset.workspaceScheduledFocusCollapsed = 'false';
        }
    })();
</script>

<section
    x-data="{
        storageKey: 'workspace-scheduled-focus-collapsed-v1',
        expanded: document.documentElement.dataset.workspaceScheduledFocusCollapsed !== 'true',
        toggle() {
            this.expanded = !this.expanded;
            try {
                window.localStorage.setItem(this.storageKey, this.expanded ? 'false' : 'true');
            } catch (error) {
                // Ignore storage write failures (private mode, quota, etc.)
            }
            document.documentElement.dataset.workspaceScheduledFocusCollapsed = this.expanded ? 'false' : 'true';
        },
    }"
    class="rounded-xl border border-brand-blue/20 bg-white/90 px-2.5 py-2.5 shadow-sm ring-1 ring-brand-blue/10 dark:border-brand-blue/30 dark:bg-zinc-900/55 dark:ring-white/5 sm:px-3.5 sm:py-3.5"
>
    <div class="flex items-center justify-between gap-2.5 sm:gap-3">
        <div class="min-w-0">
            <flux:text class="text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-blue dark:text-brand-light-blue sm:text-[11px]">
                {{ __('Scheduled focus') }}
            </flux:text>
            <flux:heading size="sm" class="mt-0.5 text-sm sm:text-base">
                {{ __('Assistant plan') }}
            </flux:heading>
        </div>
        <div class="inline-flex items-center gap-1.5 sm:gap-2">
            <span class="inline-flex items-center rounded-full bg-brand-light-blue/70 px-1.5 py-0.5 text-[10px] font-semibold text-brand-navy-blue dark:bg-brand-blue/20 dark:text-brand-light-blue sm:px-2 sm:text-[11px]">
                {{ trans_choice(':count item|:count items', (int) $totalCount, ['count' => (int) $totalCount]) }}
            </span>
            <flux:button
                size="xs"
                variant="ghost"
                class="rounded-lg border border-border/60 bg-white/80 px-2 py-0.5 text-[11px] font-medium text-zinc-700 shadow-none hover:bg-white dark:border-border/60 dark:bg-zinc-900/30 dark:text-zinc-200 dark:hover:bg-zinc-900/50 sm:px-2.5 sm:py-1 sm:text-xs"
                x-on:click="toggle()"
            >
                <span data-scheduled-focus-toggle-label="hide" x-show="expanded">{{ __('Hide') }}</span>
                <span data-scheduled-focus-toggle-label="show" x-show="!expanded">{{ __('Show') }}</span>
            </flux:button>
        </div>
    </div>

    <div
        data-scheduled-focus-plan-content
        x-show="expanded"
        x-transition.opacity.duration.150ms
        class="mt-2.5 space-y-2 sm:mt-3 sm:space-y-2.5"
    >
        @foreach ([
            'today' => ['label' => __('Today'), 'items' => $todayItems],
            'tomorrow' => ['label' => __('Tomorrow'), 'items' => $tomorrowItems],
            'upcoming' => ['label' => __('Upcoming'), 'items' => $upcomingItems],
        ] as $group)
            @if (count($group['items']) > 0)
                <div class="space-y-1.5">
                    <flux:text class="text-[10px] font-semibold uppercase tracking-[0.08em] text-muted-foreground sm:text-[11px]">
                        {{ $group['label'] }}
                    </flux:text>
                    <div class="space-y-1.5">
                        @foreach ($group['items'] as $item)
                            @php
                                $entityType = (string) ($item['entity_type'] ?? 'task');
                                $entityTypePillClass = (string) ($item['entity_type_pill_class'] ?? 'lic-item-type-pill--task');
                                $entityId = (int) ($item['entity_id'] ?? 0);
                                $planItemId = (int) ($item['id'] ?? 0);
                            @endphp
                            <button
                                type="button"
                                wire:click="$parent.focusCalendarAgendaItem('{{ $entityType }}', {{ $entityId }}, true)"
                                x-on:click="
                                    const instant = typeof window.workspaceCalendarTryInstantFocus === 'function'
                                        && window.workspaceCalendarTryInstantFocus('{{ $entityType }}', {{ $entityId }});
                                    $wire.$parent.focusCalendarAgendaItem('{{ $entityType }}', {{ $entityId }}, !instant);
                                "
                                @disabled($entityId <= 0 || ! in_array($entityType, ['task', 'event', 'project'], true))
                                class="list-item-card flex w-full flex-col gap-1 rounded-lg px-2 py-1.5 text-left transition-all duration-200 ease-out hover:scale-[1.01] hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/40 focus-visible:ring-offset-1 focus-visible:ring-offset-white disabled:cursor-not-allowed disabled:opacity-70 disabled:hover:scale-100 dark:focus-visible:ring-brand-light-blue/50 dark:focus-visible:ring-offset-zinc-900 sm:gap-1.5 sm:px-2.5 sm:py-2"
                                data-scheduled-focus-kind="{{ $entityType }}"
                                data-scheduled-focus-entity-id="{{ $entityId }}"
                                data-scheduled-focus-plan-item-id="{{ $planItemId }}"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <flux:text class="line-clamp-2 text-xs font-semibold leading-snug text-zinc-900 dark:text-zinc-100 sm:text-[13px] md:text-sm md:font-bold md:leading-tight">
                                            {{ (string) ($item['title'] ?? __('Scheduled item')) }}
                                        </flux:text>
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center gap-1 text-[11px] sm:gap-1.5 sm:text-xs lg:flex-nowrap">
                                    <span class="lic-item-type-pill shrink-0 px-1.5 py-0.5 text-[8px] tracking-[0.05em] sm:text-[9px] sm:tracking-[0.06em] {{ $entityTypePillClass }}">
                                        @if ($entityType === 'event')
                                            <flux:icon name="calendar" class="size-2 shrink-0 opacity-90 sm:size-2.5" />
                                        @elseif ($entityType === 'project')
                                            <flux:icon name="folder" class="size-2 shrink-0 opacity-90 sm:size-2.5" />
                                        @else
                                            <flux:icon name="clipboard-document-list" class="size-2 shrink-0 opacity-90 sm:size-2.5" />
                                        @endif
                                        {{ (string) ($item['entity_label'] ?? __('Item')) }}
                                    </span>

                                    <span class="inline-flex max-w-full items-center gap-1 rounded-full border border-border/60 bg-muted/80 px-1.5 py-0.5 text-[9px] font-medium text-muted-foreground sm:text-[10px]">
                                        <flux:icon name="clock" class="size-2 shrink-0 sm:size-2.5" />
                                        <span class="inline-flex min-w-0 items-baseline gap-1">
                                            <span class="text-[8px] font-semibold uppercase tracking-wide opacity-70 sm:text-[9px]">{{ __('Time') }}:</span>
                                            <span class="truncate uppercase">{{ (string) ($item['time_range_label'] ?? __('No time set')) }}</span>
                                        </span>
                                    </span>

                                    @if (isset($item['duration_label']) && is_string($item['duration_label']) && $item['duration_label'] !== '')
                                        <span class="inline-flex shrink-0 items-center gap-1 rounded-full border border-border/60 bg-muted/80 px-1.5 py-0.5 text-[9px] font-medium text-muted-foreground sm:text-[10px]">
                                            <flux:icon name="clock" class="size-2 shrink-0 sm:size-2.5" />
                                            <span class="inline-flex items-baseline gap-1">
                                                <span class="text-[8px] font-semibold uppercase tracking-wide opacity-70 sm:text-[9px]">{{ __('Duration') }}:</span>
                                                <span class="uppercase">{{ $item['duration_label'] }}</span>
                                            </span>
                                        </span>
                                    @endif
                                </div>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
    </div>
</section>

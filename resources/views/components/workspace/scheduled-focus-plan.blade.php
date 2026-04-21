@props([
    'groups' => ['today' => [], 'tomorrow' => [], 'upcoming' => []],
    'totalCount' => 0,
    'appearance' => 'default',
])

@php
    $appearance = in_array($appearance, ['default', 'compact'], true) ? $appearance : 'default';
    $isCompact = $appearance === 'compact';
    $todayItems = is_array($groups['today'] ?? null) ? $groups['today'] : [];
    $tomorrowItems = is_array($groups['tomorrow'] ?? null) ? $groups['tomorrow'] : [];
    $upcomingItems = is_array($groups['upcoming'] ?? null) ? $groups['upcoming'] : [];
    $panelId = $isCompact ? 'workspace-scheduled-focus-compact' : 'workspace-scheduled-focus-default';
    $grouped = [
        'today' => ['label' => __('Today'), 'items' => $todayItems],
        'tomorrow' => ['label' => __('Tomorrow'), 'items' => $tomorrowItems],
        'upcoming' => ['label' => __('Upcoming'), 'items' => $upcomingItems],
    ];
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
        removedEntityKeys: {},
        reconcileTimer: null,
        lastInfoToastAtMs: 0,
        infoToastCooldownMs: 1200,
        entityKey(kind, entityId) {
            return String(kind || '') + ':' + String(Number(entityId) || 0);
        },
        isEntityRemoved(kind, entityId) {
            const key = this.entityKey(kind, entityId);
            return this.removedEntityKeys[key] === true;
        },
        removeEntity(kind, entityId) {
            const key = this.entityKey(kind, entityId);
            if (key.endsWith(':0')) {
                return;
            }
            this.removedEntityKeys[key] = true;
        },
        emitInfoToast(message) {
            const nowMs = Date.now();
            if ((nowMs - Number(this.lastInfoToastAtMs || 0)) < this.infoToastCooldownMs) {
                return;
            }
            this.lastInfoToastAtMs = nowMs;
            $wire.$dispatch('toast', {
                type: 'info',
                message: message || @js(__('Removed from Scheduled Focus because timing changed.')),
            });
        },
        requestReconcile() {
            if (this.reconcileTimer) {
                clearTimeout(this.reconcileTimer);
            }
            this.reconcileTimer = setTimeout(() => {
                $wire.$parent.$call('onAssistantSchedulePlanUpdated');
            }, 160);
        },
        handleWorkspaceTrashed(detail) {
            const kind = String(detail?.kind || '');
            const itemId = Number(detail?.id || 0);
            if (! ['task', 'event', 'project'].includes(kind) || itemId < 1) {
                return;
            }
            this.removeEntity(kind, itemId);
            this.requestReconcile();
        },
        handleWorkspacePropertyUpdated(detail) {
            const kind = String(detail?.kind || '');
            const itemId = Number(detail?.itemId || 0);
            const property = String(detail?.property || '');
            if (! ['task', 'event', 'project'].includes(kind) || itemId < 1 || property === '') {
                return;
            }
            if (['startDatetime', 'endDatetime', 'startTime', 'endTime'].includes(property)) {
                this.removeEntity(kind, itemId);
                this.emitInfoToast(@js(__('Removed from Scheduled Focus because the scheduled time changed.')));
                this.requestReconcile();
                return;
            }
            if (['recurrence', 'projectId', 'eventId', 'schoolClassId', 'status', 'title', 'name', 'subjectName', 'description'].includes(property)) {
                this.requestReconcile();
            }
        },
        async focusPlanItem(planItemId, kind, entityId) {
            const normalizedKind = String(kind || '');
            const normalizedEntityId = Number(entityId);
            const normalizedPlanItemId = Number(planItemId);

            if (!Number.isFinite(normalizedEntityId) || normalizedEntityId < 1) {
                return;
            }
            if (!Number.isFinite(normalizedPlanItemId) || normalizedPlanItemId < 1) {
                return;
            }

            const instant = typeof window.workspaceCalendarTryInstantFocus === 'function'
                && window.workspaceCalendarTryInstantFocus(normalizedKind, normalizedEntityId);
            const shouldShowLoadingSkeleton = !instant;

            if (shouldShowLoadingSkeleton) {
                window.dispatchEvent(new CustomEvent('workspace-focus-navigation-loading-start', { bubbles: true }));
            }

            try {
                await $wire.$parent.$call('focusFromScheduledPlanItem', normalizedPlanItemId);
                if (!instant && typeof window.runWorkspaceFocusToTarget === 'function') {
                    requestAnimationFrame(() => {
                        setTimeout(() => window.runWorkspaceFocusToTarget(normalizedKind, normalizedEntityId), 0);
                    });
                }
            } finally {
                if (shouldShowLoadingSkeleton) {
                    window.dispatchEvent(new CustomEvent('workspace-focus-navigation-loading-end', { bubbles: true }));
                }
            }
        },
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
    role="region"
    aria-labelledby="{{ $panelId }}-title"
    @workspace-item-trashed.window="handleWorkspaceTrashed($event.detail)"
    @workspace-item-property-updated.window="handleWorkspacePropertyUpdated($event.detail)"
    @assistant-schedule-plan-updated.window="removedEntityKeys = {}"
    @class([
        'rounded-xl border border-brand-blue/20 bg-white/90 shadow-sm ring-1 ring-brand-blue/10 dark:border-brand-blue/30 dark:bg-zinc-900/55 dark:ring-white/5',
        'px-2.5 py-2.5 sm:px-3.5 sm:py-3.5' => ! $isCompact,
        'rounded-lg px-2 py-2 sm:px-2.5 sm:py-2.5' => $isCompact,
    ])
>
    <div class="flex items-center justify-between gap-2.5 sm:gap-3">
        <div class="min-w-0">
            <flux:text id="{{ $panelId }}-title" class="{{ $isCompact ? 'text-[9px] sm:text-[10px]' : 'text-[10px] sm:text-[11px]' }} font-semibold uppercase tracking-[0.12em] text-brand-blue dark:text-brand-light-blue">
                {{ __('Scheduled focus') }}
            </flux:text>
            @if (! $isCompact)
                <flux:heading size="sm" class="mt-0.5 text-sm sm:text-base">
                    {{ __('Assistant plan') }}
                </flux:heading>
            @endif
        </div>
        <div class="inline-flex items-center gap-1.5 sm:gap-2">
            <span class="inline-flex items-center rounded-full bg-brand-light-blue/70 px-1.5 py-0.5 text-[10px] font-semibold text-brand-navy-blue dark:bg-brand-blue/20 dark:text-brand-light-blue sm:px-2 sm:text-[11px]">
                {{ trans_choice(':count item|:count items', (int) $totalCount, ['count' => (int) $totalCount]) }}
            </span>
            <flux:button
                type="button"
                size="xs"
                variant="ghost"
                class="rounded-lg border border-border/60 bg-white/80 px-2 py-0.5 text-[11px] font-medium text-zinc-700 shadow-none hover:bg-white dark:border-border/60 dark:bg-zinc-900/30 dark:text-zinc-200 dark:hover:bg-zinc-900/50 sm:px-2.5 sm:py-1 sm:text-xs"
                x-on:click="toggle()"
                x-bind:aria-expanded="expanded"
                aria-controls="{{ $panelId }}-content"
            >
                <span data-scheduled-focus-toggle-label="hide" x-show="expanded">{{ __('Hide') }}</span>
                <span data-scheduled-focus-toggle-label="show" x-show="!expanded">{{ __('Show') }}</span>
            </flux:button>
        </div>
    </div>

    <div
        id="{{ $panelId }}-content"
        data-scheduled-focus-plan-content
        x-show="expanded"
        x-transition.opacity.duration.150ms
        @class([
            'mt-2.5 space-y-2 sm:mt-3 sm:space-y-2.5' => ! $isCompact,
            'mt-2 max-h-40 space-y-2 overflow-y-auto sm:max-h-48' => $isCompact,
        ])
    >
        @foreach ($grouped as $group)
            @if (count($group['items']) > 0)
                <div @class(['space-y-1.5', 'shrink-0' => $isCompact])>
                    <flux:text class="{{ $isCompact ? 'text-[9px]' : 'text-[10px] sm:text-[11px]' }} font-semibold uppercase tracking-[0.08em] text-muted-foreground">
                        {{ $group['label'] }}
                    </flux:text>
                    <div @class([
                        'space-y-1.5',
                        'flex gap-2 overflow-x-auto pb-1 pt-0.5' => $isCompact,
                    ])>
                        @foreach ($group['items'] as $item)
                            @php
                                $entityType = (string) ($item['entity_type'] ?? 'task');
                                $entityTypePillClass = (string) ($item['entity_type_pill_class'] ?? 'lic-item-type-pill--task');
                                $entityId = (int) ($item['entity_id'] ?? 0);
                                $planItemId = (int) ($item['id'] ?? 0);
                            @endphp
                            <button
                                type="button"
                                wire:key="scheduled-focus-{{ $planItemId }}"
                                @disabled($planItemId <= 0 || ! in_array($entityType, ['task', 'event', 'project'], true))
                                x-show="!isEntityRemoved('{{ $entityType }}', {{ $entityId }})"
                                @class([
                                    'list-item-card flex w-full flex-col gap-1 rounded-lg text-left transition-all duration-200 ease-out hover:scale-[1.01] hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/40 focus-visible:ring-offset-1 focus-visible:ring-offset-white disabled:cursor-not-allowed disabled:opacity-70 disabled:hover:scale-100 dark:focus-visible:ring-brand-light-blue/50 dark:focus-visible:ring-offset-zinc-900',
                                    'px-2 py-1.5 sm:gap-1.5 sm:px-2.5 sm:py-2' => ! $isCompact,
                                    'min-w-[11rem] max-w-[14rem] shrink-0 px-2 py-1.5 sm:min-w-[12rem]' => $isCompact,
                                ])
                                @click="focusPlanItem({{ $planItemId }}, '{{ $entityType }}', {{ $entityId }})"
                                data-scheduled-focus-kind="{{ $entityType }}"
                                data-scheduled-focus-entity-id="{{ $entityId }}"
                                data-scheduled-focus-plan-item-id="{{ $planItemId }}"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <flux:text @class([
                                            'line-clamp-2 font-semibold leading-snug text-zinc-900 dark:text-zinc-100',
                                            'text-xs sm:text-[13px] md:text-sm md:font-bold md:leading-tight' => ! $isCompact,
                                            'line-clamp-2 text-[11px] font-semibold leading-snug sm:text-xs' => $isCompact,
                                        ])>
                                            {{ (string) ($item['title'] ?? __('Scheduled item')) }}
                                        </flux:text>
                                        @if (($item['is_rescheduled'] ?? false) === true)
                                            <span class="mt-1 inline-flex items-center rounded-full border border-amber-300/70 bg-amber-100/80 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-[0.05em] text-amber-900 dark:border-amber-300/40 dark:bg-amber-500/15 dark:text-amber-200">
                                                {{ __('Rescheduled') }}
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                <div @class([
                                    'flex flex-wrap items-center gap-1 text-[11px] sm:gap-1.5 sm:text-xs lg:flex-nowrap',
                                    'gap-1 text-[10px] sm:gap-1 sm:text-[11px]' => $isCompact,
                                ])>
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

                                    @if (isset($item['duration_label']) && is_string($item['duration_label']) && $item['duration_label'] !== '' && ! $isCompact)
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

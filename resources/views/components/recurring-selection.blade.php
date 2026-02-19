@props([
    'model' => 'formData.item.recurrence',
    'triggerLabel' => 'Repeats',
    'position' => 'bottom',
    'align' => 'end',
    'initialValue' => null,
    'compactWhenDisabled' => false,
    'kind' => null,
    'readonly' => false,
    'hideWhenDisabled' => false,
    'recurringEventId' => null,
    'recurringTaskId' => null,
])

@php
    $notSetLabel = __('Not set');
    $initialRecurrence = $initialValue ?? [
        'enabled' => false,
        'type' => null,
        'interval' => 1,
        'daysOfWeek' => [],
        'startDatetime' => null,
        'endDatetime' => null,
    ];
    $initialDisplayLabel = $notSetLabel;
    if (($initialRecurrence['enabled'] ?? false) && ($initialRecurrence['type'] ?? null)) {
        $type = $initialRecurrence['type'];
        $interval = (int) ($initialRecurrence['interval'] ?? 1);
        $daysOfWeek = $initialRecurrence['daysOfWeek'] ?? [];
        $dayDisplayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $typeLabels = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly'];
        if ($type === 'weekly' && is_array($daysOfWeek) && count($daysOfWeek) > 0) {
            $dayNames = implode(', ', array_map(fn ($d) => $dayDisplayLabels[$d] ?? '', $daysOfWeek));
            $intervalPart = $interval === 1 ? 'Weekly' : 'Every ' . $interval . ' weeks';
            $initialDisplayLabel = $intervalPart . ' (' . $dayNames . ')';
        } elseif ($interval === 1) {
            $initialDisplayLabel = $typeLabels[$type] ?? ucfirst($type);
        } else {
            $typePlural = ['daily' => 'days', 'weekly' => 'weeks', 'monthly' => 'months', 'yearly' => 'years'][$type] ?? '';
            $initialDisplayLabel = $typePlural ? 'Every ' . $interval . ' ' . $typePlural : ($typeLabels[$type] ?? ucfirst($type));
        }
    }

    $isInitiallyEnabled = (bool) ($initialRecurrence['enabled'] ?? false);
    $shouldRenderCompact = (bool) $compactWhenDisabled && ! $isInitiallyEnabled;
    $shouldHideWhenDisabled = (bool) $hideWhenDisabled && (bool) $readonly && ! $isInitiallyEnabled;

    $triggerBaseClass = 'cursor-pointer inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 font-medium transition-[box-shadow,transform] duration-150 ease-out';
    $triggerInitialStateClass = $shouldRenderCompact
        ? 'border-border/60 bg-muted text-muted-foreground'
        : ($isInitiallyEnabled
            ? 'border-indigo-500/25 bg-indigo-500/10 text-indigo-700 shadow-sm dark:text-indigo-300'
            : 'border-border/60 bg-muted text-muted-foreground');
    $triggerInitialClass = $triggerBaseClass . ' ' . $triggerInitialStateClass;

    $repeatTooltip = match ($kind) {
        'task' => __('Repeat this task'),
        'event' => __('Repeat this event'),
        default => __('Repeat this item'),
    };
    $changeTooltip = match ($kind) {
        'task' => __('Change repeat for this task'),
        'event' => __('Change repeat for this event'),
        default => __('Change repeat'),
    };
@endphp

@if(! $shouldHideWhenDisabled)
<div
    x-data="{
        readonly: @js($readonly),
        modelPath: @js($model),
        notSetLabel: @js($notSetLabel),
        compactWhenDisabled: @js((bool) $compactWhenDisabled),
        placementVertical: @js($position),
        placementHorizontal: @js($align),
        currentValue: @js($initialRecurrence),
        valueWhenOpened: null,
        initialApplied: false,
        open: false,
        // Form fields (synced from currentValue)
        enabled: @js($initialRecurrence['enabled'] ?? false),
        type: @js($initialRecurrence['type'] ?? null),
        interval: @js($initialRecurrence['interval'] ?? 1),
        daysOfWeek: @js($initialRecurrence['daysOfWeek'] ?? []),
        // UI state
        panelHeightEst: 500,
        panelWidthEst: 360,
        dayLabels: ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'],
        dayDisplayLabels: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
        dayFullLabels: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
        recurringEventId: @js($recurringEventId ?? null),
        recurringTaskId: @js($recurringTaskId ?? null),
        skippedDates: [],
        loadingSkipped: false,
        restoreInProgressIds: [],
        skippedDatesSectionLabel: @js(__('Skipped dates')),
        noSkippedDatesLabel: @js(__('No skipped dates')),
        restoreOccurrenceLabel: @js(__('Restore')),
        restoreRestoringLabel: @js(__('Restoring...')),
        restoreErrorToast: @js(__('Could not restore occurrence. Please try again.')),
        restoreErrorPermission: @js(__('You do not have permission to restore this occurrence.')),
        restoreErrorNotFound: @js(__('Exception not found.')),
        restoreErrorValidation: @js(__('Invalid request. Please try again.')),

        init() {
            this.applyInitialValue();
            this.$watch('enabled', (value) => {
                if (value && !this.type) {
                    this.type = 'daily';
                }
            });
        },

        applyInitialValue() {
            if (this.initialApplied) return;
            this.initialApplied = true;
            const initial = this.currentValue || {};
            this.enabled = initial.enabled ?? false;
            this.type = initial.type ?? null;
            this.interval = initial.interval ?? 1;
            this.daysOfWeek = Array.isArray(initial.daysOfWeek) ? [...initial.daysOfWeek] : [];
            this.currentValue = { enabled: this.enabled, type: this.type, interval: this.interval, daysOfWeek: [...this.daysOfWeek] };
        },

        handleRecurringValue(e) {
            if (e.detail.path === this.modelPath) {
                this.currentValue = e.detail.value || { enabled: false, type: null, interval: 1, daysOfWeek: [] };
                this.initialApplied = false;
                this.applyInitialValue();
            }
        },

        handleRecurringRevert(e) {
            if (e.detail.path === this.modelPath) {
                this.currentValue = e.detail.value || { enabled: false, type: null, interval: 1, daysOfWeek: [] };
                this.initialApplied = false;
                this.applyInitialValue();
                this.close(this.$refs.button);
            }
        },

        toggle() {
            if (this.readonly) return;
            if (this.open) return this.close(this.$refs.button);
            this.$refs.button.focus();

            // When opening with recurrence disabled, auto-enable and set to daily
            if (!this.enabled) {
                this.enabled = true;
                this.type = 'daily';
                this.$dispatch('recurring-selection-updated', { path: this.modelPath, value: this.getCurrentRecurrenceValue() });
            }

            const rect = this.$refs.button.getBoundingClientRect();
            const vh = window.innerHeight;
            const vw = window.innerWidth;
            const contentLeft = 320;

            // Decide vertical placement based on available space above/below
            const spaceBelow = vh - rect.bottom;
            const spaceAbove = rect.top;

            if (spaceBelow >= this.panelHeightEst || spaceBelow >= spaceAbove) {
                this.placementVertical = 'bottom';
            } else {
                this.placementVertical = 'top';
            }

            // Decide horizontal placement similar to date-picker / tag-selection
            const endFits = rect.right <= vw && rect.right - this.panelWidthEst >= contentLeft;
            const startFits = rect.left >= contentLeft && rect.left + this.panelWidthEst <= vw;

            if (rect.left < contentLeft) {
                this.placementHorizontal = 'start';
            } else if (endFits) {
                this.placementHorizontal = 'end';
            } else if (startFits) {
                this.placementHorizontal = 'start';
            } else {
                this.placementHorizontal = rect.right > vw ? 'start' : 'end';
            }

            const openedValue = this.getCurrentRecurrenceValue();
            this.open = true;
            this.valueWhenOpened = { ...openedValue, daysOfWeek: [...openedValue.daysOfWeek] };
            this.$dispatch('recurring-selection-opened', { path: this.modelPath, value: this.getCurrentRecurrenceValue() });
            this.$dispatch('dropdown-opened');
            if (this.recurringEventId || this.recurringTaskId) {
                this.$nextTick(() => this.loadSkippedDates());
            }
        },

        async loadSkippedDates() {
            if (!this.recurringEventId && !this.recurringTaskId) return;
            if (!this.$wire?.$parent) return;
            this.loadingSkipped = true;
            try {
                const method = this.recurringEventId ? 'getEventExceptions' : 'getTaskExceptions';
                const id = this.recurringEventId ?? this.recurringTaskId;
                const list = await this.$wire.$parent.$call(method, id);
                this.skippedDates = Array.isArray(list) ? list : [];
            } catch (e) {
                this.skippedDates = [];
            } finally {
                this.loadingSkipped = false;
            }
        },

        async restoreException(ex) {
            if (this.restoreInProgressIds.includes(ex.id)) return;
            if (!this.$wire?.$parent) return;

            // PHASE 1: Snapshot BEFORE any changes
            const snapshot = this.skippedDates.map((e) => ({ ...e }));

            this.restoreInProgressIds = [...this.restoreInProgressIds, ex.id];
            // PHASE 2: Optimistic UI update - remove row immediately
            this.skippedDates = this.skippedDates.filter((e) => e.id !== ex.id);

            try {
                const method = this.recurringEventId ? 'restoreRecurringEventOccurrence' : 'restoreRecurringTaskOccurrence';
                // PHASE 3: Call server asynchronously
                const promise = this.$wire.$parent.$call(method, ex.id);
                // PHASE 4: Handle response
                const ok = await promise;
                if (!ok) {
                    // PHASE 5: Rollback on error
                    this.skippedDates = snapshot;
                    this.$wire.$dispatch('toast', { type: 'error', message: this.getRestoreErrorMessage(null) });
                }
            } catch (e) {
                // PHASE 5: Rollback on error
                this.skippedDates = snapshot;
                this.$wire.$dispatch('toast', { type: 'error', message: this.getRestoreErrorMessage(e) });
            } finally {
                this.restoreInProgressIds = this.restoreInProgressIds.filter((id) => id !== ex.id);
            }
        },

        formatExceptionDate(ymd) {
            if (!ymd) return '';
            const d = new Date(ymd + 'T12:00:00');
            if (Number.isNaN(d.getTime())) return ymd;
            return d.toLocaleDateString(undefined, { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
        },

        getRestoreErrorMessage(error) {
            const status = error?.status ?? error?.statusCode;
            if (status === 403) return this.restoreErrorPermission ?? this.restoreErrorToast;
            if (status === 404) return this.restoreErrorNotFound ?? this.restoreErrorToast;
            if (status === 422) return this.restoreErrorValidation ?? this.restoreErrorToast;
            return error?.message ?? this.restoreErrorToast ?? 'Could not restore occurrence.';
        },

        close(focusAfter) {
            if (!this.open) return;
            const valueChanged = this.hasValueChanged();
            this.open = false;
            this.valueWhenOpened = null;
            if (valueChanged) {
                const value = this.getCurrentRecurrenceValue();
                this.currentValue = value;
                this.$dispatch('recurring-selection-updated', { path: this.modelPath, value });
            }
            const leaveMs = 50;
            setTimeout(() => this.$dispatch('dropdown-closed'), leaveMs);
            focusAfter && focusAfter.focus();
        },

        hasValueChanged() {
            if (!this.valueWhenOpened) {
                return true;
            }

            const current = this.getCurrentRecurrenceValue();
            const initial = this.valueWhenOpened;

            if (current.enabled !== initial.enabled) return true;
            if (current.type !== initial.type) return true;
            if (current.interval !== initial.interval) return true;

            if (current.daysOfWeek.length !== initial.daysOfWeek.length) return true;

            for (let i = 0; i < current.daysOfWeek.length; i++) {
                if (current.daysOfWeek[i] !== initial.daysOfWeek[i]) {
                    return true;
                }
            }

            return false;
        },

        getCurrentRecurrenceValue() {
            return {
                enabled: this.enabled,
                type: this.type,
                interval: this.interval,
                daysOfWeek: [...this.daysOfWeek],
            };
        },

        updateField(field, value) {
            this[field] = value;
        },

        toggleDay(dayIndex) {
            const index = this.daysOfWeek.indexOf(dayIndex);
            if (index === -1) {
                this.daysOfWeek.push(dayIndex);
                this.daysOfWeek.sort((a, b) => a - b);
            } else {
                this.daysOfWeek.splice(index, 1);
            }
        },

        isDaySelected(dayIndex) {
            return this.daysOfWeek.includes(dayIndex);
        },

        formatDisplayValue() {
            // Use local state which is synced from parent when popover opens
            if (!this.enabled || !this.type) {
                return this.notSetLabel;
            }
            const typeLabels = { daily: 'Daily', weekly: 'Weekly', monthly: 'Monthly', yearly: 'Yearly' };
            const typeLabel = typeLabels[this.type] || this.type;

            if (this.type === 'weekly' && Array.isArray(this.daysOfWeek) && this.daysOfWeek.length > 0) {
                const dayNames = this.daysOfWeek.map(d => this.dayDisplayLabels[d]).join(', ');
                const intervalPart = this.interval === 1 ? 'Weekly' : `Every ${this.interval} weeks`;
                return `${intervalPart} (${dayNames})`;
            }

            if (this.interval === 1) {
                return typeLabel;
            }
            const typePlural = this.type === 'daily' ? 'days' : this.type === 'weekly' ? 'weeks' : this.type === 'monthly' ? 'months' : 'years';
            return `Every ${this.interval} ${typePlural}`;
        },

        get panelPlacementClasses() {
            const v = this.placementVertical;
            const h = this.placementHorizontal;
            if (v === 'top' && h === 'end') return 'bottom-full right-0 mb-1';
            if (v === 'top' && h === 'start') return 'bottom-full left-0 mb-1';
            if (v === 'bottom' && h === 'end') return 'top-full right-0 mt-1';
            if (v === 'bottom' && h === 'start') return 'top-full left-0 mt-1';
            return 'bottom-full right-0 mb-1';
        },

        get triggerButtonDynamicClass() {
            const state = (!this.enabled && this.compactWhenDisabled)
                ? 'border-border/60 bg-muted text-muted-foreground'
                : (this.enabled
                    ? 'border-indigo-500/25 bg-indigo-500/10 text-indigo-700 shadow-sm dark:text-indigo-300'
                    : 'border-border/60 bg-muted text-muted-foreground');
            const openState = this.open ? ' pointer-events-none shadow-md scale-[1.02]' : '';
            if (this.readonly) {
                return 'cursor-default pointer-events-none opacity-90 ' + state;
            }
            return state + openState;
        },

        get intervalLabel() {
            if (!this.type) {
                return 'day';
            }

            const base = this.type === 'daily' ? 'day' : this.type === 'weekly' ? 'week' : this.type === 'monthly' ? 'month' : 'year';

            return this.interval === 1 ? base : `${base}s`;
        },
    }"
    @recurring-value="handleRecurringValue($event)"
    @recurring-revert="handleRecurringRevert($event)"
    @keydown.escape.prevent.stop="open && close($refs.button)"
    @focusin.window="open && $refs.panel && !$refs.panel.contains($event.target) && close($refs.button)"
    x-id="['recurring-selection-dropdown']"
    class="relative inline-block"
    data-task-creation-safe
    {{ $attributes }}
>
    <flux:tooltip :content="$compactWhenDisabled ? $repeatTooltip : $changeTooltip">
        <button
            x-ref="button"
            type="button"
            @click="toggle()"
            aria-haspopup="true"
            :aria-expanded="open"
            :aria-controls="$id('recurring-selection-dropdown')"
            @if($compactWhenDisabled)
                aria-label="{{ $repeatTooltip }}"
            @endif
            :aria-readonly="readonly"
            class="{{ $triggerInitialClass }}"
            :class="triggerButtonDynamicClass"
            data-task-creation-safe
        >
            <flux:icon name="arrow-path" class="size-3" />

            <span
                class="sr-only"
                x-show="!enabled && compactWhenDisabled"
                style="{{ $shouldRenderCompact ? '' : 'display:none;' }}"
            >
                {{ $repeatTooltip }}
            </span>

            <span
                class="inline-flex items-baseline gap-1"
                x-show="enabled"
                style="{{ $isInitiallyEnabled ? '' : 'display:none;' }}"
            >
                <span
                    class="text-[10px] font-semibold uppercase tracking-wide opacity-70"
                    x-show="enabled"
                >
                    {{ __($triggerLabel) }}:
                </span>
                <span class="text-xs" x-text="formatDisplayValue()">{{ $initialDisplayLabel }}</span>
            </span>

            @if(!$readonly)
                <flux:icon
                    name="chevron-down"
                    class="size-3"
                    x-show="enabled"
                    style="{{ $isInitiallyEnabled ? '' : 'display:none;' }}"
                />
            @endif
        </button>
    </flux:tooltip>

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
        :id="$id('recurring-selection-dropdown')"
        :class="panelPlacementClasses"
        class="absolute z-50 flex min-w-80 flex-col overflow-hidden rounded-md border border-border bg-white text-foreground shadow-md dark:bg-zinc-900 contain-[paint]"
        data-task-creation-safe
    >
        <div class="flex flex-col items-center space-y-4 p-4">
            <!-- Recurrence Type Selection -->
            <div class="flex flex-col items-center">
                <label class="mb-2 block text-center text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    {{ __('How often?') }}
                </label>
                <div class="grid grid-cols-2 gap-2">
                    <button
                        type="button"
                        @click="updateField('type', 'daily')"
                        class="rounded-md border px-3 py-2 text-sm transition-colors"
                        :class="type === 'daily' ? 'border-pink-500 bg-pink-50 text-pink-900 dark:bg-pink-900/20 dark:text-pink-400' : 'border-border bg-muted/50 hover:bg-muted'"
                    >
                        {{ __('Daily') }}
                    </button>
                    <button
                        type="button"
                        @click="updateField('type', 'weekly')"
                        class="rounded-md border px-3 py-2 text-sm transition-colors"
                        :class="type === 'weekly' ? 'border-pink-500 bg-pink-50 text-pink-900 dark:bg-pink-900/20 dark:text-pink-400' : 'border-border bg-muted/50 hover:bg-muted'"
                    >
                        {{ __('Weekly') }}
                    </button>
                    <button
                        type="button"
                        @click="updateField('type', 'monthly')"
                        class="rounded-md border px-3 py-2 text-sm transition-colors"
                        :class="type === 'monthly' ? 'border-pink-500 bg-pink-50 text-pink-900 dark:bg-pink-900/20 dark:text-pink-400' : 'border-border bg-muted/50 hover:bg-muted'"
                    >
                        {{ __('Monthly') }}
                    </button>
                    <button
                        type="button"
                        @click="updateField('type', 'yearly')"
                        class="rounded-md border px-3 py-2 text-sm transition-colors"
                        :class="type === 'yearly' ? 'border-pink-500 bg-pink-50 text-pink-900 dark:bg-pink-900/20 dark:text-pink-400' : 'border-border bg-muted/50 hover:bg-muted'"
                    >
                        {{ __('Yearly') }}
                    </button>
                </div>
            </div>

            <!-- Interval Input -->
            <template x-if="type">
                <div class="flex flex-nowrap items-center justify-center gap-2">
                    <span class="shrink-0 text-sm text-muted-foreground">{{ __('Every') }}</span>
                    <div class="w-12 shrink-0">
                        <flux:input
                            type="number"
                            min="1"
                            x-model.number="interval"
                            class="w-full min-w-0"
                            size="sm"
                        />
                    </div>
                    <span
                        class="shrink-0 text-sm text-muted-foreground"
                        x-text="intervalLabel"
                    ></span>
                </div>
            </template>

            <!-- Days of Week (Weekly only) -->
            <template x-if="type === 'weekly'">
                <div class="flex flex-col items-center">
                    <label class="mb-2 block text-center text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        {{ __('On which days?') }}
                    </label>
                    <div class="grid grid-cols-7 gap-1.5">
                        <template x-for="(dayLabel, index) in dayLabels" :key="index">
                            <button
                                type="button"
                                @click="toggleDay(index)"
                                class="rounded-md border px-2 py-1.5 text-xs transition-colors"
                                :class="isDaySelected(index) ? 'border-pink-500 bg-pink-50 text-pink-900 dark:bg-pink-900/20 dark:text-pink-400 font-semibold' : 'border-border bg-muted/50 hover:bg-muted'"
                                x-text="dayLabel"
                            ></button>
                        </template>
                    </div>
                </div>
            </template>

            <template x-if="(recurringEventId || recurringTaskId) && enabled">
                <div class="flex w-full flex-col gap-2 border-t border-border/60 pt-3">
                    <label class="block text-center text-xs font-semibold uppercase tracking-wide text-muted-foreground" x-text="skippedDatesSectionLabel"></label>
                    <div x-show="loadingSkipped" class="text-center text-xs text-muted-foreground">{{ __('Loading...') }}</div>
                    <template x-if="!loadingSkipped && skippedDates.length === 0">
                        <p class="text-center text-xs text-muted-foreground" x-text="noSkippedDatesLabel"></p>
                    </template>
                    <template x-if="!loadingSkipped && skippedDates.length > 0">
                        <ul class="max-h-32 space-y-1 overflow-y-auto text-xs">
                            <template x-for="ex in skippedDates" :key="ex.id">
                                <li class="flex items-center justify-between gap-2 rounded-md border border-border/60 bg-muted/30 px-2 py-1.5">
                                    <span class="min-w-0 truncate" :title="ex.reason || ex.exception_date" x-text="formatExceptionDate(ex.exception_date)"></span>
                                    <button
                                        type="button"
                                        class="shrink-0 inline-flex items-center gap-1 text-primary hover:underline disabled:opacity-70"
                                        :disabled="restoreInProgressIds.includes(ex.id)"
                                        :aria-label="restoreInProgressIds.includes(ex.id) ? restoreRestoringLabel : (restoreOccurrenceLabel + ' ' + formatExceptionDate(ex.exception_date))"
                                        :aria-busy="restoreInProgressIds.includes(ex.id)"
                                        @click.throttle.250ms="restoreException(ex)"
                                    >
                                        <span x-show="!restoreInProgressIds.includes(ex.id)" x-cloak x-text="restoreOccurrenceLabel"></span>
                                        <span x-show="restoreInProgressIds.includes(ex.id)" x-cloak class="inline-flex items-center gap-1">
                                            <flux:icon name="arrow-path" class="size-3 animate-spin" />
                                            <span x-text="restoreRestoringLabel"></span>
                                        </span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </template>
                </div>
            </template>

            <div class="flex w-full justify-center border-t border-border/60 pt-3">
                <button
                    type="button"
                    @click="enabled = false; type = null; daysOfWeek = []; close($refs.button)"
                    class="text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                >
                    {{ __('Don\'t repeat') }}
                </button>
            </div>
        </div>
    </div>
</div>
@endif

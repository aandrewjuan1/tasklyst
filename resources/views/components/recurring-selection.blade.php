@props([
    'model' => 'formData.item.recurrence',
    'triggerLabel' => 'Recurring',
    'position' => 'bottom',
    'align' => 'end',
    'initialValue' => null,
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
        $dayDisplayLabels = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];
        $typeLabels = ['daily' => 'DAILY', 'weekly' => 'WEEKLY', 'monthly' => 'MONTHLY', 'yearly' => 'YEARLY'];
        if ($type === 'weekly' && is_array($daysOfWeek) && count($daysOfWeek) > 0) {
            $dayNames = implode(', ', array_map(fn ($d) => $dayDisplayLabels[$d] ?? '', $daysOfWeek));
            $intervalPart = $interval === 1 ? 'WEEKLY' : 'EVERY ' . $interval . ' WEEKS';
            $initialDisplayLabel = $intervalPart . ' (' . $dayNames . ')';
        } elseif ($interval === 1) {
            $initialDisplayLabel = $typeLabels[$type] ?? strtoupper($type);
        } else {
            $typePlural = ['daily' => 'DAYS', 'weekly' => 'WEEKS', 'monthly' => 'MONTHS', 'yearly' => 'YEARS'][$type] ?? '';
            $initialDisplayLabel = $typePlural ? 'EVERY ' . $interval . ' ' . $typePlural : ($typeLabels[$type] ?? strtoupper($type));
        }
    }
@endphp

<div
    x-data="{
        modelPath: @js($model),
        notSetLabel: @js($notSetLabel),
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
        dayDisplayLabels: ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'],
        dayFullLabels: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],

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
            if (this.open) return this.close(this.$refs.button);
            this.$refs.button.focus();

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

            this.open = true;
            this.valueWhenOpened = JSON.stringify(this.getCurrentRecurrenceValue());
            this.$dispatch('recurring-selection-opened', { path: this.modelPath, value: this.getCurrentRecurrenceValue() });
            this.$dispatch('dropdown-opened');
        },

        close(focusAfter) {
            if (!this.open) return;
            const valueChanged = JSON.stringify(this.getCurrentRecurrenceValue()) !== this.valueWhenOpened;
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
            const typeLabels = { daily: 'DAILY', weekly: 'WEEKLY', monthly: 'MONTHLY', yearly: 'YEARLY' };
            const typeLabel = typeLabels[this.type] || this.type;

            if (this.type === 'weekly' && Array.isArray(this.daysOfWeek) && this.daysOfWeek.length > 0) {
                const dayNames = this.daysOfWeek.map(d => this.dayDisplayLabels[d]).join(', ');
                const intervalPart = this.interval === 1 ? 'WEEKLY' : `EVERY ${this.interval} WEEKS`;
                return `${intervalPart} (${dayNames})`;
            }

            if (this.interval === 1) {
                return typeLabel;
            }
            const typePlural = this.type === 'daily' ? 'DAYS' : this.type === 'weekly' ? 'WEEKS' : this.type === 'monthly' ? 'MONTHS' : 'YEARS';
            return `EVERY ${this.interval} ${typePlural}`;
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

        get intervalLabel() {
            if (!this.type) return 'Every';
            const typeText = this.type === 'daily' ? 'day' : this.type === 'weekly' ? 'week' : this.type === 'monthly' ? 'month' : 'year';
            return `Every ${this.interval} ${typeText}${this.interval !== 1 ? 's' : ''}`;
        },
    }"
    @recurring-value="handleRecurringValue($event)"
    @recurring-revert="handleRecurringRevert($event)"
    @keydown.escape.prevent.stop="close($refs.button)"
    @focusin.window="($refs.panel && !$refs.panel.contains($event.target)) && close()"
    x-id="['recurring-selection-dropdown']"
    class="relative inline-block"
    data-task-creation-safe
    {{ $attributes }}
>
    <button
        x-ref="button"
        type="button"
        @click="toggle()"
        aria-haspopup="true"
        :aria-expanded="open"
        :aria-controls="$id('recurring-selection-dropdown')"
        class="cursor-pointer inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground transition-[box-shadow,transform] duration-150 ease-out"
        :class="{ 'pointer-events-none': open, 'shadow-md scale-[1.02]': open }"
        data-task-creation-safe
    >
        <flux:icon name="arrow-path" class="size-3" />
        <span class="inline-flex items-baseline gap-1">
            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                {{ $triggerLabel }}:
            </span>
            <span class="text-xs uppercase" x-text="formatDisplayValue()">{{ $initialDisplayLabel }}</span>
        </span>
        <flux:icon name="chevron-down" class="size-3" />
    </button>

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
        <div class="space-y-4 p-4">
            <!-- Enable/Disable Toggle -->
            <div class="flex items-center justify-between border-b border-border/60 pb-3">
                <label class="text-sm font-medium text-foreground">
                    {{ __('Enable Recurrence') }}
                </label>
                <flux:switch
                    x-model="enabled"
                />
            </div>

            <template x-if="enabled">
                <div class="space-y-4">
                    <!-- Recurrence Type Selection -->
                    <div>
                        <label class="mb-2 block text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            {{ __('Recurrence Type') }}
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
                        <div>
                            <label class="mb-2 block text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                <span x-text="intervalLabel"></span>
                            </label>
                            <div class="flex items-center gap-2">
                                <span class="text-sm text-muted-foreground">{{ __('Every') }}</span>
                                <flux:input
                                    type="number"
                                    min="1"
                                    x-model.number="interval"
                                    class="w-20"
                                    size="sm"
                                />
                                <span class="text-sm text-muted-foreground" x-text="type === 'daily' ? 'day(s)' : type === 'weekly' ? 'week(s)' : type === 'monthly' ? 'month(s)' : 'year(s)'"></span>
                            </div>
                        </div>
                    </template>

                    <!-- Days of Week (Weekly only) -->
                    <template x-if="type === 'weekly'">
                        <div>
                            <label class="mb-2 block text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                {{ __('Days of Week') }}
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
                </div>
            </template>
        </div>
    </div>
</div>

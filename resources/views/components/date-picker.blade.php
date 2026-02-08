@props([
    'label',
    'model',
    'type' => 'datetime-local',
    'triggerLabel' => 'Date',
    'position' => 'top',
    'align' => 'end',
    'initialValue' => null,
    'overdue' => false,
])

@php
    $notSetLabel = __('Not set');
    $initialDisplayText = $notSetLabel;
    if ($initialValue) {
        try {
            $dt = \Carbon\Carbon::parse($initialValue);
            $initialDisplayText = $type === 'datetime-local'
                ? $dt->translatedFormat('M j, Y') . ' ' . $dt->translatedFormat('g:i A')
                : $dt->translatedFormat('M j, Y');
        } catch (\Throwable $e) {
            // keep notSetLabel
        }
    }
@endphp

<div
    x-data="{
        overdue: @js($overdue),
        type: @js($type),
        modelPath: @js($model),
        notSetLabel: @js($notSetLabel),
        placementVertical: @js($position),
        placementHorizontal: @js($align),
        currentValue: @js($initialValue),
        valueWhenOpened: null,
        initialApplied: false,
        open: false,
        month: null,
        year: null,
        selectedDate: null,
        hour: '',
        minute: '',
        days: [],
        panelHeightEst: 420,
        panelWidthEst: 320,
        todayCache: null,
        valueChangedDebounceTimer: null,

        init() {
            this.applyInitialValue();
        },

        applyInitialValue() {
            if (this.initialApplied) return;
            this.initialApplied = true;
            this.parseInitial(this.currentValue);
            const baseDate = this.selectedDate ?? new Date();
            this.month = baseDate.getMonth();
            this.year = baseDate.getFullYear();
            if (this.type === 'datetime-local' && (!this.hour || !this.minute)) {
                this.setTimeFromDate(baseDate);
            }
            this.buildDays();
        },

        handleDatePickerValue(e) {
            if (e.detail.path === this.modelPath) {
                this.currentValue = e.detail.value ?? null;
                this.initialApplied = false;
                this.applyInitialValue();
            }
        },

        handleDatePickerRevert(e) {
            if (e.detail.path === this.modelPath) {
                this.currentValue = e.detail.value ?? null;
                this.initialApplied = false;
                this.applyInitialValue();
                this.close(this.$refs.button);
            }
        },

        parseIsoLocalDate(value) {
            if (!value) return null;

            try {
                if (this.type === 'date') {
                    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
                    if (!match) return null;
                    const year = Number(match[1]);
                    const month = Number(match[2]) - 1;
                    const day = Number(match[3]);
                    return new Date(year, month, day);
                }

                const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?/.exec(value);
                if (!match) return null;
                const year = Number(match[1]);
                const month = Number(match[2]) - 1;
                const day = Number(match[3]);
                const hour = Number(match[4]);
                const minute = Number(match[5]);
                const second = Number(match[6] ?? 0);

                return new Date(year, month, day, hour, minute, second);
            } catch (e) {
                return null;
            }
        },

        parseInitial(value) {
            const parsed = this.parseIsoLocalDate(value);
            if (!parsed || isNaN(parsed.getTime())) return;

            this.selectedDate = parsed;

            if (this.type === 'datetime-local') {
                this.setTimeFromDate(parsed);
            }
        },

        setTimeFromDate(date) {
            const hours = date.getHours();
            const minutes = date.getMinutes();
            this.hour = String(hours).padStart(2, '0');
            this.minute = String(minutes).padStart(2, '0');
        },

        buildDays() {
            const firstDayOfMonth = new Date(this.year, this.month, 1).getDay();
            const daysInMonth = new Date(this.year, this.month + 1, 0).getDate();

            const days = [];

            // Leading blanks.
            for (let i = 0; i < firstDayOfMonth; i++) {
                days.push({
                    label: '',
                    date: null,
                    key: `blank-${this.year}-${this.month}-l${i}`,
                });
            }

            // Current month days.
            for (let day = 1; day <= daysInMonth; day++) {
                days.push({
                    label: day,
                    date: day,
                    key: `day-${this.year}-${this.month}-${day}`,
                });
            }

            // Trailing blanks to complete the final week row (auto height).
            const remainder = days.length % 7;
            const blanksNeeded = remainder === 0 ? 0 : 7 - remainder;
            for (let i = 0; i < blanksNeeded; i++) {
                days.push({
                    label: '',
                    date: null,
                    key: `blank-${this.year}-${this.month}-t${i}`,
                });
            }

            this.days = days;
        },

        changeMonth(offset) {
            const newMonth = this.month + offset;
            const date = new Date(this.year, newMonth, 1);
            this.month = date.getMonth();
            this.year = date.getFullYear();
            this.buildDays();
        },

        selectDay(day) {
            if (!day) return;
            this.selectedDate = new Date(this.year, this.month, day);
            this.updateModel();
        },

        normalizeHour() {
            let h = parseInt(this.hour || '0', 10);
            if (isNaN(h) || h < 0) h = 0;
            if (h > 23) h = 23;
            this.hour = String(h).padStart(2, '0');
        },

        normalizeMinute() {
            let m = parseInt(this.minute || '0', 10);
            if (isNaN(m) || m < 0) m = 0;
            if (m > 59) m = 59;
            this.minute = String(m).padStart(2, '0');
        },

        updateTime() {
            this.normalizeHour();
            this.normalizeMinute();
            this.updateModel();
        },

        selectToday() {
            const now = new Date();
            this.selectedDate = now;
            if (this.type === 'datetime-local') this.setTimeFromDate(now);
            this.month = now.getMonth();
            this.year = now.getFullYear();
            this.buildDays();
            this.updateModel();
        },

        clearSelection() {
            if (this.valueChangedDebounceTimer) {
                clearTimeout(this.valueChangedDebounceTimer);
                this.valueChangedDebounceTimer = null;
            }
            this.selectedDate = null;
            this.hour = '';
            this.minute = '';
            this.currentValue = null;
            this.$dispatch('date-picker-value-changed', { path: this.modelPath, value: null });
        },

        updateModel() {
            if (!this.selectedDate) return;
            const date = new Date(this.selectedDate);
            if (this.type === 'datetime-local') {
                let hours = parseInt(this.hour || '0', 10);
                const minutes = parseInt(this.minute || '0', 10);
                if (isNaN(hours) || hours < 0 || hours > 23) hours = 0;
                date.setHours(hours, isNaN(minutes) ? 0 : minutes, 0, 0);
            } else {
                date.setHours(0, 0, 0, 0);
            }
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            let value = `${year}-${month}-${day}`;
            if (this.type === 'datetime-local') {
                const hours = String(date.getHours()).padStart(2, '0');
                const minutes = String(date.getMinutes()).padStart(2, '0');
                value += `T${hours}:${minutes}:00`;
            }
            this.currentValue = value;
            
            // Debounce the event dispatch to prevent UI blocking
            if (this.valueChangedDebounceTimer) {
                clearTimeout(this.valueChangedDebounceTimer);
            }
            this.valueChangedDebounceTimer = setTimeout(() => {
                this.$dispatch('date-picker-value-changed', { path: this.modelPath, value: this.currentValue });
                this.valueChangedDebounceTimer = null;
            }, 150);
        },

        isSelected(day) {
            if (!this.selectedDate || !day) return false;
            return this.selectedDate.getFullYear() === this.year && this.selectedDate.getMonth() === this.month && this.selectedDate.getDate() === day;
        },

        isToday(day) {
            if (!day) return false;
            if (!this.todayCache) {
                const today = new Date();
                this.todayCache = {
                    year: today.getFullYear(),
                    month: today.getMonth(),
                    date: today.getDate(),
                };
            }
            return this.todayCache.year === this.year && this.todayCache.month === this.month && this.todayCache.date === day;
        },

        dayButtonClasses(day) {
            if (!day?.date) {
                // Keep grid spacing, but avoid hover/selected artifacts on empty cells.
                return 'cursor-default opacity-0';
            }

            if (this.isSelected(day.date)) {
                return 'cursor-pointer bg-pink-500 text-white shadow-sm';
            }

            if (this.isToday(day.date)) {
                return 'cursor-pointer text-pink-600 dark:text-pink-400';
            }

            return 'cursor-pointer text-zinc-700 dark:text-zinc-300';
        },

        get monthLabel() {
            const date = new Date(this.year, this.month, 1);
            return date.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
        },

        /** True if overdue from server/parent OR if the selected date is before today (optimistic). Only applies to end/due date picker, not start. */
        get effectiveOverdue() {
            const isEndDate = this.modelPath && String(this.modelPath).includes('endDatetime');
            if (!isEndDate) return false;
            if (this.currentValue) {
                const parsed = this.parseIsoLocalDate(this.currentValue);
                if (parsed && !isNaN(parsed.getTime())) {
                    const todayStart = new Date();
                    todayStart.setHours(0, 0, 0, 0);
                    if (parsed >= todayStart) return false;
                    return true;
                }
            }
            return this.overdue;
        },

        formatDisplayValue(value) {
            if (!value) return this.notSetLabel;
            try {
                const date = new Date(value);
                if (isNaN(date.getTime())) return this.notSetLabel;
                if (this.type === 'datetime-local') {
                    const dateStr = date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
                    const timeStr = date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
                    return dateStr + ' ' + timeStr;
                } else {
                    return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
                }
            } catch (e) {
                return this.notSetLabel;
            }
        },

        toggle() {
            if (this.open) return this.close(this.$refs.button);
            this.$refs.button.focus();
            const rect = this.$refs.button.getBoundingClientRect();
            const vh = window.innerHeight;
            const vw = window.innerWidth;
            const contentLeft = 320;
            if (rect.bottom + this.panelHeightEst > vh && rect.top > this.panelHeightEst) {
                this.placementVertical = 'top';
            } else {
                this.placementVertical = 'bottom';
            }
            const endFits = rect.right <= vw && rect.right - this.panelWidthEst >= contentLeft;
            const startFits = rect.left >= contentLeft && rect.left + this.panelWidthEst <= vw;
            if (rect.left < contentLeft) this.placementHorizontal = 'start';
            else if (endFits) this.placementHorizontal = 'end';
            else if (startFits) this.placementHorizontal = 'start';
            else this.placementHorizontal = rect.right > vw ? 'start' : 'end';
            this.open = true;
            this.valueWhenOpened = this.currentValue;
            this.$dispatch('date-picker-opened', { path: this.modelPath, value: this.currentValue });
            this.$dispatch('dropdown-opened');
        },

        close(focusAfter) {
            if (!this.open) return;
            const valueChanged = this.currentValue !== this.valueWhenOpened;
            this.open = false;
            this.valueWhenOpened = null;
            if (valueChanged) {
                this.$dispatch('date-picker-updated', { path: this.modelPath, value: this.currentValue });
            }
            const leaveMs = 50;
            setTimeout(() => this.$dispatch('dropdown-closed'), leaveMs);
            focusAfter && focusAfter.focus();
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
    }"
    @date-picker-value="handleDatePickerValue($event)"
    @date-picker-revert="handleDatePickerRevert($event)"
    @keydown.escape.prevent.stop="close($refs.button)"
    @focusin.window="($refs.panel && !$refs.panel.contains($event.target)) && close()"
    x-id="['date-picker-dropdown']"
    x-effect="const card = (typeof $parent !== 'undefined' && $parent)?.$parent; const isEndDate = modelPath && String(modelPath).includes('endDatetime'); if (isEndDate && card && (card.isOverdue !== undefined || card.clientOverdue !== undefined)) overdue = (card.isOverdue || card.clientOverdue) && !card.clientNotOverdue"
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
        :aria-controls="$id('date-picker-dropdown')"
        class="cursor-pointer inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 font-medium transition-[box-shadow,transform] duration-150 ease-out {{ $overdue ? 'border-red-500/50 bg-red-500/5 text-red-700 dark:border-red-400/40 dark:bg-red-500/10 dark:text-red-400' : 'border-border/60 bg-muted text-muted-foreground' }}"
        x-bind:class="[
            effectiveOverdue ? 'border-red-500/50 bg-red-500/5 text-red-700 dark:border-red-400/40 dark:bg-red-500/10 dark:text-red-400' : 'border-border/60 bg-muted text-muted-foreground',
            { 'pointer-events-none': open, 'shadow-md scale-[1.02]': open }
        ]"
        data-task-creation-safe
    >
        <span class="inline-flex {{ $overdue ? 'text-red-600 dark:text-red-400' : '' }}" x-bind:class="effectiveOverdue ? 'text-red-600 dark:text-red-400' : ''">
            <flux:icon name="clock" class="size-3" />
        </span>
        <span class="inline-flex items-baseline gap-1">
            <span class="text-[10px] font-semibold uppercase tracking-wide {{ $overdue ? 'text-red-600 opacity-90 dark:text-red-400' : 'opacity-70' }}" x-bind:class="effectiveOverdue ? 'text-red-600 opacity-90 dark:text-red-400' : 'opacity-70'">
                {{ $triggerLabel }}:
            </span>
            <span class="text-xs uppercase {{ $overdue ? 'font-semibold text-red-700 dark:text-red-400' : '' }}" x-bind:class="effectiveOverdue ? 'font-semibold text-red-700 dark:text-red-400' : ''" x-text="formatDisplayValue(currentValue)">{{ $initialDisplayText }}</span>
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
        :id="$id('date-picker-dropdown')"
        :class="panelPlacementClasses"
        class="absolute z-50 flex min-w-48 flex-col overflow-hidden rounded-md border border-border bg-white text-foreground shadow-md dark:bg-zinc-900 contain-[paint]"
        data-task-creation-safe
    >
        <div class="space-y-3 p-3 pb-1 pt-2">
            <div class="pt-1 pb-1">
                <div class="mb-4 flex items-center justify-between px-1">
                    <button
                        type="button"
                        class="cursor-pointer flex h-7 w-7 items-center justify-center rounded-full text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                        @mousedown.capture.prevent.stop
                        @click.capture.prevent.stop="changeMonth(-1)"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </button>

                    <div
                        class="text-sm font-semibold text-zinc-900 dark:text-zinc-50"
                        x-text="monthLabel"
                    ></div>

                    <button
                        type="button"
                        class="cursor-pointer flex h-7 w-7 items-center justify-center rounded-full text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                        @mousedown.capture.prevent.stop
                        @click.capture.prevent.stop="changeMonth(1)"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                    </button>
                </div>

                <div class="mb-3 grid grid-cols-7 gap-1 text-center text-[11px] font-medium text-zinc-400 dark:text-zinc-500">
                    <span>Su</span>
                    <span>Mo</span>
                    <span>Tu</span>
                    <span>We</span>
                    <span>Th</span>
                    <span>Fr</span>
                    <span>Sa</span>
                </div>

                <div class="grid grid-cols-7 gap-1">
                    <template x-for="day in days" :key="day.key">
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-full text-sm transition-colors disabled:pointer-events-none"
                            :disabled="!day.date"
                            :class="dayButtonClasses(day)"
                            @click.prevent.stop="day.date && selectDay(day.date)"
                            x-text="day.label"
                        ></button>
                    </template>
                </div>

                <div class="mt-4 border-t border-zinc-100 pt-3 pb-2 text-xs dark:border-zinc-800">
                    <div class="mb-3 flex items-center justify-center gap-2">
                        <button
                            type="button"
                            class="cursor-pointer rounded-full px-2.5 py-1 text-[11px] font-medium text-pink-600 hover:bg-pink-50 dark:text-pink-400 dark:hover:bg-pink-900/20"
                            @click.prevent.stop="selectToday()"
                        >
                            Today
                        </button>
                        <button
                            type="button"
                            class="cursor-pointer rounded-full px-2.5 py-1 text-[11px] text-zinc-500 hover:bg-zinc-100 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent dark:text-zinc-400 dark:hover:bg-zinc-800 dark:disabled:hover:bg-transparent"
                            x-bind:disabled="!selectedDate"
                            @click.prevent.stop="clearSelection()"
                        >
                            Clear
                        </button>
                    </div>
                    <div
                        class="flex items-center justify-between gap-4"
                        x-show="type === 'datetime-local'"
                    >
                        <span class="font-medium text-zinc-500 dark:text-zinc-400">
                            Time
                        </span>

                        <div class="flex items-center gap-2">
                            <input
                                type="number"
                                min="0"
                                max="23"
                                x-model="hour"
                                @change="updateTime()"
                                placeholder="00"
                                class="h-8 w-12 rounded-lg border border-zinc-200 bg-zinc-50 px-1 text-center text-xs text-zinc-900 shadow-sm outline-none ring-0 focus:border-pink-500 focus:bg-white focus:ring-1 focus:ring-pink-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50 dark:focus:border-pink-400 dark:focus:ring-pink-400"
                            />
                            <span class="pb-1 text-sm text-zinc-400 dark:text-zinc-500">:</span>
                            <input
                                type="number"
                                min="0"
                                max="59"
                                x-model="minute"
                                @change="updateTime()"
                                placeholder="00"
                                class="h-8 w-12 rounded-lg border border-zinc-200 bg-zinc-50 px-1 text-center text-xs text-zinc-900 shadow-sm outline-none ring-0 focus:border-pink-500 focus:bg-white focus:ring-1 focus:ring-pink-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50 dark:focus:border-pink-400 dark:focus:ring-pink-400"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

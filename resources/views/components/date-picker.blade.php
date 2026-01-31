@props([
    'label',
    'model',
    'type' => 'datetime-local',
    'triggerLabel' => 'Date',
    'position' => 'top',
    'align' => 'end',
])

<script>
    document.addEventListener('alpine:init', () => {
        if (!Alpine._datePickerRegistered) {
            Alpine.data('datePicker', (type, modelPath, notSetLabel, defaultPosition = 'top', defaultAlign = 'end') => ({
            type: type,
            month: null,
            year: null,
            selectedDate: null,
            hour: '',
            minute: '',
            meridiem: 'AM',
            days: [],
            modelPath: modelPath,
            currentValue: null,
            notSetLabel: notSetLabel || 'Not set',
            initialApplied: false,
            open: false,
            placementVertical: defaultPosition,
            placementHorizontal: defaultAlign,
            panelHeightEst: 420,
            panelWidthEst: 320,

            init() {
                this.$nextTick(() => {
                    this.$dispatch('date-picker-request-value', { path: this.modelPath });

                    setTimeout(() => {
                        if (!this.initialApplied) {
                            this.applyInitialValue();
                        }
                    }, 100);
                });
            },

            applyInitialValue() {
                if (this.initialApplied) {
                    return;
                }
                this.initialApplied = true;
                this.parseInitial(this.currentValue);
                const baseDate = this.selectedDate ?? new Date();
                this.month = baseDate.getMonth();
                this.year = baseDate.getFullYear();
                if (this.type === 'datetime-local' && (!this.hour || !this.minute)) {
                    const now = this.selectedDate ?? new Date();
                    this.setTimeFromDate(now);
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

            getModelValue() {
                return this.currentValue;
            },

            setModelValue(value) {
                this.currentValue = value;
                this.$dispatch('date-picker-updated', {
                    path: this.modelPath,
                    value: value,
                });
            },

            parseInitial(value) {
                if (!value) {
                    return;
                }

                const parsed = new Date(value);

                if (isNaN(parsed.getTime())) {
                    return;
                }

                this.selectedDate = parsed;

                if (this.type === 'datetime-local') {
                    this.setTimeFromDate(parsed);
                }
            },

            setTimeFromDate(date) {
                let hours = date.getHours();
                const minutes = date.getMinutes();

                this.meridiem = hours >= 12 ? 'PM' : 'AM';

                let hour12 = hours % 12;
                if (hour12 === 0) {
                    hour12 = 12;
                }

                this.hour = String(hour12).padStart(2, '0');
                this.minute = String(minutes).padStart(2, '0');
            },

            buildDays() {
                const firstDayOfMonth = new Date(this.year, this.month, 1).getDay();
                const daysInMonth = new Date(this.year, this.month + 1, 0).getDate();

                this.days = [];

                for (let i = 0; i < firstDayOfMonth; i++) {
                    this.days.push({ label: '', date: null });
                }

                for (let day = 1; day <= daysInMonth; day++) {
                    this.days.push({ label: day, date: day });
                }
            },

            changeMonth(offset) {
                const newMonth = this.month + offset;
                const date = new Date(this.year, newMonth, 1);
                this.month = date.getMonth();
                this.year = date.getFullYear();
                this.buildDays();
            },

            selectDay(day) {
                if (!day) {
                    return;
                }

                this.selectedDate = new Date(this.year, this.month, day);
                this.updateModel();
            },

            normalizeHour() {
                let h = parseInt(this.hour || '0', 10);
                if (isNaN(h) || h < 1) {
                    h = 1;
                }
                if (h > 12) {
                    h = 12;
                }
                this.hour = String(h).padStart(2, '0');
            },

            normalizeMinute() {
                let m = parseInt(this.minute || '0', 10);
                if (isNaN(m) || m < 0) {
                    m = 0;
                }
                if (m > 59) {
                    m = 59;
                }
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

                if (this.type === 'datetime-local') {
                    this.setTimeFromDate(now);
                }

                this.month = now.getMonth();
                this.year = now.getFullYear();
                this.buildDays();
                this.updateModel();
            },

            clearSelection() {
                this.selectedDate = null;
                this.hour = '';
                this.minute = '';
                this.meridiem = 'AM';

                this.setModelValue(null);
            },

            updateModel() {
                if (!this.selectedDate) {
                    return;
                }

                const date = new Date(this.selectedDate);

                if (this.type === 'datetime-local') {
                    let hours = parseInt(this.hour || '12', 10);
                    const minutes = parseInt(this.minute || '0', 10);

                    if (isNaN(hours) || hours < 1 || hours > 12) {
                        hours = 12;
                    }

                    if (this.meridiem === 'PM' && hours < 12) {
                        hours += 12;
                    }

                    if (this.meridiem === 'AM' && hours === 12) {
                        hours = 0;
                    }

                    date.setHours(hours, isNaN(minutes) ? 0 : minutes, 0, 0);
                } else {
                    date.setHours(0, 0, 0, 0);
                }

                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');

                let value = `${year}-${month}-${day}`;

                if (this.type === 'datetime-local') {
                    const hours24 = String(date.getHours()).padStart(2, '0');
                    const minutes = String(date.getMinutes()).padStart(2, '0');
                    const seconds = String(date.getSeconds()).padStart(2, '0');
                    value += `T${hours24}:${minutes}:${seconds}`;
                }

                this.setModelValue(value);
            },

            isSelected(day) {
                if (!this.selectedDate || !day) {
                    return false;
                }

                return (
                    this.selectedDate.getFullYear() === this.year &&
                    this.selectedDate.getMonth() === this.month &&
                    this.selectedDate.getDate() === day
                );
            },

            isToday(day) {
                if (!day) {
                    return false;
                }

                const today = new Date();
                return (
                    today.getFullYear() === this.year &&
                    today.getMonth() === this.month &&
                    today.getDate() === day
                );
            },

            get monthLabel() {
                const date = new Date(this.year, this.month, 1);
                return date.toLocaleDateString(undefined, {
                    month: 'long',
                    year: 'numeric',
                });
            },

            formatDisplayValue(value) {
                if (!value) {
                    return this.notSetLabel;
                }
                try {
                    const date = new Date(value);
                    if (isNaN(date.getTime())) {
                        return this.notSetLabel;
                    }
                    const dateStr = date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
                    const timeStr = date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
                    return dateStr + ' ' + timeStr;
                } catch (e) {
                    return this.notSetLabel;
                }
            },

            toggle() {
                if (this.open) {
                    return this.close(this.$refs.button);
                }

                this.$refs.button.focus();

                const rect = this.$refs.button.getBoundingClientRect();
                const vh = window.innerHeight;
                const vw = window.innerWidth;

                if (rect.bottom + this.panelHeightEst > vh && rect.top > this.panelHeightEst) {
                    this.placementVertical = 'top';
                } else {
                    this.placementVertical = 'bottom';
                }
                const endFits = rect.right <= vw && rect.right - this.panelWidthEst >= 0;
                const startFits = rect.left >= 0 && rect.left + this.panelWidthEst <= vw;
                if (endFits) {
                    this.placementHorizontal = 'end';
                } else if (startFits) {
                    this.placementHorizontal = 'start';
                } else {
                    this.placementHorizontal = rect.right > vw ? 'start' : 'end';
                }

                this.open = true;
            },

            close(focusAfter) {
                if (!this.open) return;

                this.open = false;

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
            }));
            Alpine._datePickerRegistered = true;
        }
    });
</script>

<div
    x-data="datePicker(@js($type), @js($model), @js(__('Not set')), @js($position), @js($align))"
    @date-picker-value="handleDatePickerValue($event)"
    @keydown.escape.prevent.stop="close($refs.button)"
    @focusin.window="($refs.panel && !$refs.panel.contains($event.target)) && close()"
    x-id="['date-picker-dropdown']"
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
        class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground"
        data-task-creation-safe
    >
        <flux:icon name="clock" class="size-3" />
        <span class="inline-flex items-baseline gap-1">
            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                {{ $triggerLabel }}:
            </span>
            <span class="text-xs uppercase" x-text="formatDisplayValue(currentValue)"></span>
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
        <!-- Header -->
        <div class="mb-4 flex items-center justify-between px-1">
            <button
                type="button"
                class="flex h-7 w-7 items-center justify-center rounded-full text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
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
                class="flex h-7 w-7 items-center justify-center rounded-full text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                @mousedown.capture.prevent.stop
                @click.capture.prevent.stop="changeMonth(1)"
            >
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
            </button>
        </div>

        <!-- Weekday headings -->
        <div class="mb-3 grid grid-cols-7 gap-1 text-center text-[11px] font-medium text-zinc-400 dark:text-zinc-500">
            <span>Su</span>
            <span>Mo</span>
            <span>Tu</span>
            <span>We</span>
            <span>Th</span>
            <span>Fr</span>
            <span>Sa</span>
        </div>

        <!-- Days grid -->
        <div class="grid grid-cols-7 gap-1">
            <template x-for="(day, index) in days" :key="index">
                <button
                    type="button"
                    class="flex h-8 w-8 items-center justify-center rounded-full text-sm transition-colors"
                    :class="day.date
                        ? (isSelected(day.date)
                            ? 'bg-pink-500 text-white shadow-sm'
                            : (isToday(day.date)
                                ? 'text-pink-600 dark:text-pink-400'
                                : 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800'))
                        : 'pointer-events-none bg-transparent'"
                    @click.prevent.stop="selectDay(day.date)"
                    x-text="day.label"
                ></button>
            </template>
        </div>

        <!-- Time controls -->
        <div class="mt-4 border-t border-zinc-100 pt-3 pb-2 text-xs dark:border-zinc-800">
            <div class="mb-3 flex items-center justify-center gap-2">
                <button
                    type="button"
                    class="rounded-full px-2.5 py-1 text-[11px] font-medium text-pink-600 hover:bg-pink-50 dark:text-pink-400 dark:hover:bg-pink-900/20"
                    @click.prevent.stop="selectToday()"
                >
                    Today
                </button>
                <button
                    type="button"
                    class="rounded-full px-2.5 py-1 text-[11px] text-zinc-500 hover:bg-zinc-100 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent dark:text-zinc-400 dark:hover:bg-zinc-800 dark:disabled:hover:bg-transparent"
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
                        min="1"
                        max="12"
                        x-model="hour"
                        @change="updateTime()"
                        class="h-8 w-12 rounded-lg border border-zinc-200 bg-zinc-50 px-1 text-center text-xs text-zinc-900 shadow-sm outline-none ring-0 focus:border-pink-500 focus:bg-white focus:ring-1 focus:ring-pink-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50 dark:focus:border-pink-400 dark:focus:ring-pink-400"
                    />
                    <span class="pb-1 text-sm text-zinc-400 dark:text-zinc-500">:</span>
                    <input
                        type="number"
                        min="0"
                        max="59"
                        x-model="minute"
                        @change="updateTime()"
                        class="h-8 w-12 rounded-lg border border-zinc-200 bg-zinc-50 px-1 text-center text-xs text-zinc-900 shadow-sm outline-none ring-0 focus:border-pink-500 focus:bg-white focus:ring-1 focus:ring-pink-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50 dark:focus:border-pink-400 dark:focus:ring-pink-400"
                    />

                    <select
                        x-model="meridiem"
                        @change="updateTime()"
                        class="h-8 rounded-lg border border-zinc-200 bg-zinc-50 px-2 text-xs font-medium text-zinc-900 shadow-sm outline-none ring-0 focus:border-pink-500 focus:bg-white focus:ring-1 focus:ring-pink-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50 dark:focus:border-pink-400 dark:focus:ring-pink-400"
                    >
                        <option value="AM">AM</option>
                        <option value="PM">PM</option>
                    </select>
                </div>
            </div>
        </div>
    </div>
        </div>
    </div>
</div>

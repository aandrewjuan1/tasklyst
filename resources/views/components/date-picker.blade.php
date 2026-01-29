@props([
    'label',
    'model',
    'type' => 'datetime-local',
])

<script>
    document.addEventListener('alpine:init', () => {
        if (!Alpine._datePickerRegistered) {
            Alpine.data('datePicker', (type, modelPath) => ({
            type: type,
            month: null,
            year: null,
            selectedDate: null,
            hour: '',
            minute: '',
            meridiem: 'AM',
            days: [],
            modelPath: modelPath,

            init() {
                // Delay initialization to ensure $el is available
                this.$nextTick(() => {
                    this.parseInitial(this.getModelValue());

                    const baseDate = this.selectedDate ?? new Date();
                    this.month = baseDate.getMonth();
                    this.year = baseDate.getFullYear();

                    if (this.type === 'datetime-local' && (!this.hour || !this.minute)) {
                        const now = this.selectedDate ?? new Date();
                        this.setTimeFromDate(now);
                    }

                    this.buildDays();
                });
            },

            getParentScope() {
                try {
                    // Method 1: Try $root (Alpine root scope)
                    if (typeof $root !== 'undefined' && $root) {
                        if ($root.formData) {
                            return $root;
                        }
                    }

                    // Method 2: Use Alpine's closestDataStack if available
                    if (typeof Alpine !== 'undefined' && Alpine.closestDataStack) {
                        try {
                            const stack = Alpine.closestDataStack(this.$el);
                            for (let i = stack.length - 1; i >= 0; i--) {
                                if (stack[i] && stack[i].formData) {
                                    return stack[i];
                                }
                            }
                        } catch (e) {
                            // closestDataStack might not be available
                        }
                    }

                    // Method 3: Traverse DOM to find parent Alpine component
                    const el = this.$el;
                    if (!el) {
                        return null;
                    }

                    let element = el.parentElement;
                    let maxDepth = 50; // Increased depth
                    let depth = 0;

                    while (element && depth < maxDepth) {
                        // Check if this element has Alpine data
                        if (element.__x && element.__x.$data) {
                            const data = element.__x.$data;
                            if (data && data.formData) {
                                return data;
                            }
                        }

                        // Also check for x-data attribute
                        if (element.hasAttribute && element.hasAttribute('x-data')) {
                            const xDataAttr = element.getAttribute('x-data');
                            if (xDataAttr && xDataAttr.includes('formData')) {
                                if (element.__x && element.__x.$data) {
                                    return element.__x.$data;
                                }
                            }
                        }

                        element = element.parentElement;
                        depth++;
                    }

                    return null;
                } catch (e) {
                    console.error('Error in getParentScope:', e);
                    return null;
                }
            },

            getModelValue() {
                try {
                    const parentScope = this.getParentScope();
                    if (!parentScope) {
                        return null;
                    }

                    const pathParts = this.modelPath.split('.');
                    let value = parentScope;
                    for (const part of pathParts) {
                        if (value === null || value === undefined) {
                            return null;
                        }
                        value = value[part];
                    }
                    return value;
                } catch (e) {
                    console.error('Error getting model value:', e);
                    return null;
                }
            },

            setModelValue(value) {
                try {
                    const parentScope = this.getParentScope();
                    if (!parentScope) {
                        console.error('Parent scope not found for setModelValue. Using window event fallback:', this.modelPath);
                        // Fallback: Dispatch a window event that the parent can listen to
                        window.dispatchEvent(new CustomEvent('date-picker-updated', {
                            detail: {
                                path: this.modelPath,
                                value: value
                            }
                        }));
                        return;
                    }

                    const pathParts = this.modelPath.split('.');
                    let target = parentScope;

                    for (let i = 0; i < pathParts.length - 1; i++) {
                        if (!target[pathParts[i]]) {
                            target[pathParts[i]] = {};
                        }
                        target = target[pathParts[i]];
                    }
                    target[pathParts[pathParts.length - 1]] = value;
                } catch (e) {
                    console.error('Error setting model value:', e, 'Path:', this.modelPath);
                    // Fallback on error too
                    window.dispatchEvent(new CustomEvent('date-picker-updated', {
                        detail: {
                            path: this.modelPath,
                            value: value
                        }
                    }));
                }
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
            }));
            Alpine._datePickerRegistered = true;
        }
    });
</script>

<div
    class="space-y-3 px-3 pb-3 pt-1"
    x-data="datePicker(@js($type), @js($model))"
>
    <div class="pt-1 pb-2">
        <!-- Header -->
        <div class="mb-3 flex items-center justify-between px-1">
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
        <div class="mb-2 grid grid-cols-7 gap-1 text-center text-[11px] font-medium text-zinc-400 dark:text-zinc-500">
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
                    @click.prevent="selectDay(day.date)"
                    x-text="day.label"
                ></button>
            </template>
        </div>

        <!-- Time controls -->
        <div class="mt-3 border-t border-zinc-100 pt-3 text-xs dark:border-zinc-800">
            <div
                class="flex items-center justify-between"
                x-show="type === 'datetime-local'"
            >
                <span class="font-medium text-zinc-500 dark:text-zinc-400">
                    Time
                </span>

                <div class="flex items-center gap-1.5">
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

            <div class="mt-3 flex items-center justify-between">
                <button
                    type="button"
                    class="rounded-full px-3 py-1 text-xs font-medium text-pink-600 hover:bg-pink-50 dark:text-pink-400 dark:hover:bg-pink-900/20"
                    @click.prevent="selectToday()"
                >
                    Today
                </button>

                <button
                    type="button"
                    class="rounded-full px-3 py-1 text-xs text-zinc-500 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
                    x-show="selectedDate"
                    @click.prevent="clearSelection()"
                >
                    Clear
                </button>
            </div>
        </div>
    </div>
</div>

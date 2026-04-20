@props([
    'label',
    'model',
    'type' => 'datetime-local',
    'triggerLabel' => 'Date',
    'position' => 'top',
    'align' => 'end',
    'initialValue' => null,
    'overdue' => false,
    'itemId' => null,
    'readonly' => false,
    'compact' => false,
    /** School class item creation: trigger matches schedule chip style ("One meeting" + optional date); parent uses named group `group/sc` + `data-schedule-mode`. */
    'schoolClassMeetingDay' => false,
])

@php
    $notSetLabel = __('Not set');
    $initialDisplayText = $notSetLabel;
    $isEndDatePicker = $model && str_contains((string) $model, 'endDatetime');
    $initialParsedDate = null;
    $initialEffectiveOverdue = false;
    if ($initialValue) {
        try {
            $dt = \Carbon\Carbon::parse($initialValue);
            $initialParsedDate = $dt;
            $initialDisplayText = $type === 'datetime-local'
                ? $dt->translatedFormat('M j, Y') . ' ' . $dt->translatedFormat('g:i A')
                : $dt->translatedFormat('M j, Y');
        } catch (\Throwable $e) {
            // keep notSetLabel
        }
    }
    if ($isEndDatePicker) {
        $initialEffectiveOverdue = (bool) $overdue;
        if ($initialParsedDate !== null) {
            $initialEffectiveOverdue = $initialParsedDate->lt(now());
        }
    }
    $initialTriggerLabelText = ($isEndDatePicker && $initialEffectiveOverdue && (string) $triggerLabel === 'Due')
        ? 'Overdue'
        : (string) $triggerLabel;

    $compact = filter_var($compact, FILTER_VALIDATE_BOOLEAN);
    $schoolClassMeetingDay = filter_var($schoolClassMeetingDay, FILTER_VALIDATE_BOOLEAN);
    $datePickerTriggerAriaLabelBase = (string) $label;
@endphp

<style>
    /* Server-rendered first paint + Alpine reactive toggle; no dependency on app.css */
    .date-picker-root-overdue .date-picker-trigger {
        border-color: rgb(239 68 68 / 0.5);
        background-color: rgb(239 68 68 / 0.05);
        color: #b91c1c;
    }
    .dark .date-picker-root-overdue .date-picker-trigger {
        border-color: rgb(248 113 113 / 0.4);
        background-color: rgb(239 68 68 / 0.1);
        color: #f87171;
    }
    .date-picker-root-overdue .date-picker-trigger-icon {
        color: #dc2626;
    }
    .dark .date-picker-root-overdue .date-picker-trigger-icon {
        color: #f87171;
    }
    .date-picker-root-overdue .date-picker-trigger-label {
        color: #dc2626;
        opacity: 0.9;
    }
    .dark .date-picker-root-overdue .date-picker-trigger-label {
        color: #f87171;
    }
    .date-picker-root-overdue .date-picker-trigger-value {
        font-weight: 600;
        color: #b91c1c;
    }
    .dark .date-picker-root-overdue .date-picker-trigger-value {
        color: #f87171;
    }
</style>

<div
    x-data="{
        readonly: @js($readonly),
        overdue: @js($overdue),
        itemId: @js($itemId),
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
        ampm: 'AM',
        days: [],
        panelHeightEst: 420,
        panelWidthEst: 320,
        todayCache: null,
        valueChangedDebounceTimer: null,
        effectiveOverdue: @js($initialEffectiveOverdue),
        baseTriggerLabel: @js((string) $triggerLabel),

        get disabled() {
            return Boolean(this.readonly);
        },

        init() {
            this.applyInitialValue();
            this.updateEffectiveOverdue();
            if (typeof this.$effect === 'function') {
                this.$effect(() => () => {
                    if (this.valueChangedDebounceTimer) {
                        clearTimeout(this.valueChangedDebounceTimer);
                        this.valueChangedDebounceTimer = null;
                    }
                });
            }
        },

        resolveListItemCard() {
            try {
                const p = this.$parent;
                if (p && p.$parent) {
                    const card = p.$parent;
                    if (card && card.kind) {
                        return card;
                    }
                }
            } catch (err) {}
            if (this.itemId != null && typeof window !== 'undefined' && window.Alpine?.store) {
                const store = window.Alpine.store('listItemCards');
                if (store && store[this.itemId]) {
                    return store[this.itemId];
                }
            }
            return null;
        },

        terminalStatusSuppressesDueVisual() {
            const card = this.resolveListItemCard();
            const lr = typeof window !== 'undefined' ? window.__tasklystListRelevance : null;
            if (!lr || !card || !card.kind) {
                return false;
            }
            return lr.shouldSuppressOverdueVisualForStatus(card.kind, card.taskStatus, card.eventStatus);
        },

        handleWorkspaceItemUpdated(e) {
            const d = e.detail || {};
            if (this.itemId == null || String(d.itemId) !== String(this.itemId)) {
                return;
            }
            if (d.property === 'status') {
                this.updateEffectiveOverdue();
                return;
            }
            if (d.property === 'startDatetime' && this.modelPath === 'startDatetime') {
                this.currentValue = d.startDatetime ?? d.value ?? null;
                this.initialApplied = false;
                this.applyInitialValue();
                this.updateEffectiveOverdue();
                return;
            }
            if (d.property === 'endDatetime' && this.modelPath === 'endDatetime') {
                this.currentValue = d.endDatetime ?? d.value ?? null;
                this.initialApplied = false;
                this.applyInitialValue();
                this.updateEffectiveOverdue();
            }
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
            this.updateEffectiveOverdue();
        },

        handleDatePickerValue(e) {
            const d = e.detail || {};
            if (d.itemId != null && String(d.itemId) !== String(this.itemId)) {
                return;
            }
            if (d.path === this.modelPath) {
                this.currentValue = d.value ?? null;
                this.initialApplied = false;
                this.applyInitialValue();
                this.updateEffectiveOverdue();
            }
        },

        handleDatePickerRevert(e) {
            const d = e.detail || {};
            if (d.itemId != null && String(d.itemId) !== String(this.itemId)) {
                return;
            }
            if (d.path === this.modelPath) {
                this.currentValue = d.value ?? null;
                this.initialApplied = false;
                this.applyInitialValue();
                this.updateEffectiveOverdue();
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
            const hours24 = date.getHours();
            const minutes = date.getMinutes();

            let ampm = 'AM';
            let hours12 = hours24 % 12;

            if (hours24 === 0) {
                hours12 = 12;
                ampm = 'AM';
            } else if (hours24 === 12) {
                hours12 = 12;
                ampm = 'PM';
            } else if (hours24 > 12) {
                hours12 = hours24 - 12;
                ampm = 'PM';
            } else {
                ampm = 'AM';
            }

            this.hour = String(hours12 || 12).padStart(2, '0');
            this.minute = String(minutes).padStart(2, '0');
            this.ampm = ampm;
        },

        buildDays() {
            if (!this.todayCache) {
                const t = new Date();
                this.todayCache = { year: t.getFullYear(), month: t.getMonth(), date: t.getDate() };
            }
            const firstDayOfMonth = new Date(this.year, this.month, 1).getDay();
            const daysInMonth = new Date(this.year, this.month + 1, 0).getDate();
            const sel = this.selectedDate;
            const isSelectedDay = (d) => sel && this.year === sel.getFullYear() && this.month === sel.getMonth() && sel.getDate() === d;
            const isTodayDay = (d) => this.todayCache.year === this.year && this.todayCache.month === this.month && this.todayCache.date === d;

            const days = [];

            for (let i = 0; i < firstDayOfMonth; i++) {
                days.push({ label: '', date: null, key: `blank-${this.year}-${this.month}-l${i}`, isSelected: false, isToday: false });
            }

            for (let day = 1; day <= daysInMonth; day++) {
                days.push({
                    label: day,
                    date: day,
                    key: `day-${this.year}-${this.month}-${day}`,
                    isSelected: isSelectedDay(day),
                    isToday: isTodayDay(day),
                });
            }

            const remainder = days.length % 7;
            const blanksNeeded = remainder === 0 ? 0 : 7 - remainder;
            for (let i = 0; i < blanksNeeded; i++) {
                days.push({ label: '', date: null, key: `blank-${this.year}-${this.month}-t${i}`, isSelected: false, isToday: false });
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
            this.buildDays();
            this.updateModel();
        },

        normalizeHour() {
            let h = parseInt(this.hour || '0', 10);
            if (isNaN(h) || h <= 0) h = 12;
            if (h > 12) h = 12;
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
            this.ampm = 'AM';
            this.currentValue = null;
            this.updateEffectiveOverdue();
            this.$dispatch('date-picker-value-changed', { path: this.modelPath, value: null });
        },

        updateModel() {
            if (!this.selectedDate) return;
            const date = new Date(this.selectedDate);
            if (this.type === 'datetime-local') {
                let hours12 = parseInt(this.hour || '0', 10);
                if (isNaN(hours12) || hours12 <= 0) hours12 = 12;
                if (hours12 > 12) hours12 = 12;

                const minutes = parseInt(this.minute || '0', 10);
                const ampm = this.ampm === 'PM' ? 'PM' : 'AM';

                let hours24 = hours12 % 12;
                if (ampm === 'PM') {
                    hours24 += 12;
                }

                date.setHours(hours24, isNaN(minutes) ? 0 : minutes, 0, 0);
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
            this.updateEffectiveOverdue();

            // Debounce the event dispatch to prevent UI blocking
            if (this.valueChangedDebounceTimer) {
                clearTimeout(this.valueChangedDebounceTimer);
            }
            this.valueChangedDebounceTimer = setTimeout(() => {
                this.valueChangedDebounceTimer = null;
                if (this.$el?.isConnected) {
                    this.$dispatch('date-picker-value-changed', { path: this.modelPath, value: this.currentValue });
                }
            }, 150);
        },

        get monthLabel() {
            const date = new Date(this.year, this.month, 1);
            return date.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
        },

        /** True if overdue from server/parent OR if the selected datetime is in the past. Only applies to end/due date picker. */
        updateEffectiveOverdue() {
            const isEndDate = this.modelPath && String(this.modelPath).includes('endDatetime');
            if (!isEndDate) {
                this.effectiveOverdue = false;
                return;
            }
            if (this.currentValue) {
                const parsed = this.parseIsoLocalDate(this.currentValue);
                this.effectiveOverdue = parsed && !isNaN(parsed.getTime()) ? parsed < new Date() : this.overdue;
            } else {
                this.effectiveOverdue = this.overdue;
            }
            if (this.terminalStatusSuppressesDueVisual()) {
                this.effectiveOverdue = false;
            }
        },

        get triggerLabelText() {
            const isEndDate = this.modelPath && String(this.modelPath).includes('endDatetime');
            if (!isEndDate || !this.effectiveOverdue) {
                return this.baseTriggerLabel;
            }

            const card = this.resolveListItemCard();
            if (card && card.kind === 'task' && this.baseTriggerLabel === 'Due') {
                return 'Overdue';
            }

            return this.baseTriggerLabel;
        },

        formatDisplayValue(value) {
            if (!value) return this.notSetLabel;
            try {
                const date = new Date(value);
                if (isNaN(date.getTime())) return this.notSetLabel;
                if (this.type === 'datetime-local') {
                    const dateStr = date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
                    const timeStr = date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', hour12: true });
                    return dateStr + ' ' + timeStr;
                } else {
                    return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
                }
            } catch (e) {
                return this.notSetLabel;
            }
        },

        toggle() {
            if (this.readonly) return;
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
            const card = (typeof $parent !== 'undefined' && $parent)?.$parent;
            const isEndDate = this.modelPath && String(this.modelPath).includes('endDatetime');
            if (isEndDate && card && (card.isPastDue !== undefined || card.isOverdue !== undefined || card.clientOverdue !== undefined)) {
                const pastDue = card.isPastDue !== undefined ? card.isPastDue : card.isOverdue;
                this.overdue = (pastDue || card.clientOverdue) && !card.clientNotOverdue;
            }
            this.updateEffectiveOverdue();
            this.todayCache = null;

            this.open = true;
            this.valueWhenOpened = this.currentValue;
            const store = Alpine.store('datePicker') ?? { open: null };
            store.open = { panel: this.$refs.panel, close: () => this.close(this.$refs.button) };
            Alpine.store('datePicker', store);
            this.$dispatch('date-picker-opened', { path: this.modelPath, value: this.currentValue });
            this.$dispatch('dropdown-opened');
        },

        close(focusAfter) {
            if (!this.open) return;
            const state = Alpine.store('datePicker');
            if (state && state.open && state.open.panel === this.$refs.panel) {
                state.open = null;
            }
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
    @date-picker-value.window="handleDatePickerValue($event)"
    @date-picker-revert="handleDatePickerRevert($event)"
    @date-picker-revert.window="handleDatePickerRevert($event)"
    @workspace-item-property-updated.window="handleWorkspaceItemUpdated($event)"
    @keydown.escape.prevent.stop="close($refs.button)"
    x-id="['date-picker-dropdown']"
    class="relative inline-block date-picker-root {{ $initialEffectiveOverdue ? 'date-picker-root-overdue' : '' }}"
    :class="{ 'date-picker-root-overdue': effectiveOverdue }"
    data-task-creation-safe
    {{ $attributes }}
>
    @if ($schoolClassMeetingDay)
        <button
            x-ref="button"
            type="button"
            @click="toggle()"
            aria-haspopup="true"
            :aria-expanded="open"
            :aria-controls="$id('date-picker-dropdown')"
            :aria-readonly="readonly"
            class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-muted px-2.5 py-0.5 font-semibold text-muted-foreground transition-[box-shadow,transform] duration-150 ease-out dark:border-white/10 group-data-[schedule-mode=one_off]/sc:bg-amber-800/10 group-data-[schedule-mode=one_off]/sc:text-amber-800"
            :class="[
                { 'pointer-events-none shadow-md scale-[1.02]': open },
                readonly ? 'cursor-default pointer-events-none opacity-90' : 'cursor-pointer',
                currentValue && !readonly ? 'bg-amber-800/10 text-amber-800' : '',
            ]"
            data-task-creation-safe
        >
            <flux:icon name="calendar" class="size-3 shrink-0" />
            <span class="inline-flex min-w-0 items-baseline gap-1">
                <span class="shrink-0 text-[10px] font-semibold uppercase tracking-wide opacity-70">{{ $triggerLabel }}:</span>
                <span
                    class="min-w-0 max-w-[min(100%,12rem)] truncate text-xs font-semibold uppercase leading-tight tabular-nums sm:max-w-[16rem]"
                    :class="currentValue ? 'text-amber-800' : 'text-muted-foreground'"
                    x-text="currentValue ? formatDisplayValue(currentValue) : @js(__('Not set'))"
                >{{ $initialDisplayText !== $notSetLabel ? $initialDisplayText : '' }}</span>
            </span>
            @if (! $readonly)
                <flux:icon name="chevron-down" class="size-3 shrink-0 opacity-80" />
            @endif
        </button>
    @else
        <button
            x-ref="button"
            type="button"
            @click="toggle()"
            aria-haspopup="true"
            :aria-expanded="open"
            :aria-controls="$id('date-picker-dropdown')"
            :aria-readonly="readonly"
            @if ($compact)
                :aria-label="@js($datePickerTriggerAriaLabelBase) + ': ' + formatDisplayValue(currentValue)"
            @endif
            @class([
                'date-picker-trigger inline-flex items-center rounded-full border border-border/60 bg-muted font-medium text-muted-foreground transition-[box-shadow,transform] duration-150 ease-out',
                'gap-1 px-2 py-1' => $compact,
                'gap-1.5 px-2.5 py-0.5' => ! $compact,
            ])
            :class="[
                { 'pointer-events-none': open, 'shadow-md scale-[1.02]': open },
                readonly ? 'cursor-default pointer-events-none opacity-90' : 'cursor-pointer',
            ]"
            data-task-creation-safe
        >
            <span class="date-picker-trigger-icon inline-flex">
                <flux:icon name="clock" class="size-3" />
            </span>
            @if (! $compact)
                <span class="inline-flex items-baseline gap-1">
                    <span class="date-picker-trigger-label text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        <span x-text="triggerLabelText">{{ $initialTriggerLabelText }}</span>:
                    </span>
                    <span class="date-picker-trigger-value text-xs font-bold uppercase" x-text="formatDisplayValue(currentValue)">{{ $initialDisplayText }}</span>
                </span>
            @else
                <span class="date-picker-trigger-value max-w-[9rem] truncate text-left text-[11px] font-semibold tabular-nums text-muted-foreground sm:max-w-[11rem]" x-text="formatDisplayValue(currentValue)">{{ $initialDisplayText }}</span>
            @endif
            @if (! $readonly)
                <flux:icon name="chevron-down" class="size-3 shrink-0 focus-hide-chevron" />
            @endif
        </button>
    @endif

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
        @click.stop=""
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
                            :class="!day.date ? 'cursor-default opacity-0' : day.isSelected ? 'cursor-pointer bg-brand-blue text-white shadow-sm' : day.isToday ? 'cursor-pointer text-brand-blue dark:text-brand-light-blue' : 'cursor-pointer text-zinc-700 dark:text-zinc-300'"
                            @click.prevent.stop="day.date && selectDay(day.date)"
                            x-text="day.label"
                        ></button>
                    </template>
                </div>

                <div class="mt-4 border-t border-zinc-100 pt-3 pb-2 text-xs dark:border-zinc-800">
                    <div class="mb-3 flex items-center justify-center gap-2">
                        <button
                            type="button"
                            class="cursor-pointer rounded-full px-2.5 py-1 text-[11px] font-medium text-brand-blue hover:bg-brand-light-blue dark:text-brand-light-blue dark:hover:bg-brand-blue/20"
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
                        x-cloak
                    >
                        <span class="font-medium text-zinc-500 dark:text-zinc-400">
                            Time
                        </span>

                        @include('components.partials.time-12h-controls')
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

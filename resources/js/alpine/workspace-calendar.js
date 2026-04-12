import { syncWorkspaceCalendarTodayButton } from '../lib/workspace-calendar-today-button.js';

/**
 * Alpine component for the workspace / dashboard sidebar calendar.
 * Lives in a dedicated module so Blade does not embed a huge `x-data="..."` string
 * (unescaped `"` in comments or code would break the HTML attribute and leak JS onto the page).
 *
 * @param {object} config
 * @param {number} config.month - 0-indexed JS month
 * @param {number} config.year
 * @param {string|null} config.selectedDate - Y-m-d
 * @param {string} config.today - Y-m-d for "today" highlight and Jump to today
 * @param {Record<string, object>} config.monthMeta
 * @param {string} config.locale - BCP 47 locale for month label
 * @param {string} config.monthLabel - initial visible month/year label
 * @param {string} config.monthLabelCache - `${year}-${month}` key matching updateMonthLabel()
 */
export function workspaceCalendar(config) {
    return {
        ...config,
        alpineReady: false,
        todayCache: null,
        days: [],
        calendarNavBusy: false,
        dateSelectBusy: false,
        busyContext: '',

        init() {
            Alpine.store('focusSession', Alpine.store('focusSession') ?? { session: null, focusReady: false });

            const t = new Date();
            this.todayCache = { year: t.getFullYear(), month: t.getMonth(), date: t.getDate() };

            this.buildDays();
            this.updateMonthLabel();
            this.alpineReady = true;

            this.$watch('$wire.selectedDate', (value) => {
                if (!value) {
                    return;
                }
                this.selectedDate = value;
                const date = new Date(`${value}T12:00:00`);
                const newMonth = date.getMonth();
                const newYear = date.getFullYear();

                if (newMonth !== this.month || newYear !== this.year) {
                    this.month = newMonth;
                    this.year = newYear;
                    this.updateMonthLabel();
                    this.buildDays();
                } else {
                    this.days.forEach((day) => {
                        day.isSelected = day.dateString === value;
                    });
                }
            });

            const syncGridWhenBrowseCleared = () => {
                const wy = this.$wire.calendarViewYear;
                const wm = this.$wire.calendarViewMonth;
                if (wy != null || wm != null) {
                    return;
                }
                const value = this.$wire.selectedDate;
                if (!value) {
                    return;
                }
                const parsed = new Date(`${value}T12:00:00`);
                const nm = parsed.getMonth();
                const ny = parsed.getFullYear();
                if (nm === this.month && ny === this.year) {
                    return;
                }
                this.month = nm;
                this.year = ny;
                if (
                    this.$wire.calendarGridMetaForJs
                    && typeof this.$wire.calendarGridMetaForJs === 'object'
                    && Object.keys(this.$wire.calendarGridMetaForJs).length > 0
                ) {
                    this.monthMeta = this.$wire.calendarGridMetaForJs;
                }
                this.updateMonthLabel();
                this.buildDays();
            };
            this.$watch('$wire.calendarViewYear', syncGridWhenBrowseCleared);
            this.$watch('$wire.calendarViewMonth', syncGridWhenBrowseCleared);
        },

        /**
         * Prefer the optimistic date while a day change is in flight so we do not read stale
         * {@link this.$wire.selectedDate} (wire wins otherwise, which broke Today enable/disable).
         */
        effectiveSelectedDate() {
            if (this.dateSelectBusy && this.selectedDate) {
                return this.selectedDate;
            }

            const wireDate = this.$wire?.selectedDate;

            return wireDate ?? this.selectedDate;
        },

        buildDays() {
            const firstDayOfMonth = new Date(this.year, this.month, 1).getDay();
            const daysInMonth = new Date(this.year, this.month + 1, 0).getDate();
            const previousMonth = new Date(this.year, this.month, 0);
            const daysInPreviousMonth = previousMonth.getDate();

            const daysToShowFromPreviousMonth = firstDayOfMonth;
            const remainder = (daysToShowFromPreviousMonth + daysInMonth) % 7;
            const blanksNeeded = remainder === 0 ? 0 : 7 - remainder;
            const expectedLength = daysToShowFromPreviousMonth + daysInMonth + blanksNeeded;

            let days;
            if (this.days.length === expectedLength) {
                this.days.length = 0;
                days = this.days;
            } else {
                days = [];
            }

            for (let i = daysInPreviousMonth - daysToShowFromPreviousMonth + 1; i <= daysInPreviousMonth; i++) {
                days.push({
                    day: i,
                    month: 'previous',
                    isToday: false,
                    isSelected: false,
                    dateString: null,
                });
            }

            for (let day = 1; day <= daysInMonth; day++) {
                const monthStr = String(this.month + 1).padStart(2, '0');
                const dayStr = String(day).padStart(2, '0');
                const dateString = `${this.year}-${monthStr}-${dayStr}`;
                const isToday =
                    this.todayCache.year === this.year
                    && this.todayCache.month === this.month
                    && this.todayCache.date === day;
                const selected = this.effectiveSelectedDate();
                const isSelected = selected != null && selected === dateString;

                days.push({
                    day,
                    month: 'current',
                    isToday,
                    isSelected,
                    dateString,
                    meta: this.getMeta(dateString),
                });
            }

            for (let day = 1; day <= blanksNeeded; day++) {
                days.push({
                    day,
                    month: 'next',
                    isToday: false,
                    isSelected: false,
                    dateString: null,
                });
            }

            this.days = days;
        },

        async changeMonth(offset) {
            if (this.calendarNavBusy) {
                return;
            }
            this.calendarNavBusy = true;
            this.busyContext = 'calendar-nav';
            try {
                await this.$wire.browseCalendarMonth(offset);
                if (this.$wire.calendarViewYear != null && this.$wire.calendarViewMonth != null) {
                    this.year = this.$wire.calendarViewYear;
                    this.month = this.$wire.calendarViewMonth - 1;
                }
                if (this.$wire.calendarGridMetaForJs && typeof this.$wire.calendarGridMetaForJs === 'object') {
                    this.monthMeta = this.$wire.calendarGridMetaForJs;
                }
                this.updateMonthLabel();
                this.buildDays();
            } finally {
                this.calendarNavBusy = false;
                this.busyContext = '';
                queueMicrotask(() => syncWorkspaceCalendarTodayButton());
            }
        },

        updateMonthLabel() {
            const cacheKey = `${this.year}-${this.month}`;
            if (this.monthLabelCache === cacheKey) {
                return;
            }

            const date = new Date(this.year, this.month, 1);
            this.monthLabel = date.toLocaleDateString(this.locale, { month: 'long', year: 'numeric' });
            this.monthLabelCache = cacheKey;
        },

        async selectDay(dayData) {
            if (!dayData.dateString) {
                return;
            }
            const current = this.effectiveSelectedDate();
            if (dayData.dateString === current) {
                return;
            }
            const previousSelected = current;
            const oldSelected = this.days.find((d) => d.isSelected);
            if (oldSelected) {
                oldSelected.isSelected = false;
            }
            dayData.isSelected = true;
            this.selectedDate = dayData.dateString;

            try {
                this.dateSelectBusy = true;
                this.busyContext = 'date-select';
                await this.$wire.set('selectedDate', dayData.dateString);
            } catch (error) {
                this.selectedDate = previousSelected;
                this.days.forEach((day) => {
                    day.isSelected = day.dateString === previousSelected;
                });
            } finally {
                this.dateSelectBusy = false;
                this.busyContext = '';
                if (this.$wire.selectedDate) {
                    this.selectedDate = this.$wire.selectedDate;
                }
                queueMicrotask(() => syncWorkspaceCalendarTodayButton());
            }
        },

        getMeta(dateString) {
            if (!dateString || !this.monthMeta || typeof this.monthMeta !== 'object') {
                return {
                    task_count: 0,
                    overdue_count: 0,
                    due_count: 0,
                    task_starts_count: 0,
                    event_count: 0,
                    conflict_count: 0,
                    recurring_count: 0,
                    all_day_count: 0,
                };
            }

            return (
                this.monthMeta[dateString] ?? {
                    task_count: 0,
                    overdue_count: 0,
                    due_count: 0,
                    task_starts_count: 0,
                    event_count: 0,
                    conflict_count: 0,
                    recurring_count: 0,
                    all_day_count: 0,
                }
            );
        },

        handleKeydown(event) {
            const tag = (event.target?.tagName ?? '').toLowerCase();
            if (['input', 'textarea', 'select', 'button'].includes(tag)) {
                return;
            }
            if (this.calendarNavBusy || this.dateSelectBusy) {
                return;
            }

            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                this.$wire.navigateSelectedDate(-1);
                return;
            }
            if (event.key === 'ArrowRight') {
                event.preventDefault();
                this.$wire.navigateSelectedDate(1);
                return;
            }
            if (event.key === 'ArrowUp') {
                event.preventDefault();
                this.$wire.navigateSelectedDate(-7);
                return;
            }
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                this.$wire.navigateSelectedDate(7);
                return;
            }
            if (event.key.toLowerCase() === 'n') {
                event.preventDefault();
                this.changeMonth(1);
                return;
            }
            if (event.key.toLowerCase() === 'p') {
                event.preventDefault();
                this.changeMonth(-1);
            }
        },

        /** Same busy overlay / day-cell disabling as {@link selectDay} while Livewire runs. */
        async jumpToToday() {
            if (this.calendarNavBusy || this.dateSelectBusy) {
                return;
            }
            const wire = this.$wire;
            const browsing = wire?.calendarViewYear != null && wire?.calendarViewMonth != null;
            if (wire?.selectedDate === this.today && !browsing) {
                return;
            }
            try {
                this.dateSelectBusy = true;
                this.busyContext = 'jump-today';
                await this.$wire.jumpCalendarToToday();
            } finally {
                this.dateSelectBusy = false;
                this.busyContext = '';
                queueMicrotask(() => syncWorkspaceCalendarTodayButton());
            }
        },
    };
}

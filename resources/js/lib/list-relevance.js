/**
 * Pure helpers for list filtering: date parsing, "still relevant for filter day", overdue.
 */

/**
 * Parse value to Date or null.
 * @param {string|number|Date|null|undefined} value
 * @returns {Date|null}
 */
export function parseDateTime(value) {
    if (value == null || value === '') {
        return null;
    }
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? null : d;
}

/**
 * Format a Date as local YYYY-MM-DD.
 * @param {Date} date
 * @returns {string}
 */
function toLocalDateKey(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

/**
 * Parse YYYY-MM-DD into local day boundaries.
 * @param {string} filterDate
 * @returns {{startOfDay: Date, endOfDay: Date}}
 */
function dayWindowForFilterDate(filterDate) {
    const [yearRaw, monthRaw, dayRaw] = String(filterDate).split('-');
    const year = Number(yearRaw);
    const month = Number(monthRaw);
    const day = Number(dayRaw);
    const startOfDay = new Date(year, month - 1, day, 0, 0, 0, 0);
    const endOfDay = new Date(year, month - 1, day, 23, 59, 59, 999);
    return { startOfDay, endOfDay };
}

/**
 * Whether an item with given start/end is still relevant for the filter day.
 * filterDate is string YYYY-MM-DD. Used for task, event, and project list filtering.
 * @param {string|null|undefined} startDatetime
 * @param {string|null|undefined} endDatetime
 * @param {string} filterDate - 'YYYY-MM-DD'
 * @returns {boolean}
 */
export function isItemStillRelevantForList(startDatetime, endDatetime, filterDate) {
    const start = parseDateTime(startDatetime);
    const end = parseDateTime(endDatetime);
    if (!start && !end) {
        return true;
    }
    const { startOfDay, endOfDay } = dayWindowForFilterDate(filterDate);
    const startOfDayMs = startOfDay.getTime();
    const endOfDayMs = endOfDay.getTime();
    if (!start && end) {
        const endDate = toLocalDateKey(end);
        return endDate >= filterDate;
    }
    if (!start) {
        return true;
    }
    const startMs = start.getTime();
    if (startMs >= startOfDayMs && startMs <= endOfDayMs) {
        return true;
    }
    if (startMs <= startOfDayMs) {
        if (!end) {
            return true;
        }
        const endMs = end.getTime();
        return endMs >= startOfDayMs;
    }
    return false;
}

/**
 * Whether end datetime is in the past (item is overdue).
 * @param {string|null|undefined} startDatetime - unused, for API consistency
 * @param {string|null|undefined} endDatetime
 * @returns {boolean}
 */
export function isStillOverdue(startDatetime, endDatetime) {
    const end = parseDateTime(endDatetime);
    if (!end) return false;
    return end.getTime() < Date.now();
}

/**
 * When true, overdue pill and due-date red styling should be hidden (task done or event terminal).
 *
 * @param {string} kind - 'task' | 'event' | etc.
 * @param {string|null|undefined} taskStatus
 * @param {string|null|undefined} eventStatus
 * @returns {boolean}
 */
export function shouldSuppressOverdueVisualForStatus(kind, taskStatus, eventStatus) {
    if (kind === 'task' && String(taskStatus ?? '') === 'done') {
        return true;
    }
    if (kind === 'event') {
        const s = String(eventStatus ?? '');
        if (s === 'completed' || s === 'cancelled') {
            return true;
        }
    }
    return false;
}

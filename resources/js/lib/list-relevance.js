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
    const startOfDay = new Date(filterDate + 'T00:00:00');
    const endOfDay = new Date(filterDate + 'T23:59:59.999');
    const startOfDayMs = startOfDay.getTime();
    const endOfDayMs = endOfDay.getTime();
    if (!start && end) {
        try {
            const endDate = end.toISOString().slice(0, 10);
            return endDate >= filterDate;
        } catch (_) {
            return true;
        }
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
        return endMs >= endOfDayMs;
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

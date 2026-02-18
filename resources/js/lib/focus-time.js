/**
 * Pure helpers for focus/session time: parsing ISO strings, formatting countdown and durations.
 */

/**
 * Parse ISO date string to milliseconds (handles Z and offset, and no-Z strings).
 * @param {string} [isoString]
 * @returns {number} Milliseconds or NaN if invalid.
 */
export function parseFocusStartedAt(isoString) {
    if (!isoString) return Number.NaN;
    const s = String(isoString).trim();
    if (!s) return Number.NaN;
    if (/Z|[+-]\d{2}:?\d{2}$/.test(s)) return new Date(s).getTime();
    return new Date(s.replace(/\.\d{3,}$/, '') + 'Z').getTime();
}

/**
 * Format seconds as countdown string (e.g. "05:00" or "1:00:00").
 * @param {number} seconds
 * @returns {string}
 */
export function formatFocusCountdown(seconds) {
    const s = Math.max(0, Math.floor(Number(seconds)));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = s % 60;
    if (h > 0) {
        return `${h}:${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`;
    }
    return `${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`;
}

const defaultLabels = {
    minLabel: 'min',
    hrLabel: 'hour',
    hrsLabel: 'hours',
};

/**
 * Format duration in minutes as human string (e.g. "25 min", "1 hour 30 min").
 * @param {number} minutes
 * @param {{ minLabel?: string, hrLabel?: string, hrsLabel?: string }} [labels]
 * @returns {string}
 */
export function formatDurationMinutes(minutes, labels = {}) {
    const { minLabel, hrLabel, hrsLabel } = { ...defaultLabels, ...labels };
    const min = Math.max(0, Math.floor(Number(minutes)));
    const hrs = Math.floor(min / 60);
    const mins = min % 60;
    if (hrs === 0) {
        return `${min} ${minLabel}`;
    }
    if (mins === 0) {
        return `${hrs} ${hrs === 1 ? hrLabel : hrsLabel}`;
    }
    return `${hrs} ${hrs === 1 ? hrLabel : hrsLabel} ${mins} ${minLabel}`;
}

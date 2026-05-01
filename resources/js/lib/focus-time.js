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
 * Compute remaining seconds for a focus session at a given time.
 * @param {{ started_at: string, duration_seconds: number, paused_at?: string, paused_seconds?: number }} session
 * @param {number} nowMs - Current time in milliseconds.
 * @param {{ pausedSecondsAccumulated?: number, isPaused?: boolean, pauseStartedAtMs?: number|null }} [options]
 * @returns {number} Remaining seconds (>= 0).
 */
export function getFocusRemainingSeconds(session, nowMs, options = {}) {
    if (!session?.started_at || session?.duration_seconds == null) return 0;
    const startedMs = parseFocusStartedAt(session.started_at);
    if (!Number.isFinite(startedMs)) return 0;
    const durationSec = Number(session.duration_seconds);
    const elapsedSec = Math.max(0, (nowMs - startedMs) / 1000);
    const hasOptionPausedAccumulated = Number.isFinite(Number(options.pausedSecondsAccumulated));
    const hasOptionActivePause = options.isPaused && options.pauseStartedAtMs != null;
    const hasRuntimePauseState =
        Object.prototype.hasOwnProperty.call(options, 'pausedSecondsAccumulated')
        || Object.prototype.hasOwnProperty.call(options, 'isPaused')
        || Object.prototype.hasOwnProperty.call(options, 'pauseStartedAtMs');
    let pausedSec = hasOptionPausedAccumulated
        ? Math.max(0, Number(options.pausedSecondsAccumulated))
        : (session.paused_seconds != null && Number.isFinite(Number(session.paused_seconds))
            ? Math.max(0, Math.floor(Number(session.paused_seconds)))
            : 0);

    // Runtime pause state should be authoritative when provided by callers.
    // This avoids stale `session.paused_at` values causing second jumps after resume.
    if (hasOptionActivePause) {
        pausedSec += (nowMs - options.pauseStartedAtMs) / 1000;
    } else if (!hasRuntimePauseState && session.paused_at) {
        const pausedAtMs = parseFocusStartedAt(session.paused_at);
        if (Number.isFinite(pausedAtMs)) pausedSec += (nowMs - pausedAtMs) / 1000;
    }

    return Math.max(0, Math.floor(durationSec - elapsedSec + pausedSec));
}

/**
 * Compute remaining seconds for the latest unfinished session snapshot.
 * Supports active, paused, and abandoned (ended but not completed) sessions.
 * @param {{ started_at: string, duration_seconds: number, paused_at?: string|null, paused_seconds?: number, ended_at?: string|null }} session
 * @param {number} [nowMs]
 * @returns {number}
 */
export function getUnfinishedSessionRemainingSeconds(session, nowMs = Date.now()) {
    if (!session?.started_at || session?.duration_seconds == null) return 0;
    const startedMs = parseFocusStartedAt(session.started_at);
    if (!Number.isFinite(startedMs)) return 0;
    const durationSec = Number(session.duration_seconds);
    const pausedSec = Math.max(0, Math.floor(Number(session.paused_seconds ?? 0)));

    if (session.ended_at) {
        const endedMs = parseFocusStartedAt(session.ended_at);
        if (!Number.isFinite(endedMs)) return 0;
        const elapsedSec = Math.max(0, (endedMs - startedMs) / 1000);
        return Math.max(0, Math.floor(durationSec - elapsedSec + pausedSec));
    }

    if (session.paused_at) {
        const pausedAtMs = parseFocusStartedAt(session.paused_at);
        if (!Number.isFinite(pausedAtMs)) return 0;
        const elapsedSec = Math.max(0, (pausedAtMs - startedMs) / 1000);
        return Math.max(0, Math.floor(durationSec - elapsedSec + pausedSec));
    }

    const elapsedSec = Math.max(0, (nowMs - startedMs) / 1000);
    return Math.max(0, Math.floor(durationSec - elapsedSec + pausedSec));
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

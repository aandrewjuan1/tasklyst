/**
 * Pure Pomodoro helpers: normalize settings payload and predict next session.
 */

/**
 * Normalize a numeric field to a clamped integer.
 * @param {string|number|null|undefined} value
 * @param {number} fallback
 * @param {number} min
 * @param {number} max
 * @returns {number}
 */
function normalizeField(value, fallback, min, max) {
    const raw = value ?? '';
    const trimmed = String(raw).trim();
    const base = trimmed === '' ? fallback : Number(trimmed);
    const num = Number.isFinite(base) ? base : fallback;
    return Math.max(min, Math.min(max, Math.floor(num)));
}

/**
 * Build normalized Pomodoro settings payload from a settings object.
 * @param {{
 *   pomodoroWorkMinutes?: string|number,
 *   pomodoroShortBreakMinutes?: string|number,
 *   pomodoroLongBreakMinutes?: string|number,
 *   pomodoroLongBreakAfter?: string|number,
 *   pomodoroSoundVolume?: string|number,
 *   pomodoroAutoStartBreak?: boolean,
 *   pomodoroAutoStartPomodoro?: boolean,
 *   pomodoroSoundEnabled?: boolean,
 * }} settings
 * @returns {{
 *   work_duration_minutes: number,
 *   short_break_minutes: number,
 *   long_break_minutes: number,
 *   long_break_after_pomodoros: number,
 *   auto_start_break: boolean,
 *   auto_start_pomodoro: boolean,
 *   sound_enabled: boolean,
 *   sound_volume: number,
 * }}
 */
export function getPomodoroSettingsPayload(settings) {
    const workMinutes = normalizeField(settings?.pomodoroWorkMinutes, 25, 1, 120);
    const shortMinutes = normalizeField(settings?.pomodoroShortBreakMinutes, 5, 1, 60);
    const longMinutes = normalizeField(settings?.pomodoroLongBreakMinutes, 15, 1, 60);
    const everyPomodoros = normalizeField(settings?.pomodoroLongBreakAfter, 4, 2, 10);
    const volume = normalizeField(settings?.pomodoroSoundVolume, 80, 0, 100);

    return {
        work_duration_minutes: workMinutes,
        short_break_minutes: shortMinutes,
        long_break_minutes: longMinutes,
        long_break_after_pomodoros: everyPomodoros,
        auto_start_break: !!settings?.pomodoroAutoStartBreak,
        auto_start_pomodoro: !!settings?.pomodoroAutoStartPomodoro,
        sound_enabled: !!settings?.pomodoroSoundEnabled,
        sound_volume: volume,
    };
}

/**
 * Predict the next Pomodoro session (break or work) from current session and settings.
 * @param {{ type: string, sequence_number?: number } | null} activeFocusSession
 * @param {{
 *   pomodoroWorkMinutes?: string|number,
 *   pomodoroShortBreakMinutes?: string|number,
 *   pomodoroLongBreakMinutes?: string|number,
 *   pomodoroLongBreakAfter?: string|number,
 *   pomodoroAutoStartBreak?: boolean,
 *   pomodoroAutoStartPomodoro?: boolean,
 * }} settings
 * @param {number} [pomodoroSequenceFallback=1]
 * @returns {{ type: string, sequence_number: number, duration_seconds: number, auto_start: boolean } | null}
 */
export function predictNextPomodoroSessionInfo(activeFocusSession, settings, pomodoroSequenceFallback = 1) {
    if (!activeFocusSession) return null;

    const type = activeFocusSession.type;
    const seq = Number(activeFocusSession.sequence_number ?? pomodoroSequenceFallback ?? 1);
    const workMinutes = Math.max(1, Math.min(120, Math.floor(Number(settings?.pomodoroWorkMinutes ?? 25))));
    const shortMinutes = Math.max(1, Math.min(60, Math.floor(Number(settings?.pomodoroShortBreakMinutes ?? 5))));
    const longMinutes = Math.max(1, Math.min(60, Math.floor(Number(settings?.pomodoroLongBreakMinutes ?? 15))));
    const every = Math.max(2, Math.min(10, Math.floor(Number(settings?.pomodoroLongBreakAfter ?? 4))));

    if (type === 'work') {
        const shouldLong = (seq % every) === 0;
        return {
            type: shouldLong ? 'long_break' : 'short_break',
            sequence_number: seq,
            duration_seconds: (shouldLong ? longMinutes : shortMinutes) * 60,
            auto_start: !!settings?.pomodoroAutoStartBreak,
        };
    }

    if (type === 'short_break' || type === 'long_break') {
        return {
            type: 'work',
            sequence_number: seq + 1,
            duration_seconds: workMinutes * 60,
            auto_start: !!settings?.pomodoroAutoStartPomodoro,
        };
    }

    return null;
}

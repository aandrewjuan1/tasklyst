/**
 * Alpine.js component for the workspace list item card.
 * Config is provided by ListItemCardViewModel::alpineConfig().
 *
 * @param {Object} config - Initial state from server (no functions).
 * @returns {Object} Alpine component object (state + methods).
 */
export function listItemCard(config) {
    return {
        ...config,
        focusReady: false,
        focusCountdownText: '',
        focusProgressStyle: 'width: 0%; min-width: 0',
        init() {
            try {
                if (this.kind !== 'task') return;
                if (this.activeFocusSession) {
                    const taskId = this.activeFocusSession.task_id;
                    if (taskId != null && Number(taskId) === Number(this.itemId)) {
                        const ps = this.activeFocusSession.paused_seconds;
                        if (ps != null && Number.isFinite(Number(ps))) {
                            this.focusPausedSecondsAccumulated = Math.max(0, Math.floor(Number(ps)));
                        }
                        if (this.activeFocusSession.paused_at) {
                            const pausedAtMs = this.parseFocusStartedAt(this.activeFocusSession.paused_at);
                            if (Number.isFinite(pausedAtMs)) {
                                this.focusIsPaused = true;
                                this.focusPauseStartedAt = pausedAtMs;
                            }
                        }
                        return;
                    }
                }
            } catch (err) {
                console.error('[listItemCard] Failed to restore focus state:', err);
            }
            this.$watch('focusReady', (value) => {
                try {
                    const Alpine = window.Alpine;
                    if (!Alpine?.store) return;
                    const store = Alpine.store('focusSession') ?? {};
                    Alpine.store('focusSession', { ...store, focusReady: !!value });
                } catch (_) {}
            });
            
            // Watch for focus state changes to sync ticker (replaces x-effect)
            if (this.kind === 'task') {
                this.$watch('isFocused', (value) => {
                    if (value && this.activeFocusSession) {
                        this.syncFocusTicker();
                    } else {
                        this.stopFocusTicker();
                    }
                });
                this.$watch('activeFocusSession', (session) => {
                    if (session && this.isFocused) {
                        this.syncFocusTicker();
                    } else {
                        this.stopFocusTicker();
                    }
                });
                this._pomodoroLastSavedPayload = JSON.stringify(this.getPomodoroSettingsPayload());
            }
        },
        get isFocused() {
            return this.kind === 'task' && this.activeFocusSession && Number(this.activeFocusSession.task_id) === Number(this.itemId);
        },
        get focusReadyDurationMinutes() {
            return this.taskDurationMinutes != null && this.taskDurationMinutes > 0
                ? Number(this.taskDurationMinutes)
                : this.defaultWorkDurationMinutes;
        },
        get formattedFocusReadyDuration() {
            return this.formatFocusReadyDuration();
        },
        formatFocusReadyDuration() {
            const min = this.focusReadyDurationMinutes;
            const minutes = Math.max(0, Math.floor(Number(min)));
            const hrs = Math.floor(minutes / 60);
            const mins = minutes % 60;
            const minLabel = this.focusDurationLabelMin ?? 'min';
            const hrLabel = this.focusDurationLabelHr ?? 'hour';
            const hrsLabel = this.focusDurationLabelHrs ?? 'hours';
            if (hrs === 0) {
                return `${minutes} ${minLabel}`;
            }
            if (mins === 0) {
                return `${hrs} ${hrs === 1 ? hrLabel : hrsLabel}`;
            }
            return `${hrs} ${hrs === 1 ? hrLabel : hrsLabel} ${mins} ${minLabel}`;
        },
        get formattedPomodoroWorkDuration() {
            return this.formatPomodoroDurationMinutes(this.pomodoroWorkMinutes ?? 25);
        },
        get pomodoroSummaryText() {
            const work = Math.max(0, Math.floor(Number(this.pomodoroWorkMinutes ?? 25)));
            const short = Math.max(0, Math.floor(Number(this.pomodoroShortBreakMinutes ?? 5)));
            const long = Math.max(0, Math.floor(Number(this.pomodoroLongBreakMinutes ?? 15)));
            const every = Math.max(2, Math.min(10, Math.floor(Number(this.pomodoroLongBreakAfter ?? 4))));
            const minLabel = this.focusDurationLabelMin ?? 'min';
            const everyLabel = this.pomodoroLongBreakEveryLabel ?? 'long break every';
            return `${work} ${minLabel} work · ${short} short · ${long} long · ${everyLabel} ${every}`;
        },
        formatPomodoroDurationMinutes(minutes) {
            const min = Math.max(0, Math.floor(Number(minutes)));
            const hrs = Math.floor(min / 60);
            const mins = min % 60;
            const minLabel = this.focusDurationLabelMin ?? 'min';
            const hrLabel = this.focusDurationLabelHr ?? 'hour';
            const hrsLabel = this.focusDurationLabelHrs ?? 'hours';
            if (hrs === 0) {
                return `${min} ${minLabel}`;
            }
            if (mins === 0) {
                return `${hrs} ${hrs === 1 ? hrLabel : hrsLabel}`;
            }
            return `${hrs} ${hrs === 1 ? hrLabel : hrsLabel} ${mins} ${minLabel}`;
        },
        getPomodoroSettingsPayload() {
            return {
                work_duration_minutes: Math.max(1, Math.min(120, Math.floor(Number(this.pomodoroWorkMinutes ?? 25)))),
                short_break_minutes: Math.max(1, Math.min(60, Math.floor(Number(this.pomodoroShortBreakMinutes ?? 5)))),
                long_break_minutes: Math.max(1, Math.min(60, Math.floor(Number(this.pomodoroLongBreakMinutes ?? 15)))),
                long_break_after_pomodoros: Math.max(2, Math.min(10, Math.floor(Number(this.pomodoroLongBreakAfter ?? 4)))),
                auto_start_break: !!this.pomodoroAutoStartBreak,
                auto_start_pomodoro: !!this.pomodoroAutoStartPomodoro,
                sound_enabled: !!this.pomodoroSoundEnabled,
                sound_volume: Math.max(0, Math.min(100, Math.floor(Number(this.pomodoroSoundVolume ?? 80)))),
                notification_on_complete: !!this.pomodoroNotificationOnComplete,
            };
        },
        applyPomodoroSettings(settings) {
            if (!settings || typeof settings !== 'object') return;
            if (settings.work_duration_minutes != null) this.pomodoroWorkMinutes = Number(settings.work_duration_minutes);
            if (settings.short_break_minutes != null) this.pomodoroShortBreakMinutes = Number(settings.short_break_minutes);
            if (settings.long_break_minutes != null) this.pomodoroLongBreakMinutes = Number(settings.long_break_minutes);
            if (settings.long_break_after_pomodoros != null) this.pomodoroLongBreakAfter = Number(settings.long_break_after_pomodoros);
            if (settings.auto_start_break != null) this.pomodoroAutoStartBreak = !!settings.auto_start_break;
            if (settings.auto_start_pomodoro != null) this.pomodoroAutoStartPomodoro = !!settings.auto_start_pomodoro;
            if (settings.sound_enabled != null) this.pomodoroSoundEnabled = !!settings.sound_enabled;
            if (settings.sound_volume != null) this.pomodoroSoundVolume = Number(settings.sound_volume);
            if (settings.notification_on_complete != null) this.pomodoroNotificationOnComplete = !!settings.notification_on_complete;
            if (this.kind === 'task') this._pomodoroLastSavedPayload = JSON.stringify(this.getPomodoroSettingsPayload());
        },
        async savePomodoroSettings() {
            if (this.kind !== 'task' || !this.$wire?.$parent?.$call) return;
            const payload = this.getPomodoroSettingsPayload();
            const currentJson = JSON.stringify(payload);
            if (currentJson === this._pomodoroLastSavedPayload) return;
            
            // PHASE 1: Create snapshot BEFORE any changes
            const settingsSnapshot = {
                pomodoroWorkMinutes: this.pomodoroWorkMinutes,
                pomodoroShortBreakMinutes: this.pomodoroShortBreakMinutes,
                pomodoroLongBreakMinutes: this.pomodoroLongBreakMinutes,
                pomodoroLongBreakAfter: this.pomodoroLongBreakAfter,
                pomodoroAutoStartBreak: this.pomodoroAutoStartBreak,
                pomodoroAutoStartPomodoro: this.pomodoroAutoStartPomodoro,
                pomodoroSoundEnabled: this.pomodoroSoundEnabled,
                pomodoroSoundVolume: this.pomodoroSoundVolume,
                pomodoroNotificationOnComplete: this.pomodoroNotificationOnComplete,
            };
            
            const parent = this.$wire.$parent;
            try {
                // PHASE 2: UI already updated via x-model bindings (optimistic)
                // PHASE 3: Call server asynchronously (don't await yet)
                const promise = parent.$call('updatePomodoroSettings', payload);
                
                // PHASE 4: Handle response AFTER UI is updated
                const ok = await promise;
                if (ok === true) {
                    this._pomodoroLastSavedPayload = currentJson;
                }
                if (ok === false) {
                    // Rollback: fetch fresh settings from server
                    const fresh = await parent.$call('getPomodoroSettings');
                    this.applyPomodoroSettings(fresh ?? {});
                }
            } catch (err) {
                // PHASE 5: Rollback on error - restore from snapshot
                this.pomodoroWorkMinutes = settingsSnapshot.pomodoroWorkMinutes;
                this.pomodoroShortBreakMinutes = settingsSnapshot.pomodoroShortBreakMinutes;
                this.pomodoroLongBreakMinutes = settingsSnapshot.pomodoroLongBreakMinutes;
                this.pomodoroLongBreakAfter = settingsSnapshot.pomodoroLongBreakAfter;
                this.pomodoroAutoStartBreak = settingsSnapshot.pomodoroAutoStartBreak;
                this.pomodoroAutoStartPomodoro = settingsSnapshot.pomodoroAutoStartPomodoro;
                this.pomodoroSoundEnabled = settingsSnapshot.pomodoroSoundEnabled;
                this.pomodoroSoundVolume = settingsSnapshot.pomodoroSoundVolume;
                this.pomodoroNotificationOnComplete = settingsSnapshot.pomodoroNotificationOnComplete;
                
                this.$wire.$dispatch('toast', {
                    type: 'error',
                    message: err?.message ?? this.pomodoroSettingsSaveErrorToast ?? 'Could not save Pomodoro settings.',
                });
            }
        },
        enterFocusReady() {
            if (this.kind !== 'task' || !this.canEdit || this.isFocused) return;
            this.focusReady = true;
        },
        async startFocusFromReady() {
            try {
                await this.startFocusMode();
            } finally {
                this.focusReady = false;
            }
        },
        parseFocusStartedAt(isoString) {
            if (!isoString) return NaN;
            const s = String(isoString).trim();
            if (!s) return NaN;
            if (/Z|[+-]\d{2}:?\d{2}$/.test(s)) return new Date(s).getTime();
            return new Date(s.replace(/\.\d{3,}$/, '') + 'Z').getTime();
        },
        get focusRemainingSeconds() {
            if (!this.isFocused || !this.activeFocusSession?.started_at || !this.activeFocusSession?.duration_seconds) return 0;
            const startedMs = this.parseFocusStartedAt(this.activeFocusSession.started_at);
            if (!Number.isFinite(startedMs)) return 0;
            const durationSec = Number(this.activeFocusSession.duration_seconds);
            
            // Cache Date.now() once per evaluation to avoid multiple calls
            const nowMs = this.focusTickerNow ?? Date.now();
            
            const elapsedSec = Math.max(0, (nowMs - startedMs) / 1000);
            let pausedSec = this.focusPausedSecondsAccumulated;
            if (this.activeFocusSession.paused_at) {
                const pausedAtMs = this.parseFocusStartedAt(this.activeFocusSession.paused_at);
                if (Number.isFinite(pausedAtMs)) {
                    pausedSec += (nowMs - pausedAtMs) / 1000;
                }
            } else if (pausedSec === 0 && this.activeFocusSession.paused_seconds != null && Number.isFinite(Number(this.activeFocusSession.paused_seconds))) {
                pausedSec = Math.max(0, Math.floor(Number(this.activeFocusSession.paused_seconds)));
            }
            if (this.focusIsPaused && this.focusPauseStartedAt) {
                pausedSec += (nowMs - this.focusPauseStartedAt) / 1000;
            }
            return Math.max(0, Math.floor(durationSec - elapsedSec + pausedSec));
        },
        get focusElapsedPercent() {
            if (!this.activeFocusSession?.duration_seconds) return 0;
            const duration = Number(this.activeFocusSession.duration_seconds);
            const remaining = this.focusRemainingSeconds;
            return Math.min(100, Math.max(0, ((duration - remaining) / duration) * 100));
        },
        formatFocusCountdown(seconds) {
            const s = Math.max(0, Math.floor(Number(seconds)));
            const h = Math.floor(s / 3600);
            const m = Math.floor((s % 3600) / 60);
            const sec = s % 60;
            if (h > 0) {
                return `${h}:${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`;
            }
            return `${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`;
        },
        isTempSessionId(id) {
            return id != null && String(id).startsWith('temp-');
        },
        syncFocusTicker() {
            const shouldRun = this.isFocused && this.activeFocusSession;
            if (this._focusTickerShouldRun === shouldRun) return;
            this._focusTickerShouldRun = shouldRun;
            if (shouldRun) {
                this.startFocusTicker();
            } else {
                this.stopFocusTicker();
                this.sessionComplete = false;
            }
        },
        startFocusTicker() {
            if (this.focusIntervalId != null) return;
            if (this.sessionComplete || this.focusRemainingSeconds <= 0) return;
            this.sessionComplete = false;
            this.focusTickerNow = Date.now();
            const initialRemaining = this.focusRemainingSeconds;
            const initialDuration = Number(this.activeFocusSession?.duration_seconds ?? 0);
            this.focusElapsedPercentValue = initialDuration > 0
                ? Math.min(100, Math.max(0, ((initialDuration - initialRemaining) / initialDuration) * 100))
                : 0;
            this.focusCountdownText = this.formatFocusCountdown(initialRemaining);
            this.focusProgressStyle = `width: ${this.focusElapsedPercentValue}%; min-width: ${this.focusElapsedPercentValue > 0 ? '2px' : '0'}`;
            this.focusIntervalId = setInterval(() => {
                if (this.focusIsPaused) return;
                
                // Batch all calculations first
                const now = Date.now();
                this.focusTickerNow = now;
                const remaining = this.focusRemainingSeconds;
                const duration = Number(this.activeFocusSession?.duration_seconds ?? 0);
                const pct = duration > 0
                    ? Math.min(100, Math.max(0, ((duration - remaining) / duration) * 100))
                    : 0;
                
                // Batch DOM updates using requestAnimationFrame to prevent layout thrashing
                requestAnimationFrame(() => {
                    this.focusElapsedPercentValue = pct;
                    this.focusCountdownText = this.formatFocusCountdown(remaining);
                    this.focusProgressStyle = `width: ${pct}%; min-width: ${pct > 0 ? '2px' : '0'}`;
                });
                
                if (remaining <= 0) {
                    this.sessionComplete = true;
                    const pausedSeconds = this.getFocusPausedSecondsTotal();
                    const sessionId = this.activeFocusSession?.id;
                    this.stopFocusTicker();
                    // Persist completion in background; keep bar visible for "Session complete" state.
                    if (sessionId != null && !this.isTempSessionId(sessionId)) {
                        this.$wire.$parent.$call('completeFocusSession', sessionId, {
                            ended_at: new Date().toISOString(),
                            completed: true,
                            paused_seconds: pausedSeconds,
                            mark_task_status: 'done',
                        }).catch(() => {
                            this.$wire.$dispatch('toast', { type: 'error', message: this.focusCompleteErrorToast });
                        });
                    }
                    // Dismiss the "Session complete" bar after 2 seconds.
                    this._completedDismissTimeoutId = setTimeout(() => {
                        this._completedDismissTimeoutId = null;
                        this.activeFocusSession = null;
                        this.dispatchFocusSessionUpdated(null);
                        this.sessionComplete = false;
                        this.focusReady = false;
                    }, 2000);
                }
            }, 1000);
            this._focusEscapeHandler = (e) => {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    this.stopFocus();
                }
            };
            window.addEventListener('keydown', this._focusEscapeHandler);
        },
        stopFocusTicker() {
            if (this.focusIntervalId != null) {
                clearInterval(this.focusIntervalId);
                this.focusIntervalId = null;
            }
            if (this._completedDismissTimeoutId != null) {
                clearTimeout(this._completedDismissTimeoutId);
                this._completedDismissTimeoutId = null;
            }
            this.focusIsPaused = false;
            this.focusPauseStartedAt = null;
            this.focusPausedSecondsAccumulated = 0;
            if (this._focusEscapeHandler) {
                window.removeEventListener('keydown', this._focusEscapeHandler);
                this._focusEscapeHandler = null;
            }
            this.focusCountdownText = '';
            this.focusProgressStyle = 'width: 0%; min-width: 0';
        },
        dismissCompletedFocus() {
            if (this._completedDismissTimeoutId != null) {
                clearTimeout(this._completedDismissTimeoutId);
                this._completedDismissTimeoutId = null;
            }
            this.activeFocusSession = null;
            this.dispatchFocusSessionUpdated(null);
            this.sessionComplete = false;
            this.focusReady = false;
        },
        async markTaskDoneFromFocus() {
            if (this.kind !== 'task') return;
            
            // PHASE 1: Create snapshot BEFORE any changes
            const previousStatus = this.taskStatus;
            const activeFocusSessionSnapshot = this.activeFocusSession ? { ...this.activeFocusSession } : null;
            const sessionCompleteSnapshot = this.sessionComplete;
            const focusReadySnapshot = this.focusReady;
            
            try {
                // PHASE 2: Update UI immediately (optimistic)
                this.taskStatus = 'done';
                this.dismissCompletedFocus();
                window.dispatchEvent(
                    new CustomEvent('task-status-updated', {
                        detail: { itemId: this.itemId, status: 'done' },
                        bubbles: true,
                    })
                );
                
                // PHASE 3: Call server asynchronously (don't await yet)
                const promise = this.$wire.$parent.$call(
                    this.updatePropertyMethod,
                    this.itemId,
                    'status',
                    'done',
                    false
                );
                
                // PHASE 4: Handle response AFTER UI is updated
                const ok = await promise;
                if (ok === false) {
                    // PHASE 5: Rollback on error
                    this.taskStatus = previousStatus;
                    this.activeFocusSession = activeFocusSessionSnapshot;
                    this.sessionComplete = sessionCompleteSnapshot;
                    this.focusReady = focusReadySnapshot;
                    if (activeFocusSessionSnapshot) {
                        this.dispatchFocusSessionUpdated(activeFocusSessionSnapshot);
                    }
                    window.dispatchEvent(
                        new CustomEvent('task-status-updated', {
                            detail: { itemId: this.itemId, status: previousStatus },
                            bubbles: true,
                        })
                    );
                    this.$wire.$dispatch('toast', {
                        type: 'error',
                        message: this.focusMarkDoneErrorToast,
                    });
                }
            } catch (err) {
                // PHASE 5: Rollback on error - restore from snapshot
                this.taskStatus = previousStatus;
                this.activeFocusSession = activeFocusSessionSnapshot;
                this.sessionComplete = sessionCompleteSnapshot;
                this.focusReady = focusReadySnapshot;
                if (activeFocusSessionSnapshot) {
                    this.dispatchFocusSessionUpdated(activeFocusSessionSnapshot);
                }
                window.dispatchEvent(
                    new CustomEvent('task-status-updated', {
                        detail: { itemId: this.itemId, status: previousStatus },
                        bubbles: true,
                    })
                );
                this.$wire.$dispatch('toast', {
                    type: 'error',
                    message: err.message ?? this.focusMarkDoneErrorToast,
                });
            }
        },
        async pauseFocus() {
            if (!this.isFocused || this.focusIsPaused) return;
            const sessionId = this.activeFocusSession?.id;
            
            // PHASE 1: Create snapshot BEFORE any changes
            const snapshot = {
                focusIsPaused: this.focusIsPaused,
                focusPauseStartedAt: this.focusPauseStartedAt,
                focusPausedSecondsAccumulated: this.focusPausedSecondsAccumulated,
                activeFocusSession: this.activeFocusSession ? { ...this.activeFocusSession } : null,
            };
            
            try {
                // PHASE 2: Update UI immediately (optimistic)
                this.focusIsPaused = true;
                this.focusPauseStartedAt = Date.now();
                this.focusTickerNow = Date.now();
                const remaining = this.focusRemainingSeconds;
                this.focusElapsedPercentValue = this.focusElapsedPercent;
                this.focusCountdownText = this.formatFocusCountdown(remaining);
                const pct = this.focusElapsedPercentValue;
                this.focusProgressStyle = `width: ${pct}%; min-width: ${pct > 0 ? '2px' : '0'}`;
                
                if (sessionId != null && !this.isTempSessionId(sessionId)) {
                    // PHASE 3: Call server asynchronously (don't await yet)
                    const promise = this.$wire.$parent.$call('pauseFocusSession', sessionId);
                    
                    // PHASE 4: Handle response AFTER UI is updated
                    const ok = await promise;
                    if (ok === false) {
                        // PHASE 5: Rollback on error
                        this.focusIsPaused = snapshot.focusIsPaused;
                        this.focusPauseStartedAt = snapshot.focusPauseStartedAt;
                        this.activeFocusSession = snapshot.activeFocusSession;
                        this.dispatchFocusSessionUpdated(snapshot.activeFocusSession);
                        this.$wire.$dispatch('toast', { type: 'error', message: this.focusSessionNoLongerActiveToast });
                    }
                }
            } catch (err) {
                // PHASE 5: Rollback on error - restore from snapshot
                this.focusIsPaused = snapshot.focusIsPaused;
                this.focusPauseStartedAt = snapshot.focusPauseStartedAt;
                this.focusPausedSecondsAccumulated = snapshot.focusPausedSecondsAccumulated;
                this.$wire.$dispatch('toast', { type: 'error', message: this.focusStopErrorToast });
            }
        },
        async resumeFocus() {
            if (!this.isFocused || !this.focusIsPaused || !this.focusPauseStartedAt) return;
            const sessionId = this.activeFocusSession?.id;
            const pauseStartMs = this.focusPauseStartedAt;
            const segmentSec = (Date.now() - pauseStartMs) / 1000;
            
            // PHASE 1: Create snapshot BEFORE any changes
            const snapshot = {
                focusIsPaused: this.focusIsPaused,
                focusPauseStartedAt: this.focusPauseStartedAt,
                focusPausedSecondsAccumulated: this.focusPausedSecondsAccumulated,
                activeFocusSession: this.activeFocusSession ? { ...this.activeFocusSession } : null,
            };
            
            try {
                // PHASE 2: Update UI immediately (optimistic)
                this.focusPausedSecondsAccumulated += segmentSec;
                this.focusPauseStartedAt = null;
                this.focusIsPaused = false;
                this.focusTickerNow = Date.now();
                const remaining = this.focusRemainingSeconds;
                this.focusElapsedPercentValue = this.focusElapsedPercent;
                this.focusCountdownText = this.formatFocusCountdown(remaining);
                const pct = this.focusElapsedPercentValue;
                this.focusProgressStyle = `width: ${pct}%; min-width: ${pct > 0 ? '2px' : '0'}`;
                this._focusJustResumed = true;
                
                if (sessionId != null && !this.isTempSessionId(sessionId)) {
                    // PHASE 3: Call server asynchronously (don't await yet)
                    const promise = this.$wire.$parent.$call('resumeFocusSession', sessionId);
                    
                    // PHASE 4: Handle response AFTER UI is updated
                    const ok = await promise;
                    if (ok === false) {
                        // PHASE 5: Rollback on error
                        this._focusJustResumed = false;
                        this.focusPausedSecondsAccumulated = snapshot.focusPausedSecondsAccumulated;
                        this.focusIsPaused = snapshot.focusIsPaused;
                        this.focusPauseStartedAt = snapshot.focusPauseStartedAt;
                        this.activeFocusSession = snapshot.activeFocusSession;
                        this.dispatchFocusSessionUpdated(snapshot.activeFocusSession);
                        this.$wire.$dispatch('toast', { type: 'error', message: this.focusSessionNoLongerActiveToast });
                    }
                }
            } catch (err) {
                // PHASE 5: Rollback on error - restore from snapshot
                this._focusJustResumed = false;
                this.focusPausedSecondsAccumulated = snapshot.focusPausedSecondsAccumulated;
                this.focusIsPaused = snapshot.focusIsPaused;
                this.focusPauseStartedAt = snapshot.focusPauseStartedAt;
                this.$wire.$dispatch('toast', { type: 'error', message: this.focusStopErrorToast });
            }
        },
        getFocusPausedSecondsTotal() {
            let total = this.focusPausedSecondsAccumulated;
            if (this.focusIsPaused && this.focusPauseStartedAt) {
                total += (Date.now() - this.focusPauseStartedAt) / 1000;
            }
            return Math.round(total);
        },
        dispatchFocusSessionUpdated(session) {
            const detail = { session: session ?? null };
            window.dispatchEvent(new CustomEvent('focus-session-updated', { detail, bubbles: true, composed: true }));
        },
        async startFocusMode() {
            if (this.kind !== 'task' || !this.canEdit || this.isFocused) return;
            const types = this.focusModeTypes ?? [];
            const selected = types.find((t) => t.value === this.focusModeType);
            if (selected && !selected.available) {
                this.$wire.$dispatch('toast', {
                    type: 'info',
                    message: typeof this.focusModeComingSoonToast === 'string' ? this.focusModeComingSoonToast : 'Coming soon.',
                });
                return;
            }
            const isPomodoro = this.focusModeType === 'pomodoro';
            const minutes = isPomodoro
                ? Math.max(1, Math.min(120, Math.floor(Number(this.pomodoroWorkMinutes ?? 25))))
                : (this.taskDurationMinutes != null && this.taskDurationMinutes > 0
                    ? Number(this.taskDurationMinutes) : this.defaultWorkDurationMinutes);
            const durationSeconds = Math.max(60, minutes * 60);
            const startedAt = new Date().toISOString();
            const payload = {
                type: 'work',
                duration_seconds: durationSeconds,
                started_at: startedAt,
                payload: {
                    used_task_duration: !isPomodoro && !!(this.taskDurationMinutes != null && this.taskDurationMinutes > 0),
                    focus_mode_type: this.focusModeType ?? 'countdown',
                },
            };
            if (this.isRecurringTask && this.listFilterDate) {
                payload.occurrence_date = String(this.listFilterDate).slice(0, 10);
            }
            const optimisticSession = {
                id: 'temp-' + Date.now(),
                task_id: this.itemId,
                started_at: startedAt,
                duration_seconds: durationSeconds,
                type: 'work',
                sequence_number: 1,
                focus_mode_type: this.focusModeType ?? 'countdown',
            };
            const promise = this.$wire.$parent.$call('startFocusSession', this.itemId, payload);
            this.pendingStartPromise = promise;
            try {
                if (this.kind === 'task' && this.taskStatus === 'to_do') {
                    this.taskStatus = 'doing';
                    window.dispatchEvent(
                        new CustomEvent('task-status-updated', {
                            detail: { itemId: this.itemId, status: 'doing' },
                            bubbles: true,
                        })
                    );
                }
                this.activeFocusSession = optimisticSession;
                this.dispatchFocusSessionUpdated(optimisticSession);
                const result = await promise;
                this.pendingStartPromise = null;
                if (this.focusStopRequestedBeforeStartResolved) {
                    this.focusStopRequestedBeforeStartResolved = false;
                    if (result && !result.error && result.id) {
                        try {
                            await this.$wire.$parent.$call('abandonFocusSession', result.id);
                        } catch (_) {
                            this.$wire.$dispatch('toast', { type: 'error', message: this.focusStopErrorToast });
                        }
                    }
                    return;
                }
                if (result && result.error) {
                    if (this.kind === 'task' && this.taskStatus === 'doing') {
                        this.taskStatus = 'to_do';
                        window.dispatchEvent(
                            new CustomEvent('task-status-updated', {
                                detail: { itemId: this.itemId, status: 'to_do' },
                                bubbles: true,
                            })
                        );
                    }
                    this.activeFocusSession = null;
                    this.dispatchFocusSessionUpdated(null);
                    this.$wire.$dispatch('toast', { type: 'error', message: (typeof result.error === 'string' ? result.error : null) || this.focusStartErrorToast });
                    return;
                }
                const merged = {
                    ...result,
                    started_at: this.activeFocusSession?.started_at || result.started_at,
                    focus_mode_type: this.activeFocusSession?.focus_mode_type ?? result.focus_mode_type ?? 'countdown',
                };
                this.activeFocusSession = merged;
                this.dispatchFocusSessionUpdated(merged);
                if (this.focusIsPaused && result.id) {
                    try {
                        await this.$wire.$parent.$call('pauseFocusSession', result.id);
                    } catch (_) {
                        this.focusIsPaused = false;
                        this.focusPauseStartedAt = null;
                    }
                }
            } catch (error) {
                this.pendingStartPromise = null;
                this.focusStopRequestedBeforeStartResolved = false;
                this.activeFocusSession = null;
                this.dispatchFocusSessionUpdated(null);
                this.$wire.$dispatch('toast', { type: 'error', message: error.message || this.focusStartErrorToast });
            }
        },
        async stopFocus() {
            if (!this.activeFocusSession || !this.activeFocusSession.id) return;
            
            // PHASE 1: Create snapshot BEFORE any changes
            const sessionSnapshot = { ...this.activeFocusSession };
            const focusReadySnapshot = this.focusReady;
            
            if (this.isTempSessionId(sessionSnapshot.id)) {
                this.focusStopRequestedBeforeStartResolved = true;
                this.activeFocusSession = null;
                this.dispatchFocusSessionUpdated(null);
                this.focusReady = true;
                return;
            }
            
            const pausedSeconds = this.getFocusPausedSecondsTotal();
            try {
                // PHASE 2: Update UI immediately (optimistic)
                this.activeFocusSession = null;
                this.dispatchFocusSessionUpdated(null);
                this.focusReady = true;
                
                // PHASE 3: Call server asynchronously (don't await yet)
                const promise = this.$wire.$parent.$call('abandonFocusSession', sessionSnapshot.id, { paused_seconds: pausedSeconds });
                
                // PHASE 4: Handle response AFTER UI is updated
                await promise;
            } catch (error) {
                // PHASE 5: Rollback on error - restore from snapshot
                this.activeFocusSession = sessionSnapshot;
                this.dispatchFocusSessionUpdated(sessionSnapshot);
                this.focusReady = focusReadySnapshot;
                this.$wire.$dispatch('toast', { type: 'error', message: error.message || this.focusStopErrorToast });
            }
        },
        hideFromList() {
            if (this.hideCard) {
                return;
            }
            // If this card had focus, clear global focus state so blur styling is removed
            if (this.focusReady || this.isFocused) {
                this.stopFocusTicker();
                this.focusReady = false;
                this.activeFocusSession = null;
                // Dispatch event to update store in parent components (index/list) - watcher also updates focusReady
                this.dispatchFocusSessionUpdated(null);
            }
            this.hideCard = true;
            this.$dispatch('list-item-hidden', { fromOverdue: this.isOverdue });
        },
        isTaskStillRelevantForList(startDatetime, endDatetime) {
            if (this.kind !== 'task' || !this.listFilterDate) {
                return true;
            }
            const filterDate = String(this.listFilterDate).slice(0, 10);
            const parseDateTime = (value) => {
                if (value == null || value === '') {
                    return null;
                }
                const d = new Date(value);
                return Number.isNaN(d.getTime()) ? null : d;
            };
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
        },
        isEventStillRelevantForList(startDatetime, endDatetime) {
            if (this.kind !== 'event' || !this.listFilterDate) {
                return true;
            }
            const filterDate = String(this.listFilterDate).slice(0, 10);
            const parseDateTime = (value) => {
                if (value == null || value === '') {
                    return null;
                }
                const d = new Date(value);
                return Number.isNaN(d.getTime()) ? null : d;
            };
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
        },
        isProjectStillRelevantForList(startDatetime, endDatetime) {
            if (this.kind !== 'project' || !this.listFilterDate) {
                return true;
            }
            const filterDate = String(this.listFilterDate).slice(0, 10);
            const parseDateTime = (value) => {
                if (value == null || value === '') {
                    return null;
                }
                const d = new Date(value);
                return Number.isNaN(d.getTime()) ? null : d;
            };
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
        },
        /** Overdue when end datetime is in the past (before now), same logic as date-picker effectiveOverdue. */
        isStillOverdue(startDatetime, endDatetime) {
            const parseDateTime = (value) => {
                if (value == null || value === '') return null;
                const d = new Date(value);
                return Number.isNaN(d.getTime()) ? null : d;
            };
            const end = parseDateTime(endDatetime);
            if (!end) return false;
            return end < new Date();
        },
        shouldHideAfterPropertyUpdate(detail) {
            const { property, value, startDatetime: detailStart, endDatetime: detailEnd } = detail;
            const f = this.filters ?? {};

            if (['startDatetime', 'endDatetime'].includes(property)) {
                const start = detailStart ?? null;
                const end = detailEnd ?? null;
                if (this.kind === 'task') {
                    if (this.isOverdue) {
                        return false;
                    }
                    if (this.isStillOverdue(start, end)) {
                        return false;
                    }
                    return !this.isTaskStillRelevantForList(start, end);
                }
                if (this.kind === 'event') {
                    if (this.isOverdue) {
                        return false;
                    }
                    if (this.isStillOverdue(start, end)) {
                        return false;
                    }
                    return !this.isEventStillRelevantForList(start, end);
                }
                if (this.kind === 'project') {
                    return !this.isProjectStillRelevantForList(start, end);
                }
            }

            if (!f?.hasActiveFilters) {
                return false;
            }

            if (this.kind === 'task') {
                if (f.taskPriority && property === 'priority' && value !== f.taskPriority) return true;
                if (f.taskStatus && property === 'status' && value !== f.taskStatus) return true;
                if (f.taskComplexity && property === 'complexity' && value !== f.taskComplexity) return true;
            }

            if (this.kind === 'event') {
                if (f.eventStatus && property === 'status' && value !== f.eventStatus) return true;
            }

            if (f.tagIds?.length && property === 'tagIds') {
                const ids = Array.isArray(value) ? value : [];
                const hasMatch = ids.some((id) => f.tagIds.includes(Number(id)) || f.tagIds.includes(String(id)));
                if (!hasMatch) return true;
            }

            if (f.recurring === 'recurring' && property === 'recurrence' && !value?.enabled) return true;
            if (f.recurring === 'oneTime' && property === 'recurrence' && value?.enabled) return true;

            if (property === 'status') {
                if (this.kind === 'task' && value === 'done') return true;
                if (this.kind === 'event' && ['completed', 'cancelled'].includes(value)) return true;
            }

            return false;
        },
        async deleteItem() {
            if (!this.canDelete || this.deletingInProgress || this.hideCard || !this.deleteMethod || this.itemId == null) return;

            const wasOverdue = this.isOverdue;
            this.deletingInProgress = true;

            try {
                this.hideFromList(false);
                const ok = await this.$wire.$parent.$call(this.deleteMethod, this.itemId);

                if (!ok) {
                    this.hideCard = false;
                    this.$dispatch('list-item-shown', { fromOverdue: wasOverdue });
                    this.$wire.$dispatch('toast', { type: 'error', message: this.deleteErrorToast });
                }
            } catch (e) {
                this.hideCard = false;
                this.$dispatch('list-item-shown', { fromOverdue: wasOverdue });
                this.$wire.$dispatch('toast', { type: 'error', message: this.deleteErrorToast });
            } finally {
                this.deletingInProgress = false;
            }
        },
        startEditingTitle() {
            if (!this.canEdit || this.deletingInProgress || !this.updatePropertyMethod) return;
            this.titleSnapshot = this.editedTitle;
            this.isEditingTitle = true;
            this.$nextTick(() => {
                const input = this.$refs.titleInput;
                if (input) {
                    input.focus();
                    const length = input.value.length;
                    input.setSelectionRange(length, length);
                }
            });
        },
        cancelEditingTitle() {
            this.justCanceledTitle = true;
            this.savedViaEnter = false;
            this.editedTitle = this.titleSnapshot;
            this.isEditingTitle = false;
            this.titleSnapshot = null;
            setTimeout(() => { this.justCanceledTitle = false; }, 100);
        },
        async saveTitle() {
            if (this.deletingInProgress || !this.updatePropertyMethod || !this.itemId || this.savingTitle || this.justCanceledTitle) return;

            const trimmedTitle = (this.editedTitle || '').trim();
            if (!trimmedTitle) {
                this.$wire.$dispatch('toast', { type: 'error', message: this.titleErrorToast });
                this.cancelEditingTitle();
                return;
            }

            const snapshot = this.titleSnapshot;
            const originalTrimmed = (snapshot ?? '').toString().trim();

            if (trimmedTitle === originalTrimmed) {
                this.editedTitle = snapshot;
                this.isEditingTitle = false;
                this.titleSnapshot = null;
                return;
            }

            this.savingTitle = true;

            try {
                this.editedTitle = trimmedTitle;
                const ok = await this.$wire.$parent.$call(this.updatePropertyMethod, this.itemId, this.titleProperty, trimmedTitle, false);

                if (!ok) {
                    this.editedTitle = snapshot;
                    this.$wire.$dispatch('toast', { type: 'error', message: this.titleUpdateErrorToast });
                } else {
                    this.isEditingTitle = false;
                    this.titleSnapshot = null;
                }
            } catch (error) {
                this.editedTitle = snapshot;
                this.$wire.$dispatch('toast', { type: 'error', message: error.message || this.titleUpdateErrorToast });
            } finally {
                this.savingTitle = false;
                if (this.savedViaEnter) {
                    setTimeout(() => { this.savedViaEnter = false; }, 100);
                }
            }
        },
        handleEnterKey() {
            this.savedViaEnter = true;
            this.saveTitle();
        },
        handleBlur() {
            if (!this.savedViaEnter && !this.justCanceledTitle) {
                this.saveTitle();
            }
        },
        startEditingDescription() {
            if (!this.canEdit || this.deletingInProgress || !this.updatePropertyMethod) return;
            this.descriptionSnapshot = this.editedDescription;
            this.isEditingDescription = true;
        },
        cancelEditingDescription() {
            this.justCanceledDescription = true;
            this.savedDescriptionViaEnter = false;
            this.editedDescription = this.descriptionSnapshot ?? '';
            this.isEditingDescription = false;
            this.descriptionSnapshot = null;
            setTimeout(() => { this.justCanceledDescription = false; }, 100);
        },
        async saveDescription() {
            if (this.deletingInProgress || !this.updatePropertyMethod || !this.itemId || this.savingDescription || this.justCanceledDescription) return;

            const trimmedDesc = (this.editedDescription ?? '').toString().trim();
            const snapshot = this.descriptionSnapshot ?? '';
            const originalTrimmed = (snapshot ?? '').toString().trim();

            if (trimmedDesc === originalTrimmed) {
                this.editedDescription = snapshot;
                this.isEditingDescription = false;
                this.descriptionSnapshot = null;
                return;
            }

            this.savingDescription = true;

            try {
                this.editedDescription = trimmedDesc;
                const valueToSave = trimmedDesc === '' ? null : trimmedDesc;
                const ok = await this.$wire.$parent.$call(this.updatePropertyMethod, this.itemId, this.descriptionProperty, valueToSave, false);

                if (!ok) {
                    this.editedDescription = snapshot;
                    this.$wire.$dispatch('toast', { type: 'error', message: this.descriptionUpdateErrorToast });
                } else {
                    this.isEditingDescription = false;
                    this.descriptionSnapshot = null;
                }
            } catch (error) {
                this.editedDescription = snapshot;
                this.$wire.$dispatch('toast', { type: 'error', message: error.message || this.descriptionUpdateErrorToast });
            } finally {
                this.savingDescription = false;
                if (this.savedDescriptionViaEnter) {
                    setTimeout(() => { this.savedDescriptionViaEnter = false; }, 100);
                }
            }
        },
        handleDescriptionKeydown(e) {
            if (e.key === 'Escape') {
                this.cancelEditingDescription();
            } else if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.savedDescriptionViaEnter = true;
                this.saveDescription();
            }
        },
        handleDescriptionBlur() {
            if (!this.savedDescriptionViaEnter && !this.justCanceledDescription) {
                this.saveDescription();
            }
        },
        async updateRecurrence(value) {
            if (!this.updatePropertyMethod || !this.itemId) {
                return;
            }

            const snapshot = this.recurrence;
            this.recurrence = value;

            try {
                const ok = await this.$wire.$parent.$call(this.updatePropertyMethod, this.itemId, 'recurrence', value, false);
                if (!ok) {
                    this.recurrence = snapshot;
                    this.$dispatch('recurring-revert', { path: 'recurrence', value: snapshot });
                    this.$wire.$dispatch('toast', { type: 'error', message: this.recurrenceUpdateErrorToast });
                    return;
                }
                this.$dispatch('recurring-value', { path: 'recurrence', value });
            } catch (e) {
                this.recurrence = snapshot;
                this.$dispatch('recurring-revert', { path: 'recurrence', value: snapshot });
                this.$wire.$dispatch('toast', { type: 'error', message: this.recurrenceUpdateErrorToast });
            }
        },
        onFocusSessionUpdated(incoming) {
            if (!incoming) {
                this.activeFocusSession = null;
                return;
            }
            const isForThisCard = Number(incoming.task_id) === Number(this.itemId);
            if (isForThisCard && this.activeFocusSession?.started_at) {
                this.activeFocusSession = {
                    ...incoming,
                    started_at: this.activeFocusSession.started_at,
                    focus_mode_type: this.activeFocusSession.focus_mode_type ?? incoming.focus_mode_type ?? 'countdown',
                };
            } else {
                this.activeFocusSession = incoming;
            }
            if (isForThisCard) {
                if (incoming.paused_seconds != null && Number.isFinite(Number(incoming.paused_seconds))) {
                    if (!this._focusJustResumed) {
                        this.focusPausedSecondsAccumulated = Math.max(0, Math.floor(Number(incoming.paused_seconds)));
                    }
                }
                if (incoming.paused_at) {
                    this._focusJustResumed = false;
                    this.focusIsPaused = true;
                    if (this.focusPauseStartedAt == null) {
                        this.focusPauseStartedAt = this.parseFocusStartedAt(incoming.paused_at);
                    }
                } else {
                    this._focusJustResumed = false;
                    this.focusIsPaused = false;
                    this.focusPauseStartedAt = null;
                }
            }
        },
        onRecurringSelectionUpdated(detail) {
            if (detail && detail.path === 'recurrence') {
                this.updateRecurrence(detail.value);
            }
        },
        onTaskDurationUpdated(detail) {
            if (!detail || this.kind !== 'task') return;
            if (Number(detail.itemId) !== Number(this.itemId)) return;
            this.taskDurationMinutes = detail.durationMinutes != null ? Number(detail.durationMinutes) : null;
        },
        onItemPropertyUpdated(detail) {
            if (this.shouldHideAfterPropertyUpdate(detail)) {
                this.dateChangeHidingCard = true;
                this.hideFromList(false);
            } else {
                this.dateChangeHidingCard = false;
                const d = detail;
                if (d && d.property === 'status' && this.kind === 'task' && d.value) {
                    this.taskStatus = d.value;
                }
                if (d && ['startDatetime', 'endDatetime'].includes(d.property) && (this.kind === 'task' || this.kind === 'event')) {
                    const stillOverdue = this.isStillOverdue(d.startDatetime ?? null, d.endDatetime ?? null);
                    if (stillOverdue) {
                        this.clientNotOverdue = false;
                        if (!this.isOverdue) {
                            this.clientOverdue = true;
                        }
                    } else {
                        this.clientOverdue = false;
                        this.clientNotOverdue = true;
                    }
                }
            }
        },
        onItemUpdateRollback() {
            if (this.hideCard) {
                this.hideCard = false;
                this.$dispatch('list-item-shown', { fromOverdue: this.isOverdue });
            }
        },
    };
}

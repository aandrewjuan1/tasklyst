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
        },
        get isFocused() {
            return this.kind === 'task' && this.activeFocusSession && Number(this.activeFocusSession.task_id) === Number(this.itemId);
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
            const nowMs = this.focusTickerNow ?? Date.now();
            const elapsedSec = Math.max(0, (nowMs - startedMs) / 1000);
            let pausedSec = this.focusPausedSecondsAccumulated;
            if (this.activeFocusSession.paused_at) {
                const pausedAtMs = this.parseFocusStartedAt(this.activeFocusSession.paused_at);
                if (Number.isFinite(pausedAtMs)) {
                    pausedSec += (Date.now() - pausedAtMs) / 1000;
                }
            } else if (pausedSec === 0 && this.activeFocusSession.paused_seconds != null && Number.isFinite(Number(this.activeFocusSession.paused_seconds))) {
                pausedSec = Math.max(0, Math.floor(Number(this.activeFocusSession.paused_seconds)));
            }
            if (this.focusIsPaused && this.focusPauseStartedAt) {
                pausedSec += (Date.now() - this.focusPauseStartedAt) / 1000;
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
        startFocusTicker() {
            if (this.focusIntervalId != null) return;
            this.sessionComplete = false;
            this.focusTickerNow = Date.now();
            this.focusElapsedPercentValue = this.focusElapsedPercent;
            this.focusIntervalId = setInterval(() => {
                if (this.focusIsPaused) return;
                this.focusTickerNow = Date.now();
                this.focusElapsedPercentValue = this.focusElapsedPercent;
                if (this.focusRemainingSeconds <= 0) {
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
        },
        dismissCompletedFocus() {
            if (this._completedDismissTimeoutId != null) {
                clearTimeout(this._completedDismissTimeoutId);
                this._completedDismissTimeoutId = null;
            }
            this.activeFocusSession = null;
            this.dispatchFocusSessionUpdated(null);
            this.sessionComplete = false;
        },
        async pauseFocus() {
            if (!this.isFocused || this.focusIsPaused) return;
            const sessionId = this.activeFocusSession?.id;
            this.focusIsPaused = true;
            this.focusPauseStartedAt = Date.now();
            this.focusTickerNow = Date.now();
            this.focusElapsedPercentValue = this.focusElapsedPercent;
            if (sessionId != null && !this.isTempSessionId(sessionId)) {
                try {
                    const ok = await this.$wire.$parent.$call('pauseFocusSession', sessionId);
                    if (ok === false) {
                        this.focusIsPaused = false;
                        this.focusPauseStartedAt = null;
                        this.activeFocusSession = null;
                        this.dispatchFocusSessionUpdated(null);
                        this.$wire.$dispatch('toast', { type: 'error', message: this.focusSessionNoLongerActiveToast });
                    }
                } catch (err) {
                    this.focusIsPaused = false;
                    this.focusPauseStartedAt = null;
                    this.$wire.$dispatch('toast', { type: 'error', message: this.focusStopErrorToast });
                }
            }
        },
        async resumeFocus() {
            if (!this.isFocused || !this.focusIsPaused || !this.focusPauseStartedAt) return;
            const sessionId = this.activeFocusSession?.id;
            const pauseStartMs = this.focusPauseStartedAt;
            const segmentSec = (Date.now() - pauseStartMs) / 1000;
            this.focusPausedSecondsAccumulated += segmentSec;
            this.focusPauseStartedAt = null;
            this.focusIsPaused = false;
            this.focusTickerNow = Date.now();
            this.focusElapsedPercentValue = this.focusElapsedPercent;
            this._focusJustResumed = true;
            if (sessionId != null && !this.isTempSessionId(sessionId)) {
                try {
                    const ok = await this.$wire.$parent.$call('resumeFocusSession', sessionId);
                    if (ok === false) {
                        this._focusJustResumed = false;
                        this.focusPausedSecondsAccumulated = Math.max(0, this.focusPausedSecondsAccumulated - segmentSec);
                        this.focusIsPaused = true;
                        this.focusPauseStartedAt = pauseStartMs;
                        this.activeFocusSession = null;
                        this.dispatchFocusSessionUpdated(null);
                        this.$wire.$dispatch('toast', { type: 'error', message: this.focusSessionNoLongerActiveToast });
                    }
                } catch (err) {
                    this._focusJustResumed = false;
                    this.focusPausedSecondsAccumulated = Math.max(0, this.focusPausedSecondsAccumulated - segmentSec);
                    this.focusIsPaused = true;
                    this.focusPauseStartedAt = pauseStartMs;
                    this.$wire.$dispatch('toast', { type: 'error', message: this.focusStopErrorToast });
                }
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
            const minutes = this.taskDurationMinutes != null && this.taskDurationMinutes > 0
                ? Number(this.taskDurationMinutes) : this.defaultWorkDurationMinutes;
            const durationSeconds = Math.max(60, Math.min(7200, minutes * 60));
            const startedAt = new Date().toISOString();
            const payload = {
                type: 'work',
                duration_seconds: durationSeconds,
                started_at: startedAt,
                payload: { used_task_duration: !!(this.taskDurationMinutes != null && this.taskDurationMinutes > 0) },
            };
            const optimisticSession = {
                id: 'temp-' + Date.now(),
                task_id: this.itemId,
                started_at: startedAt,
                duration_seconds: durationSeconds,
                type: 'work',
                sequence_number: 1,
            };
            const promise = this.$wire.$parent.$call('startFocusSession', this.itemId, payload);
            this.pendingStartPromise = promise;
            try {
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
                    this.activeFocusSession = null;
                    this.dispatchFocusSessionUpdated(null);
                    this.$wire.$dispatch('toast', { type: 'error', message: (typeof result.error === 'string' ? result.error : null) || this.focusStartErrorToast });
                    return;
                }
                const merged = { ...result, started_at: this.activeFocusSession?.started_at || result.started_at };
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
            const sessionSnapshot = { ...this.activeFocusSession };
            if (this.isTempSessionId(sessionSnapshot.id)) {
                this.focusStopRequestedBeforeStartResolved = true;
                this.activeFocusSession = null;
                this.dispatchFocusSessionUpdated(null);
                return;
            }
            const pausedSeconds = this.getFocusPausedSecondsTotal();
            try {
                this.activeFocusSession = null;
                this.dispatchFocusSessionUpdated(null);
                await this.$wire.$parent.$call('abandonFocusSession', sessionSnapshot.id, { paused_seconds: pausedSeconds });
            } catch (error) {
                this.activeFocusSession = sessionSnapshot;
                this.dispatchFocusSessionUpdated(sessionSnapshot);
                this.$wire.$dispatch('toast', { type: 'error', message: error.message || this.focusStopErrorToast });
            }
        },
        hideFromList() {
            if (this.hideCard) {
                return;
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
        isStillOverdue(startDatetime, endDatetime) {
            const today = new Date();
            const todayStr = today.toISOString().slice(0, 10);
            const parseDateTime = (value) => {
                if (value == null || value === '') {
                    return null;
                }
                const d = new Date(value);
                return Number.isNaN(d.getTime()) ? null : d;
            };
            const end = parseDateTime(endDatetime);
            if (!end) {
                return false;
            }
            try {
                const endDateStr = end.toISOString().slice(0, 10);
                return endDateStr < todayStr;
            } catch (_) {
                return true;
            }
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
                this.activeFocusSession = { ...incoming, started_at: this.activeFocusSession.started_at };
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
                if (d && ['startDatetime', 'endDatetime'].includes(d.property) && (this.kind === 'task' || this.kind === 'event')) {
                    const stillOverdue = this.isStillOverdue(d.startDatetime ?? null, d.endDatetime ?? null);
                    if (!this.isOverdue && stillOverdue) {
                        this.clientOverdue = true;
                        this.clientNotOverdue = false;
                    } else if (this.isOverdue && !stillOverdue) {
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

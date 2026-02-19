/**
 * Alpine.js component for the workspace list item card.
 * Config is provided by ListItemCardViewModel::alpineConfig().
 *
 * @param {Object} config - Initial state from server (no functions).
 * @returns {Object} Alpine component object (state + methods).
 */

import {
    parseFocusStartedAt as parseFocusStartedAtLib,
    formatFocusCountdown as formatFocusCountdownLib,
    formatDurationMinutes,
} from '../lib/focus-time.js';
import {
    isItemStillRelevantForList,
    isStillOverdue as isStillOverdueLib,
} from '../lib/list-relevance.js';
import {
    getPomodoroSettingsPayload as getPomodoroSettingsPayloadLib,
    predictNextPomodoroSessionInfo as predictNextPomodoroSessionInfoLib,
} from '../lib/pomodoro.js';
import { createFocusSessionController, isTempSessionId as isTempSessionIdLib } from './focus-session.js';

export function listItemCard(config) {
    return {
        ...config,
        focusReady: false,
        focusCountdownText: '',
        focusProgressStyle: 'width: 0%; min-width: 0',
        nextSessionInfo: null, // Stores next session info from pomodoro completion
        isBreakSession: false, // Tracks if current session is a break
        lastPomodoroTaskId: null, // Tracks last task used for pomodoro (for auto-start next pomodoro)
        pomodoroSequence: 1, // Current pomodoro sequence number (server-driven)
        pomodoroWorkCount: 0, // Local count of work pomodoros in the current chain (for display)
        completingPomodoro: false, // Prevent duplicate pomodoro completion calls
        startingNextSessionInProgress: false, // Prevent race conditions when starting next session
        _onPomodoroStartNextWork: null, // Holder for global pomodoro event handler
        _audioContext: null, // Shared audio context for completion sounds
        _audioGainNode: null, // Shared gain node for completion sounds
        init() {
            this._focus = createFocusSessionController();
            this._focus.init(this);
            if (this.itemId != null && window.Alpine?.store) {
                let store = window.Alpine.store('listItemCards');
                if (!store || typeof store !== 'object') {
                    window.Alpine.store('listItemCards', {});
                    store = window.Alpine.store('listItemCards');
                }
                store[this.itemId] = this;
            }
        },
        get isFocused() {
            return this.kind === 'task' && this.activeFocusSession && this.activeFocusSession.type === 'work' && Number(this.activeFocusSession.task_id) === Number(this.itemId);
        },
        get isBreakFocused() {
            if (!this.activeFocusSession) return false;
            const isBreak = this.activeFocusSession.type === 'short_break' || this.activeFocusSession.type === 'long_break';
            if (!isBreak) return false;
            const taskId = this.activeFocusSession.owner_task_id ?? this.activeFocusSession.task_id;
            if (taskId == null) return true;
            return this.kind === 'task' && Number(taskId) === Number(this.itemId);
        },
        get isPomodoroSession() {
            if (!this.activeFocusSession) return false;
            const payload = this.activeFocusSession.payload || {};
            const focusModeType = payload.focus_mode_type ?? this.activeFocusSession.focus_mode_type;
            return focusModeType === 'pomodoro' || 
                   this.activeFocusSession.type === 'short_break' || 
                   this.activeFocusSession.type === 'long_break';
        },
        get pomodoroSequenceText() {
            if (!this.isPomodoroSession || !this.activeFocusSession) return '';
            const longBreakAfter = Math.max(2, Math.min(10, Math.floor(Number(this.pomodoroLongBreakAfter ?? 4))));
            const workCount = this.pomodoroWorkCount && this.pomodoroWorkCount > 0 ? this.pomodoroWorkCount : 1;
            const cycleIndex = Math.floor((workCount - 1) / longBreakAfter) + 1;

            return `Pomodoros: ${workCount} · Cycle ${cycleIndex}`;
        },
        get nextSessionDurationText() {
            if (!this.nextSessionInfo) return '';
            return formatFocusCountdownLib(this.nextSessionInfo.duration_seconds);
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
            return formatDurationMinutes(this.focusReadyDurationMinutes, {
                minLabel: this.focusDurationLabelMin ?? 'min',
                hrLabel: this.focusDurationLabelHr ?? 'hour',
                hrsLabel: this.focusDurationLabelHrs ?? 'hours',
            });
        },
        get formattedPomodoroWorkDuration() {
            return formatDurationMinutes(this.pomodoroWorkMinutes ?? 25, {
                minLabel: this.focusDurationLabelMin ?? 'min',
                hrLabel: this.focusDurationLabelHr ?? 'hour',
                hrsLabel: this.focusDurationLabelHrs ?? 'hours',
            });
        },
        get pomodoroSummaryText() {
            const work = Math.max(0, Math.floor(Number(this.pomodoroWorkMinutes ?? 25)));
            const short = Math.max(0, Math.floor(Number(this.pomodoroShortBreakMinutes ?? 5)));
            const long = Math.max(0, Math.floor(Number(this.pomodoroLongBreakMinutes ?? 15)));
            const every = Math.max(2, Math.min(10, Math.floor(Number(this.pomodoroLongBreakAfter ?? 4))));
            const minLabel = this.focusDurationLabelMin ?? 'min';
            const everyLabel = this.pomodoroLongBreakEveryLabel ?? 'Long break every';
            // Use lightweight icons for a more visual summary:
            // Work: X → Short break: Y → Long break: Z ⟳ Long break every N pomodoros
            return `Work: ${work} ${minLabel} → Short break: ${short} ${minLabel} → Long break: ${long} ${minLabel} ⟳ ${everyLabel} ${every} pomodoros`;
        },
        formatPomodoroDurationMinutes(minutes) {
            return formatDurationMinutes(minutes, {
                minLabel: this.focusDurationLabelMin ?? 'min',
                hrLabel: this.focusDurationLabelHr ?? 'hour',
                hrsLabel: this.focusDurationLabelHrs ?? 'hours',
            });
        },
        getPomodoroSettingsPayload() {
            return getPomodoroSettingsPayloadLib({
                pomodoroWorkMinutes: this.pomodoroWorkMinutes,
                pomodoroShortBreakMinutes: this.pomodoroShortBreakMinutes,
                pomodoroLongBreakMinutes: this.pomodoroLongBreakMinutes,
                pomodoroLongBreakAfter: this.pomodoroLongBreakAfter,
                pomodoroSoundVolume: this.pomodoroSoundVolume,
                pomodoroAutoStartBreak: this.pomodoroAutoStartBreak,
                pomodoroAutoStartPomodoro: this.pomodoroAutoStartPomodoro,
                pomodoroSoundEnabled: this.pomodoroSoundEnabled,
            });
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
            if (this.kind === 'task') this._pomodoroLastSavedPayload = JSON.stringify(this.getPomodoroSettingsPayload());
        },
        async savePomodoroSettings() {
            return this._focus.savePomodoroSettings(this);
        },
        enterFocusReady() {
            this._focus.enterFocusReady(this);
        },
        async startFocusFromReady() {
            return this._focus.startFocusFromReady(this);
        },
        parseFocusStartedAt(isoString) {
            return parseFocusStartedAtLib(isoString);
        },
        get focusRemainingSeconds() {
            if ((!this.isFocused && !this.isBreakFocused) || !this.activeFocusSession?.started_at || !this.activeFocusSession?.duration_seconds) return 0;
            const startedMs = parseFocusStartedAtLib(this.activeFocusSession.started_at);
            if (!Number.isFinite(startedMs)) return 0;
            const durationSec = Number(this.activeFocusSession.duration_seconds);
            
            // Cache Date.now() once per evaluation to avoid multiple calls
            const nowMs = this.focusTickerNow ?? Date.now();
            
            const elapsedSec = Math.max(0, (nowMs - startedMs) / 1000);
            let pausedSec = this.focusPausedSecondsAccumulated;
            if (this.activeFocusSession.paused_at) {
                const pausedAtMs = parseFocusStartedAtLib(this.activeFocusSession.paused_at);
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
            return formatFocusCountdownLib(seconds);
        },
        isTempSessionId(id) {
            return isTempSessionIdLib(id);
        },
        syncFocusTicker() {
            this._focus.syncFocusTicker(this);
        },
        startFocusTicker() {
            this._focus.startFocusTicker(this);
        },
        stopFocusTicker() {
            this._focus.stopFocusTicker(this);
        },
        dismissCompletedFocus() {
            this._focus.dismissCompletedFocus(this);
        },
        predictNextPomodoroSessionInfo() {
            return predictNextPomodoroSessionInfoLib(
                this.activeFocusSession,
                {
                    pomodoroWorkMinutes: this.pomodoroWorkMinutes,
                    pomodoroShortBreakMinutes: this.pomodoroShortBreakMinutes,
                    pomodoroLongBreakMinutes: this.pomodoroLongBreakMinutes,
                    pomodoroLongBreakAfter: this.pomodoroLongBreakAfter,
                    pomodoroAutoStartBreak: this.pomodoroAutoStartBreak,
                    pomodoroAutoStartPomodoro: this.pomodoroAutoStartPomodoro,
                },
                this.pomodoroSequence
            );
        },
        optimisticallyStartNextPomodoroSession(nextSessionInfo, startedAt) {
            this._focus.optimisticallyStartNextPomodoroSession(this, nextSessionInfo, startedAt);
        },
        async completePomodoroSession(sessionId, pausedSeconds) {
            return this._focus.completePomodoroSession(this, sessionId, pausedSeconds);
        },
        playCompletionSound() {
            this._focus.playCompletionSound(this);
        },
        async startNextSession(nextSessionInfo) {
            return this._focus.startNextSession(this, nextSessionInfo);
        },
        async startNextPomodoroWorkSession(nextSessionInfo, taskId, startedAt) {
            return this._focus.startNextPomodoroWorkSession(this, nextSessionInfo, taskId, startedAt);
        },
        async startBreakSession(nextSessionInfo, startedAt) {
            return this._focus.startBreakSession(this, nextSessionInfo, startedAt);
        },
        async markTaskDoneFromFocus() {
            return this._focus.markTaskDoneFromFocus(this);
        },
        async pauseFocus() {
            return this._focus.pauseFocus(this);
        },
        async resumeFocus() {
            return this._focus.resumeFocus(this);
        },
        getFocusPausedSecondsTotal() {
            return this._focus.getFocusPausedSecondsTotal(this);
        },
        dispatchFocusSessionUpdated(session) {
            this._focus.dispatchFocusSessionUpdated(this, session);
        },
        async startFocusMode() {
            return this._focus.startFocusMode(this);
        },
        async stopFocus() {
            return this._focus.stopFocus(this);
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
            if (this.kind !== 'task' || !this.listFilterDate) return true;
            return isItemStillRelevantForList(startDatetime, endDatetime, String(this.listFilterDate).slice(0, 10));
        },
        isEventStillRelevantForList(startDatetime, endDatetime) {
            if (this.kind !== 'event' || !this.listFilterDate) return true;
            return isItemStillRelevantForList(startDatetime, endDatetime, String(this.listFilterDate).slice(0, 10));
        },
        isProjectStillRelevantForList(startDatetime, endDatetime) {
            if (this.kind !== 'project' || !this.listFilterDate) return true;
            return isItemStillRelevantForList(startDatetime, endDatetime, String(this.listFilterDate).slice(0, 10));
        },
        isStillOverdue(startDatetime, endDatetime) {
            return isStillOverdueLib(startDatetime, endDatetime);
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

            // PHASE 1: Snapshot BEFORE any changes
            const snapshot = {
                hideCard: this.hideCard,
                focusReady: this.focusReady,
                activeFocusSession: this.activeFocusSession ? { ...this.activeFocusSession } : null,
            };

            this.deletingInProgress = true;

            try {
                // PHASE 2: Optimistic UI update - hide card immediately
                this.hideFromList(false);

                // PHASE 3: Call server asynchronously
                const promise = this.$wire.$parent.$call(this.deleteMethod, this.itemId);

                // PHASE 4: Handle response
                const ok = await promise;
                if (!ok) {
                    // PHASE 5: Rollback on error
                    this.hideCard = snapshot.hideCard;
                    this.focusReady = snapshot.focusReady;
                    this.activeFocusSession = snapshot.activeFocusSession;
                    if (snapshot.activeFocusSession) {
                        this.dispatchFocusSessionUpdated(snapshot.activeFocusSession);
                    }
                    this.$dispatch('list-item-shown', { fromOverdue: wasOverdue });
                    this.$wire.$dispatch('toast', { type: 'error', message: this.deleteErrorToast });
                }
            } catch (e) {
                // PHASE 5: Rollback on error
                this.hideCard = snapshot.hideCard;
                this.focusReady = snapshot.focusReady;
                this.activeFocusSession = snapshot.activeFocusSession;
                if (snapshot.activeFocusSession) {
                    this.dispatchFocusSessionUpdated(snapshot.activeFocusSession);
                }
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
            this._focus.onFocusSessionUpdated(this, incoming);
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
        /** Unregisters this card from listItemCards store and clears focus ticker/listeners via the focus controller. */
        destroy() {
            if (this.itemId != null && window.Alpine?.store) {
                const store = window.Alpine.store('listItemCards');
                if (store && typeof store === 'object') {
                    delete store[this.itemId];
                }
            }
            this._focus.destroy(this);
        },
    };
}

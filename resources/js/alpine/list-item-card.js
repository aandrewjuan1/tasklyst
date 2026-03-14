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
    getFocusRemainingSeconds,
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

const FOCUS_MODAL_FOCUSABLE_SELECTOR =
    'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

export function listItemCard(config) {
    return {
        ...config,
        focusReady: false,
        focusCountdownText: '',
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
            // Sync focus modal store so layout can react (scroll lock uses openItemId)
            this.$watch(
                'isFocusModalOpen',
                (open) => {
                    const focusModal = window.Alpine?.store?.('focusModal');
                    if (!focusModal) return;
                    if (open) {
                        focusModal.openItemId = this.itemId;
                        this.$nextTick(() => this.focusFirstInModal());
                    } else if (focusModal.openItemId === this.itemId) {
                        focusModal.openItemId = null;
                    }
                },
                { immediate: true }
            );
            this._onSubtaskUnbound = (e) => {
                const d = e.detail || {};
                if (this.kind !== 'task' || d.taskId == null || Number(d.taskId) !== Number(this.itemId)) return;
                if (d.unboundProjectId != null && Number(d.unboundProjectId) === Number(this.itemProjectId)) {
                    this.showProjectPill = false;
                    this.itemProjectId = null;
                    this.itemProjectName = null;
                }
                if (d.unboundEventId != null && Number(d.unboundEventId) === Number(this.itemEventId)) {
                    this.showEventPill = false;
                    this.itemEventId = null;
                    this.itemEventTitle = null;
                }
            };
            window.addEventListener('workspace-subtask-unbound', this._onSubtaskUnbound);

            this._onParentSet = (e) => {
                const d = e.detail || {};
                if (this.kind !== 'task' || d.taskId == null || Number(d.taskId) !== Number(this.itemId)) return;
                const previousProjectId = this.itemProjectId != null ? this.itemProjectId : null;
                const previousEventId = this.itemEventId != null ? this.itemEventId : null;
                if ('projectId' in d) {
                    this.showProjectPill = d.projectId != null;
                    this.itemProjectId = d.projectId ?? null;
                    this.itemProjectName = d.projectName ?? null;
                }
                if ('eventId' in d) {
                    this.showEventPill = d.eventId != null;
                    this.itemEventId = d.eventId ?? null;
                    this.itemEventTitle = d.eventTitle ?? null;
                }
                if ('projectId' in d && previousProjectId != null && (d.projectId == null || d.projectId === undefined)) {
                    window.dispatchEvent(
                        new CustomEvent('workspace-subtask-unbound', {
                            detail: {
                                taskId: this.itemId,
                                unboundProjectId: previousProjectId,
                                unboundEventId: null,
                            },
                            bubbles: true,
                        })
                    );
                }
                if ('eventId' in d && previousEventId != null && (d.eventId == null || d.eventId === undefined)) {
                    window.dispatchEvent(
                        new CustomEvent('workspace-subtask-unbound', {
                            detail: {
                                taskId: this.itemId,
                                unboundProjectId: null,
                                unboundEventId: previousEventId,
                            },
                            bubbles: true,
                        })
                    );
                }
                // Notify parent subtasks so they can add this task to their list (optimistic)
                if (d.projectId != null || d.eventId != null) {
                    window.dispatchEvent(
                        new CustomEvent('workspace-subtask-added', {
                            detail: {
                                taskId: this.itemId,
                                projectId: d.projectId ?? null,
                                projectName: d.projectName ?? null,
                                eventId: d.eventId ?? null,
                                eventTitle: d.eventTitle ?? null,
                                title: this.editedTitle ?? '',
                                statusLabel: this.taskStatusLabel ?? '',
                                statusClass: this.taskStatusClass ?? 'bg-muted text-muted-foreground',
                            },
                            bubbles: true,
                        })
                    );
                }
            };
            window.addEventListener('workspace-task-parent-set', this._onParentSet);

            this._onProjectNameUpdated = (e) => {
                const d = e.detail || {};
                if (this.kind !== 'task' || d.projectId == null) return;
                if (Number(d.projectId) === Number(this.itemProjectId)) {
                    this.itemProjectName = d.name ?? null;
                }
            };
            this._onEventTitleUpdated = (e) => {
                const d = e.detail || {};
                if (this.kind !== 'task' || d.eventId == null) return;
                if (Number(d.eventId) === Number(this.itemEventId)) {
                    this.itemEventTitle = d.title ?? null;
                }
            };
            window.addEventListener('workspace-project-name-updated', this._onProjectNameUpdated);
            window.addEventListener('workspace-event-title-updated', this._onEventTitleUpdated);

            this._onProjectTrashed = (e) => {
                const d = e.detail || {};
                if (this.kind !== 'task' || d.projectId == null) return;
                if (Number(d.projectId) === Number(this.itemProjectId)) {
                    this.showProjectPill = false;
                    this.itemProjectId = null;
                    this.itemProjectName = null;
                }
            };
            this._onEventTrashed = (e) => {
                const d = e.detail || {};
                if (this.kind !== 'task' || d.eventId == null) return;
                if (Number(d.eventId) === Number(this.itemEventId)) {
                    this.showEventPill = false;
                    this.itemEventId = null;
                    this.itemEventTitle = null;
                }
            };
            window.addEventListener('workspace-project-trashed', this._onProjectTrashed);
            window.addEventListener('workspace-event-trashed', this._onEventTrashed);
        },
        /** Focus first focusable element in the modal (a11y). */
        focusFirstInModal() {
            const panel = this.$refs?.focusModalPanel;
            if (!panel) return;
            const focusable = panel.querySelector(FOCUS_MODAL_FOCUSABLE_SELECTOR);
            if (focusable?.focus) focusable.focus({ preventScroll: true });
        },
        /** Keep Tab focus inside the modal (a11y focus trap). */
        trapFocusInModal(e) {
            if (e.key !== 'Tab' || !this.isFocusModalOpen) return;
            const panel = this.$refs?.focusModalPanel;
            if (!panel || !panel.contains(e.target)) return;
            const list = panel.querySelectorAll(FOCUS_MODAL_FOCUSABLE_SELECTOR);
            const focusables = Array.from(list).filter((el) => el.offsetParent !== null && !el.hasAttribute('disabled'));
            if (focusables.length === 0) return;
            const i = focusables.indexOf(document.activeElement);
            if (e.shiftKey) {
                if (i <= 0) {
                    e.preventDefault();
                    focusables[focusables.length - 1].focus();
                }
            } else {
                if (i === -1 || i >= focusables.length - 1) {
                    e.preventDefault();
                    focusables[0].focus();
                }
            }
        },
        /** True when focus modal is visible (ready, focused, or break). */
        get isFocusModalOpen() {
            return !!(this.focusReady || this.isFocused || this.isBreakFocused);
        },
        /** Close the focus modal and return focus to the trigger without scrolling. */
        closeFocusModal() {
            this.focusReady = false;
            this.restoreFocusAfterModalClose();
        },
        /** Return focus to the in-list Focus trigger without scrolling (used on any modal close). */
        restoreFocusAfterModalClose() {
            this.$nextTick(() => {
                const el = this.$refs?.focusTrigger;
                if (el?.focus) el.focus({ preventScroll: true });
            });
        },
        /** True when this card has focus modal open or an active focus/break session — use for locking list card and hiding chevrons */
        get isCardLockedForFocus() {
            return this.isFocusModalOpen;
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
            await this._focus.startFocusFromReady(this);
            this.$nextTick(() => this.focusFirstInModal());
        },
        parseFocusStartedAt(isoString) {
            return parseFocusStartedAtLib(isoString);
        },
        get focusRemainingSeconds() {
            if ((!this.isFocused && !this.isBreakFocused) || !this.activeFocusSession?.started_at || !this.activeFocusSession?.duration_seconds) return 0;
            const nowMs = this.focusTickerNow ?? Date.now();
            return getFocusRemainingSeconds(this.activeFocusSession, nowMs, {
                pausedSecondsAccumulated: this.focusPausedSecondsAccumulated,
                isPaused: this.focusIsPaused,
                pauseStartedAtMs: this.focusPauseStartedAt ?? null,
            });
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
            if (this.kind === 'task' && this.itemId != null) {
                window.dispatchEvent(
                    new CustomEvent('workspace-item-visibility-updated', {
                        detail: { kind: 'task', itemId: this.itemId, visible: false },
                        bubbles: true,
                    }),
                );
            }
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
            // Never hide task card when user marks task as done (match default no-filter behaviour)
            if (this.kind === 'task' && property === 'status' && value === 'done') {
                return false;
            }
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
                // Never hide when user marks task as done; keep card visible like default (no filter) behaviour
                if (f.taskStatus && property === 'status' && value !== f.taskStatus && value !== 'done') return true;
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

            return false;
        },
        rollbackDeleteItem(snapshot, wasOverdue) {
            this.hideCard = snapshot.hideCard;
            this.focusReady = snapshot.focusReady;
            this.activeFocusSession = snapshot.activeFocusSession;
            if (snapshot.activeFocusSession) {
                this.dispatchFocusSessionUpdated(snapshot.activeFocusSession);
            }
            this.$dispatch('list-item-shown', { fromOverdue: wasOverdue });
            window.dispatchEvent(
                new CustomEvent('workspace-item-trashed-rollback', {
                    detail: { kind: this.kind, id: this.itemId },
                    bubbles: true,
                })
            );
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
                this.hideFromList();
                if (this.kind === 'task' && this.itemId != null) {
                    window.dispatchEvent(
                        new CustomEvent('workspace-subtask-trashed', {
                            detail: { taskId: this.itemId },
                            bubbles: true,
                        })
                    );
                }
                if (this.kind === 'project' && this.itemId != null) {
                    window.dispatchEvent(
                        new CustomEvent('workspace-project-trashed', {
                            detail: { projectId: this.itemId },
                            bubbles: true,
                        })
                    );
                }
                if (this.kind === 'event' && this.itemId != null) {
                    window.dispatchEvent(
                        new CustomEvent('workspace-event-trashed', {
                            detail: { eventId: this.itemId },
                            bubbles: true,
                        })
                    );
                }
                // Notify trash popover so it can add the item to its list (optimistic)
                window.dispatchEvent(
                    new CustomEvent('workspace-item-trashed', {
                        detail: {
                            kind: this.kind,
                            id: this.itemId,
                            title: this.editedTitle ?? '',
                            deleted_at_display: 'Just now',
                        },
                        bubbles: true,
                    })
                );

                // PHASE 3: Call server asynchronously
                const promise = this.$wire.$parent.$call(this.deleteMethod, this.itemId);

                // PHASE 4: Handle response
                const ok = await promise;
                if (!ok) {
                    this.rollbackDeleteItem(snapshot, wasOverdue);
                    this.$wire.$dispatch('toast', { type: 'error', message: this.deleteErrorToast });
                }
            } catch (e) {
                this.rollbackDeleteItem(snapshot, wasOverdue);
                this.$wire.$dispatch('toast', { type: 'error', message: this.deleteErrorToast });
            } finally {
                this.deletingInProgress = false;
            }
        },
        async skipThisOccurrence() {
            const canSkip =
                (this.kind === 'event' && this.recurringEventId != null && this.exceptionDate) ||
                (this.kind === 'task' && this.recurringTaskId != null && this.exceptionDate);
            if (!canSkip || this.skipInProgress || this.hideCard) {
                return;
            }

            const exceptionDate = String(this.exceptionDate ?? '').slice(0, 10);
            if (!exceptionDate) {
                return;
            }

            const wasOverdue = this.isOverdue;

            // PHASE 1: Snapshot BEFORE any changes
            const snapshot = { hideCard: this.hideCard };

            this.skipInProgress = true;

            try {
                // PHASE 2: Optimistic UI update - hide card immediately
                this.hideFromList();
                // PHASE 3: Call server asynchronously
                const method =
                    this.kind === 'event' ? 'skipRecurringEventOccurrence' : 'skipRecurringTaskOccurrence';
                const payload =
                    this.kind === 'event'
                        ? {
                              recurringEventId: this.recurringEventId,
                              exceptionDate,
                              isDeleted: true,
                          }
                        : {
                              recurringTaskId: this.recurringTaskId,
                              exceptionDate,
                              isDeleted: true,
                          };
                const promise = this.$wire.$parent.$call(method, payload);
                // PHASE 4: Handle response
                const result = await promise;
                if (result == null) {
                    // PHASE 5: Rollback on error
                    this.hideCard = snapshot.hideCard;
                    this.$dispatch('list-item-shown', { fromOverdue: wasOverdue });
                    this.$wire.$dispatch('toast', {
                        type: 'error',
                        message: this.getSkipErrorMessage(null),
                    });
                }
            } catch (e) {
                // PHASE 5: Rollback on error
                this.hideCard = snapshot.hideCard;
                this.$dispatch('list-item-shown', { fromOverdue: wasOverdue });
                this.$wire.$dispatch('toast', {
                    type: 'error',
                    message: this.getSkipErrorMessage(e),
                });
            } finally {
                this.skipInProgress = false;
            }
        },
        getSkipErrorMessage(error) {
            const status = error?.status ?? error?.statusCode;
            if (status === 403) return this.skipOccurrenceErrorPermission ?? this.skipOccurrenceErrorToast;
            if (status === 404) return this.skipOccurrenceErrorNotFound ?? this.skipOccurrenceErrorToast;
            if (status === 422) return this.skipOccurrenceErrorValidation ?? this.skipOccurrenceErrorToast;
            return error?.message ?? this.skipOccurrenceErrorToast ?? 'Could not skip occurrence.';
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
                    if (this.kind === 'task' && this.itemId != null) {
                        window.dispatchEvent(
                            new CustomEvent('workspace-item-property-updated', {
                                detail: {
                                    kind: 'task',
                                    itemId: this.itemId,
                                    property: 'title',
                                    value: trimmedTitle,
                                },
                                bubbles: true,
                            }),
                        );
                    }
                    if (this.kind === 'project' && this.itemId != null) {
                        window.dispatchEvent(
                            new CustomEvent('workspace-project-name-updated', {
                                detail: { projectId: this.itemId, name: trimmedTitle },
                                bubbles: true,
                            })
                        );
                    }
                    if (this.kind === 'event' && this.itemId != null) {
                        window.dispatchEvent(
                            new CustomEvent('workspace-event-title-updated', {
                                detail: { eventId: this.itemId, title: trimmedTitle },
                                bubbles: true,
                            })
                        );
                    }
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
                    if (this.kind === 'task' && this.itemId != null) {
                        window.dispatchEvent(
                            new CustomEvent('workspace-item-property-updated', {
                                detail: {
                                    kind: 'task',
                                    itemId: this.itemId,
                                    property: 'description',
                                    value: valueToSave,
                                },
                                bubbles: true,
                            }),
                        );
                    }
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

            const applyRevert = () => {
                this.showSkipOccurrence = !!(snapshot?.enabled);
                if (!(snapshot?.enabled)) {
                    this.recurringTaskId = null;
                    this.recurringEventId = null;
                }
            };

            try {
                const result = await this.$wire.$parent.$call(this.updatePropertyMethod, this.itemId, 'recurrence', value, false);
                const ok = result === true || (result && result.success === true);
                if (!ok) {
                    this.recurrence = snapshot;
                    applyRevert();
                    this.$dispatch('recurring-revert', { path: 'recurrence', value: snapshot });
                    this.$wire.$dispatch('toast', { type: 'error', message: this.recurrenceUpdateErrorToast });
                    return;
                }
                this.showSkipOccurrence = !!(value?.enabled);
                if (value?.enabled && result && typeof result === 'object') {
                    if (result.recurringTaskId != null) this.recurringTaskId = result.recurringTaskId;
                    if (result.recurringEventId != null) this.recurringEventId = result.recurringEventId;
                }
                this.$dispatch('recurring-value', { path: 'recurrence', value });
            } catch (e) {
                this.recurrence = snapshot;
                applyRevert();
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
        onRecurringRevert(detail) {
            if (!detail || detail.path !== 'recurrence') return;
            const v = detail.value;
            this.showSkipOccurrence = !!(v?.enabled);
            if (!(v?.enabled)) {
                this.recurringTaskId = null;
                this.recurringEventId = null;
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
                this.hideFromList();
            } else {
                this.dateChangeHidingCard = false;
                const d = detail;
                if (d && d.property === 'status' && this.kind === 'task' && d.value) {
                    this.taskStatus = d.value;
                }
                 if (d && d.property === 'title' && this.kind === 'task') {
                     this.editedTitle = d.value ?? '';
                 }
                 if (d && d.property === 'description' && this.kind === 'task') {
                     this.editedDescription = d.value ?? '';
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
            if (this._onSubtaskUnbound) {
                window.removeEventListener('workspace-subtask-unbound', this._onSubtaskUnbound);
            }
            if (this._onParentSet) {
                window.removeEventListener('workspace-task-parent-set', this._onParentSet);
            }
            if (this._onProjectNameUpdated) {
                window.removeEventListener('workspace-project-name-updated', this._onProjectNameUpdated);
            }
            if (this._onEventTitleUpdated) {
                window.removeEventListener('workspace-event-title-updated', this._onEventTitleUpdated);
            }
            if (this._onProjectTrashed) {
                window.removeEventListener('workspace-project-trashed', this._onProjectTrashed);
            }
            if (this._onEventTrashed) {
                window.removeEventListener('workspace-event-trashed', this._onEventTrashed);
            }
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

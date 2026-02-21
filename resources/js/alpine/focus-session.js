/**
 * Focus + Pomodoro session controller. Receives the Alpine list-item-card component (ctx)
 * and performs all focus/pomodoro actions. Keeps list-item-card.js thin.
 */

import { parseFocusStartedAt, formatFocusCountdown } from '../lib/focus-time.js';
import {
    getPomodoroSettingsPayload,
    predictNextPomodoroSessionInfo,
} from '../lib/pomodoro.js';

export function isTempSessionId(id) {
    return id != null && String(id).startsWith('temp-');
}

/** Compute remaining seconds without touching reactive state (for ticker interval). */
function getRemainingSecondsAt(ctx, nowMs) {
    if ((!ctx.isFocused && !ctx.isBreakFocused) || !ctx.activeFocusSession?.started_at || !ctx.activeFocusSession?.duration_seconds) return 0;
    const startedMs = parseFocusStartedAt(ctx.activeFocusSession.started_at);
    if (!Number.isFinite(startedMs)) return 0;
    const durationSec = Number(ctx.activeFocusSession.duration_seconds);
    const elapsedSec = Math.max(0, (nowMs - startedMs) / 1000);
    let pausedSec = ctx.focusPausedSecondsAccumulated;
    if (ctx.activeFocusSession.paused_at) {
        const pausedAtMs = parseFocusStartedAt(ctx.activeFocusSession.paused_at);
        if (Number.isFinite(pausedAtMs)) pausedSec += (nowMs - pausedAtMs) / 1000;
    } else if (pausedSec === 0 && ctx.activeFocusSession.paused_seconds != null && Number.isFinite(Number(ctx.activeFocusSession.paused_seconds))) {
        pausedSec = Math.max(0, Math.floor(Number(ctx.activeFocusSession.paused_seconds)));
    }
    if (ctx.focusIsPaused && ctx.focusPauseStartedAt) {
        pausedSec += (nowMs - ctx.focusPauseStartedAt) / 1000;
    }
    return Math.max(0, Math.floor(durationSec - elapsedSec + pausedSec));
}

const POMODORO_SAVE_DEBOUNCE_MS = 400;

/**
 * Single global Escape handler: stops focus on the card that currently has modal/session (O(1) via focusModal store).
 * Intentionally never removed; one handler for the app lifetime.
 */
function ensureGlobalEscapeHandler() {
    if (window.__focusEscapeHandlerRegistered) return;
    window.__focusEscapeHandlerRegistered = true;
    window.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        const openItemId = window.Alpine?.store?.('focusModal')?.openItemId;
        if (openItemId == null) return;
        const card = window.Alpine?.store?.('listItemCards')?.[openItemId];
        if (!card) return;
        const hasModalOrSession = card.focusReady || card.isFocused || card.isBreakFocused;
        if (!hasModalOrSession) return;
        e.preventDefault();
        if (card.focusReady) card.closeFocusModal?.();
        else card.stopFocus();
    });
}

export function createFocusSessionController() {
    return {
        init(ctx) {
            try {
                if (ctx.activeFocusSession) {
                    const isBreak = ctx.activeFocusSession.type === 'short_break' || ctx.activeFocusSession.type === 'long_break';
                    if (isBreak) {
                        ctx.isBreakSession = true;
                        const ps = ctx.activeFocusSession.paused_seconds;
                        if (ps != null && Number.isFinite(Number(ps))) {
                            ctx.focusPausedSecondsAccumulated = Math.max(0, Math.floor(Number(ps)));
                        }
                        if (ctx.activeFocusSession.paused_at) {
                            const pausedAtMs = parseFocusStartedAt(ctx.activeFocusSession.paused_at);
                            if (Number.isFinite(pausedAtMs)) {
                                ctx.focusIsPaused = true;
                                ctx.focusPauseStartedAt = pausedAtMs;
                            }
                        }
                    }
                    if (ctx.kind === 'task') {
                        const taskId = ctx.activeFocusSession.task_id;
                        if (taskId != null && Number(taskId) === Number(ctx.itemId)) {
                            const ps = ctx.activeFocusSession.paused_seconds;
                            if (ps != null && Number.isFinite(Number(ps))) {
                                ctx.focusPausedSecondsAccumulated = Math.max(0, Math.floor(Number(ps)));
                            }
                            if (ctx.activeFocusSession.paused_at) {
                                const pausedAtMs = parseFocusStartedAt(ctx.activeFocusSession.paused_at);
                                if (Number.isFinite(pausedAtMs)) {
                                    ctx.focusIsPaused = true;
                                    ctx.focusPauseStartedAt = pausedAtMs;
                                }
                            }
                        }
                    }
                }
            } catch (err) {
                console.error('[listItemCard] Failed to restore focus state:', err);
            }
            // focusSession.focusReady no longer set here; scroll lock uses focusModal.openItemId (see list-item-card watcher)
            if (ctx.kind === 'task') {
                ctx.$watch(
                    () => (ctx.isFocused || ctx.isBreakFocused) && ctx.activeFocusSession,
                    (shouldRun) => {
                        if (shouldRun) {
                            ctx.syncFocusTicker();
                        } else {
                            ctx.stopFocusTicker();
                            ctx.sessionComplete = false;
                        }
                    }
                );
                ctx._pomodoroLastSavedPayload = JSON.stringify(ctx.getPomodoroSettingsPayload());
            }
            if (ctx.kind === 'task') {
                ctx._onPomodoroStartNextWork = (event) => {
                    const { taskId, nextSessionInfo } = event.detail || {};
                    if (taskId && Number(taskId) === Number(ctx.itemId) && nextSessionInfo) {
                        ctx.startNextPomodoroWorkSession(nextSessionInfo, taskId);
                    }
                };
                window.addEventListener('pomodoro-start-next-work', ctx._onPomodoroStartNextWork);
            }
            const visibilityHandler = () => {
                if (document.visibilityState === 'visible') {
                    this.checkFocusCompletionOnVisible(ctx);
                }
            };
            document.addEventListener('visibilitychange', visibilityHandler);
            ctx._focusVisibilityListener = visibilityHandler;
        },

        checkFocusCompletionOnVisible(ctx) {
            if (!(ctx.isFocused || ctx.isBreakFocused) || !ctx.activeFocusSession || ctx.sessionComplete) return;
            ctx.focusTickerNow = Date.now();
            if (ctx.focusRemainingSeconds > 0) return;
            ctx.sessionComplete = true;
            const pausedSeconds = ctx.getFocusPausedSecondsTotal();
            const sessionId = ctx.activeFocusSession?.id;
            ctx.stopFocusTicker();
            if (sessionId != null && !isTempSessionId(sessionId)) {
                if (ctx.isPomodoroSession) {
                    ctx.completePomodoroSession(sessionId, pausedSeconds);
                } else {
                    ctx.playCompletionSound();
                    ctx.$wire.$parent.$call('completeFocusSession', sessionId, {
                        ended_at: new Date().toISOString(),
                        completed: true,
                        paused_seconds: pausedSeconds,
                        mark_task_status: 'done',
                    }).catch(() => {
                        ctx.$wire.$dispatch('toast', { type: 'error', message: ctx.focusCompleteErrorToast });
                    });
                }
            }
        },

        syncFocusTicker(ctx) {
            const shouldRun = (ctx.isFocused || ctx.isBreakFocused) && ctx.activeFocusSession;
            if (ctx._focusTickerShouldRun === shouldRun) return;
            ctx._focusTickerShouldRun = shouldRun;
            if (shouldRun) ctx.startFocusTicker();
            else {
                ctx.stopFocusTicker();
                ctx.sessionComplete = false;
            }
        },

        startFocusTicker(ctx) {
            if (ctx.focusIntervalId != null) return;
            if (ctx.sessionComplete || ctx.focusRemainingSeconds <= 0) return;
            ensureGlobalEscapeHandler();
            ctx.sessionComplete = false;
            ctx.focusTickerNow = Date.now();
            ctx._lastDisplayUpdate = 0;
            const initialRemaining = ctx.focusRemainingSeconds;
            const initialDuration = Number(ctx.activeFocusSession?.duration_seconds ?? 0);
            ctx.focusElapsedPercentValue = initialDuration > 0
                ? Math.min(100, Math.max(0, ((initialDuration - initialRemaining) / initialDuration) * 100))
                : 0;
            ctx.focusCountdownText = formatFocusCountdown(initialRemaining);
            ctx.focusProgressStyle = `width: ${ctx.focusElapsedPercentValue}%; min-width: ${ctx.focusElapsedPercentValue > 0 ? '2px' : '0'}`;
            const tickMs = 1000;
            ctx.focusIntervalId = setInterval(() => {
                if (ctx.focusIsPaused) return;
                const now = Date.now();
                const remaining = getRemainingSecondsAt(ctx, now);
                const duration = Number(ctx.activeFocusSession?.duration_seconds ?? 0);
                const pct = duration > 0
                    ? Math.min(100, Math.max(0, ((duration - remaining) / duration) * 100))
                    : 0;
                ctx._lastDisplayUpdate = now;
                ctx.focusTickerNow = now;
                requestAnimationFrame(() => {
                    ctx.focusElapsedPercentValue = pct;
                    ctx.focusCountdownText = formatFocusCountdown(remaining);
                    ctx.focusProgressStyle = `width: ${pct}%; min-width: ${pct > 0 ? '2px' : '0'}`;
                });
                if (remaining <= 0) {
                    ctx.sessionComplete = true;
                    const pausedSeconds = ctx.getFocusPausedSecondsTotal();
                    const sessionId = ctx.activeFocusSession?.id;
                    ctx.stopFocusTicker();
                    if (sessionId != null && !isTempSessionId(sessionId)) {
                        if (ctx.isPomodoroSession) {
                            ctx.completePomodoroSession(sessionId, pausedSeconds);
                        } else {
                            ctx.playCompletionSound();
                            ctx.$wire.$parent.$call('completeFocusSession', sessionId, {
                                ended_at: new Date().toISOString(),
                                completed: true,
                                paused_seconds: pausedSeconds,
                                mark_task_status: 'done',
                            }).catch(() => {
                                ctx.$wire.$dispatch('toast', { type: 'error', message: ctx.focusCompleteErrorToast });
                            });
                        }
                    }
                }
            }, tickMs);
        },

        stopFocusTicker(ctx) {
            if (ctx.focusIntervalId != null) {
                clearInterval(ctx.focusIntervalId);
                ctx.focusIntervalId = null;
            }
            if (ctx._completedDismissTimeoutId != null) {
                clearTimeout(ctx._completedDismissTimeoutId);
                ctx._completedDismissTimeoutId = null;
            }
            ctx.focusIsPaused = false;
            ctx.focusPauseStartedAt = null;
            ctx.focusPausedSecondsAccumulated = 0;
            ctx.focusCountdownText = '';
            ctx.focusProgressStyle = 'width: 0%; min-width: 0';
        },

        dismissCompletedFocus(ctx) {
            if (ctx._completedDismissTimeoutId != null) {
                clearTimeout(ctx._completedDismissTimeoutId);
                ctx._completedDismissTimeoutId = null;
            }
            if (ctx._pomodoroAutoStartTimeoutId != null) {
                clearTimeout(ctx._pomodoroAutoStartTimeoutId);
                ctx._pomodoroAutoStartTimeoutId = null;
            }
            ctx._pomodoroAutoStartTransitioned = false;
            ctx._pomodoroAutoStartOptimisticStartedAt = null;
            ctx.activeFocusSession = null;
            ctx.nextSessionInfo = null;
            ctx.isBreakSession = false;
            ctx.pomodoroSequence = 1;
            ctx.lastPomodoroTaskId = null;
            ctx.pomodoroWorkCount = 0;
            ctx.dispatchFocusSessionUpdated(null);
            ctx.sessionComplete = false;
            ctx.focusReady = false;
            ctx.restoreFocusAfterModalClose?.();
        },

        optimisticallyStartNextPomodoroSession(ctx, nextSessionInfo, startedAt) {
            if (!nextSessionInfo) return;
            const isBreak = nextSessionInfo.type === 'short_break' || nextSessionInfo.type === 'long_break';
            if (isBreak) {
                const optimisticSession = {
                    id: 'temp-' + Date.now(),
                    task_id: null,
                    owner_task_id: ctx.lastPomodoroTaskId || ctx.itemId,
                    started_at: startedAt,
                    duration_seconds: nextSessionInfo.duration_seconds,
                    type: nextSessionInfo.type,
                    sequence_number: nextSessionInfo.sequence_number,
                    payload: { focus_mode_type: 'pomodoro' },
                };
                ctx.activeFocusSession = optimisticSession;
                ctx.isBreakSession = true;
                ctx.nextSessionInfo = null;
                ctx.sessionComplete = false;
                ctx.pomodoroSequence = nextSessionInfo.sequence_number;
                ctx.dispatchFocusSessionUpdated(optimisticSession);
                return;
            }
            const taskId = ctx.lastPomodoroTaskId || ctx.itemId;
            if (!(taskId && ctx.kind === 'task' && Number(taskId) === Number(ctx.itemId))) return;
            const optimisticSession = {
                id: 'temp-' + Date.now(),
                task_id: taskId,
                started_at: startedAt,
                duration_seconds: nextSessionInfo.duration_seconds,
                type: 'work',
                sequence_number: nextSessionInfo.sequence_number,
                focus_mode_type: 'pomodoro',
                payload: { focus_mode_type: 'pomodoro' },
            };
            ctx.activeFocusSession = optimisticSession;
            ctx.isBreakSession = false;
            ctx.nextSessionInfo = null;
            ctx.sessionComplete = false;
            ctx.lastPomodoroTaskId = taskId;
            ctx.pomodoroSequence = nextSessionInfo.sequence_number;
            ctx.dispatchFocusSessionUpdated(optimisticSession);
        },

        async completePomodoroSession(ctx, sessionId, pausedSeconds) {
            if (ctx.completingPomodoro) return;
            ctx.completingPomodoro = true;
            const snapshot = {
                activeFocusSession: ctx.activeFocusSession ? { ...ctx.activeFocusSession } : null,
                isBreakSession: ctx.isBreakSession,
                nextSessionInfo: ctx.nextSessionInfo ? { ...ctx.nextSessionInfo } : null,
                sessionComplete: ctx.sessionComplete,
                pomodoroSequence: ctx.pomodoroSequence,
                _pomodoroAutoStartTransitioned: ctx._pomodoroAutoStartTransitioned,
                _pomodoroAutoStartOptimisticStartedAt: ctx._pomodoroAutoStartOptimisticStartedAt,
            };
            const rollbackAfterError = (message) => {
                if (ctx._pomodoroAutoStartTimeoutId != null) {
                    clearTimeout(ctx._pomodoroAutoStartTimeoutId);
                    ctx._pomodoroAutoStartTimeoutId = null;
                }
                ctx._pomodoroAutoStartTransitioned = snapshot._pomodoroAutoStartTransitioned;
                ctx._pomodoroAutoStartOptimisticStartedAt = snapshot._pomodoroAutoStartOptimisticStartedAt;
                ctx.activeFocusSession = snapshot.activeFocusSession;
                ctx.isBreakSession = snapshot.isBreakSession;
                ctx.nextSessionInfo = snapshot.nextSessionInfo;
                ctx.sessionComplete = snapshot.sessionComplete;
                ctx.pomodoroSequence = snapshot.pomodoroSequence;
                ctx.dispatchFocusSessionUpdated(snapshot.activeFocusSession ?? null);
                ctx.$wire.$dispatch('toast', { type: 'error', message: message || ctx.focusCompleteErrorToast });
            };
            try {
                const predictedNextSession = ctx.predictNextPomodoroSessionInfo();
                if (predictedNextSession) {
                    if (predictedNextSession.auto_start) {
                        ctx.nextSessionInfo = predictedNextSession;
                        ctx._pomodoroAutoStartTransitioned = false;
                        ctx._pomodoroAutoStartOptimisticStartedAt = null;
                        if (ctx._pomodoroAutoStartTimeoutId != null) clearTimeout(ctx._pomodoroAutoStartTimeoutId);
                        ctx._pomodoroAutoStartTimeoutId = setTimeout(() => {
                            ctx._pomodoroAutoStartTimeoutId = null;
                            ctx._pomodoroAutoStartTransitioned = true;
                            const startedAt = new Date().toISOString();
                            ctx._pomodoroAutoStartOptimisticStartedAt = startedAt;
                            ctx.optimisticallyStartNextPomodoroSession(predictedNextSession, startedAt);
                        }, 1500);
                    } else {
                        ctx.nextSessionInfo = predictedNextSession;
                    }
                }
                const result = await ctx.$wire.$parent.$call('completePomodoroSession', sessionId, {
                    ended_at: new Date().toISOString(),
                    completed: true,
                    paused_seconds: pausedSeconds,
                    mark_task_status: ctx.activeFocusSession?.type === 'work' ? 'done' : null,
                });
                if (result && result.error) {
                    rollbackAfterError(result.error);
                    return;
                }
                ctx.playCompletionSound();
                if (result && result.next_session) {
                    if (!ctx._pomodoroAutoStartTransitioned) ctx.nextSessionInfo = result.next_session;
                    if (result.next_session.sequence_number) ctx.pomodoroSequence = result.next_session.sequence_number;
                    if (result.next_session.auto_start) {
                        if (ctx._pomodoroAutoStartTransitioned) {
                            const startedAt = ctx._pomodoroAutoStartOptimisticStartedAt || ctx.activeFocusSession?.started_at || new Date().toISOString();
                            const isBreak = result.next_session.type === 'short_break' || result.next_session.type === 'long_break';
                            if (isBreak) ctx.startBreakSession(result.next_session, startedAt);
                            else ctx.startNextPomodoroWorkSession(result.next_session, ctx.lastPomodoroTaskId || ctx.itemId, startedAt);
                        } else {
                            setTimeout(() => ctx.startNextSession(result.next_session), 1500);
                        }
                    }
                } else {
                    ctx.nextSessionInfo = predictedNextSession ?? null;
                    ctx.sessionComplete = true;
                }
            } catch (error) {
                console.error('[listItemCard] Failed to complete pomodoro session:', error);
                rollbackAfterError(error?.message);
            } finally {
                ctx.completingPomodoro = false;
            }
        },

        playCompletionSound(ctx) {
            if (!ctx.pomodoroSoundEnabled) return;
            try {
                const AudioCtx = window.AudioContext || window.webkitAudioContext;
                if (!AudioCtx) return;
                if (!ctx._audioContext) {
                    ctx._audioContext = new AudioCtx();
                    ctx._audioGainNode = ctx._audioContext.createGain();
                    ctx._audioGainNode.connect(ctx._audioContext.destination);
                }
                const play = () => {
                    const oscillator = ctx._audioContext.createOscillator();
                    oscillator.connect(ctx._audioGainNode);
                    oscillator.frequency.value = 800;
                    oscillator.type = 'sine';
                    const volume = Math.max(0, Math.min(1, (ctx.pomodoroSoundVolume ?? 80) / 100));
                    const now = ctx._audioContext.currentTime;
                    ctx._audioGainNode.gain.setValueAtTime(volume, now);
                    ctx._audioGainNode.gain.exponentialRampToValueAtTime(0.01, now + 0.3);
                    oscillator.start(now);
                    oscillator.stop(now + 0.3);
                };
                if (ctx._audioContext.state === 'suspended') {
                    ctx._audioContext.resume().then(play).catch((err) => {
                        console.warn('[listItemCard] Failed to resume AudioContext for completion sound:', err);
                    });
                } else {
                    play();
                }
            } catch (error) {
                console.warn('[listItemCard] Failed to play completion sound:', error);
            }
        },

        async startNextSession(ctx, nextSessionInfo) {
            if (!nextSessionInfo || ctx.startingNextSessionInProgress) return;
            ctx.startingNextSessionInProgress = true;
            try {
                const isBreak = nextSessionInfo.type === 'short_break' || nextSessionInfo.type === 'long_break';
                if (isBreak) {
                    await ctx.startBreakSession(nextSessionInfo);
                } else {
                    const taskId = ctx.lastPomodoroTaskId || ctx.itemId;
                    if (ctx.isBreakFocused && ctx.lastPomodoroTaskId && ctx.kind === 'task' && Number(ctx.lastPomodoroTaskId) === Number(ctx.itemId)) {
                        await ctx.startNextPomodoroWorkSession(nextSessionInfo, ctx.lastPomodoroTaskId);
                    } else if (ctx.isBreakFocused && ctx.lastPomodoroTaskId) {
                        window.dispatchEvent(new CustomEvent('pomodoro-start-next-work', {
                            detail: { taskId: ctx.lastPomodoroTaskId, nextSessionInfo },
                            bubbles: true,
                        }));
                        ctx.nextSessionInfo = null;
                        ctx.sessionComplete = false;
                        ctx.activeFocusSession = null;
                        ctx.isBreakSession = false;
                        ctx.dispatchFocusSessionUpdated(null);
                    } else if (taskId && ctx.kind === 'task' && Number(taskId) === Number(ctx.itemId)) {
                        await ctx.startNextPomodoroWorkSession(nextSessionInfo, taskId);
                    } else {
                        ctx.nextSessionInfo = null;
                        ctx.sessionComplete = false;
                        ctx.$wire.$dispatch('toast', { type: 'info', message: 'Please select a task to start the next pomodoro.' });
                    }
                }
            } finally {
                ctx.startingNextSessionInProgress = false;
            }
        },

        async startNextPomodoroWorkSession(ctx, nextSessionInfo, taskId, startedAtArg) {
            const snapshot = {
                activeFocusSession: ctx.activeFocusSession ? { ...ctx.activeFocusSession } : null,
                isBreakSession: ctx.isBreakSession,
                nextSessionInfo: ctx.nextSessionInfo ? { ...ctx.nextSessionInfo } : null,
                sessionComplete: ctx.sessionComplete,
                pomodoroSequence: ctx.pomodoroSequence,
            };
            try {
                ctx.pomodoroWorkCount = (ctx.pomodoroWorkCount || 0) + 1;
                const startedAt = startedAtArg ?? new Date().toISOString();
                const payload = {
                    type: 'work',
                    duration_seconds: nextSessionInfo.duration_seconds,
                    started_at: startedAt,
                    sequence_number: nextSessionInfo.sequence_number,
                    payload: { focus_mode_type: 'pomodoro', used_default_duration: true },
                };
                const optimisticSession = {
                    id: 'temp-' + Date.now(),
                    task_id: taskId,
                    started_at: startedAt,
                    duration_seconds: nextSessionInfo.duration_seconds,
                    type: 'work',
                    sequence_number: nextSessionInfo.sequence_number,
                    focus_mode_type: 'pomodoro',
                    payload: { focus_mode_type: 'pomodoro' },
                };
                const promise = ctx.$wire.$parent.$call('startFocusSession', taskId, payload);
                ctx.activeFocusSession = optimisticSession;
                ctx.isBreakSession = false;
                ctx.nextSessionInfo = null;
                ctx.sessionComplete = false;
                ctx.lastPomodoroTaskId = taskId;
                ctx.pomodoroSequence = nextSessionInfo.sequence_number;
                ctx.dispatchFocusSessionUpdated(optimisticSession);
                const result = await promise;
                if (result && result.error) {
                    ctx.activeFocusSession = snapshot.activeFocusSession;
                    ctx.isBreakSession = snapshot.isBreakSession;
                    ctx.nextSessionInfo = snapshot.nextSessionInfo;
                    ctx.sessionComplete = snapshot.sessionComplete;
                    ctx.pomodoroSequence = snapshot.pomodoroSequence;
                    ctx.dispatchFocusSessionUpdated(snapshot.activeFocusSession ?? null);
                    ctx.$wire.$dispatch('toast', { type: 'error', message: result.error });
                    return;
                }
                const merged = {
                    ...result,
                    started_at: ctx.activeFocusSession?.started_at || result.started_at,
                    focus_mode_type: 'pomodoro',
                    payload: { focus_mode_type: 'pomodoro' },
                };
                ctx.activeFocusSession = merged;
                ctx.lastPomodoroTaskId = taskId;
                ctx.pomodoroSequence = nextSessionInfo.sequence_number;
                ctx.dispatchFocusSessionUpdated(merged);
            } catch (error) {
                console.error('[listItemCard] Failed to start next pomodoro work session:', error);
                ctx.activeFocusSession = snapshot.activeFocusSession;
                ctx.isBreakSession = snapshot.isBreakSession;
                ctx.nextSessionInfo = snapshot.nextSessionInfo;
                ctx.sessionComplete = snapshot.sessionComplete;
                ctx.pomodoroSequence = snapshot.pomodoroSequence;
                ctx.dispatchFocusSessionUpdated(snapshot.activeFocusSession ?? null);
                ctx.$wire.$dispatch('toast', { type: 'error', message: 'Failed to start next pomodoro.' });
            }
        },

        async startBreakSession(ctx, nextSessionInfo, startedAtArg) {
            const snapshot = {
                activeFocusSession: ctx.activeFocusSession ? { ...ctx.activeFocusSession } : null,
                isBreakSession: ctx.isBreakSession,
                nextSessionInfo: ctx.nextSessionInfo ? { ...ctx.nextSessionInfo } : null,
                sessionComplete: ctx.sessionComplete,
                pomodoroSequence: ctx.pomodoroSequence,
            };
            try {
                const startedAt = startedAtArg ?? new Date().toISOString();
                const taskId = ctx.lastPomodoroTaskId ?? (ctx.kind === 'task' ? ctx.itemId : null);
                const payload = {
                    type: nextSessionInfo.type,
                    duration_seconds: nextSessionInfo.duration_seconds,
                    started_at: startedAt,
                    sequence_number: nextSessionInfo.sequence_number,
                    payload: { focus_mode_type: 'pomodoro' },
                    ...(taskId != null ? { task_id: taskId } : {}),
                };
                const optimisticSession = {
                    id: 'temp-' + Date.now(),
                    task_id: null,
                    owner_task_id: ctx.lastPomodoroTaskId || ctx.itemId,
                    started_at: startedAt,
                    duration_seconds: nextSessionInfo.duration_seconds,
                    type: nextSessionInfo.type,
                    sequence_number: nextSessionInfo.sequence_number,
                    payload: { focus_mode_type: 'pomodoro' },
                };
                const promise = ctx.$wire.$parent.$call('startBreakSession', payload);
                ctx.activeFocusSession = optimisticSession;
                ctx.isBreakSession = true;
                ctx.nextSessionInfo = null;
                ctx.sessionComplete = false;
                ctx.pomodoroSequence = nextSessionInfo.sequence_number;
                ctx.dispatchFocusSessionUpdated(optimisticSession);
                const result = await promise;
                if (result && result.error) {
                    ctx.activeFocusSession = snapshot.activeFocusSession;
                    ctx.isBreakSession = snapshot.isBreakSession;
                    ctx.nextSessionInfo = snapshot.nextSessionInfo;
                    ctx.sessionComplete = snapshot.sessionComplete;
                    ctx.pomodoroSequence = snapshot.pomodoroSequence;
                    ctx.dispatchFocusSessionUpdated(snapshot.activeFocusSession ?? null);
                    ctx.$wire.$dispatch('toast', { type: 'error', message: result.error });
                    return;
                }
                const merged = {
                    ...result,
                    started_at: ctx.activeFocusSession?.started_at || result.started_at,
                    owner_task_id: ctx.activeFocusSession?.owner_task_id ?? (ctx.lastPomodoroTaskId || ctx.itemId),
                    payload: { focus_mode_type: 'pomodoro' },
                };
                ctx.activeFocusSession = merged;
                ctx.dispatchFocusSessionUpdated(merged);
            } catch (error) {
                console.error('[listItemCard] Failed to start break session:', error);
                ctx.activeFocusSession = snapshot.activeFocusSession;
                ctx.isBreakSession = snapshot.isBreakSession;
                ctx.nextSessionInfo = snapshot.nextSessionInfo;
                ctx.sessionComplete = snapshot.sessionComplete;
                ctx.pomodoroSequence = snapshot.pomodoroSequence;
                ctx.dispatchFocusSessionUpdated(snapshot.activeFocusSession ?? null);
                ctx.$wire.$dispatch('toast', { type: 'error', message: 'Failed to start break session.' });
            }
        },

        savePomodoroSettings(ctx) {
            if (ctx.kind !== 'task' || !ctx.$wire?.$parent?.$call) return;
            if (ctx._savePomodoroSettingsTimeout) clearTimeout(ctx._savePomodoroSettingsTimeout);
            ctx._savePomodoroSettingsTimeout = setTimeout(() => {
                ctx._savePomodoroSettingsTimeout = null;
                this._savePomodoroSettingsNow(ctx);
            }, POMODORO_SAVE_DEBOUNCE_MS);
        },

        async _savePomodoroSettingsNow(ctx) {
            if (ctx.kind !== 'task' || !ctx.$wire?.$parent?.$call) return;
            const settingsSnapshot = {
                pomodoroWorkMinutes: ctx.pomodoroWorkMinutes,
                pomodoroShortBreakMinutes: ctx.pomodoroShortBreakMinutes,
                pomodoroLongBreakMinutes: ctx.pomodoroLongBreakMinutes,
                pomodoroLongBreakAfter: ctx.pomodoroLongBreakAfter,
                pomodoroAutoStartBreak: ctx.pomodoroAutoStartBreak,
                pomodoroAutoStartPomodoro: ctx.pomodoroAutoStartPomodoro,
                pomodoroSoundEnabled: ctx.pomodoroSoundEnabled,
                pomodoroSoundVolume: ctx.pomodoroSoundVolume,
            };
            const payload = getPomodoroSettingsPayload({
                pomodoroWorkMinutes: ctx.pomodoroWorkMinutes,
                pomodoroShortBreakMinutes: ctx.pomodoroShortBreakMinutes,
                pomodoroLongBreakMinutes: ctx.pomodoroLongBreakMinutes,
                pomodoroLongBreakAfter: ctx.pomodoroLongBreakAfter,
                pomodoroSoundVolume: ctx.pomodoroSoundVolume,
                pomodoroAutoStartBreak: ctx.pomodoroAutoStartBreak,
                pomodoroAutoStartPomodoro: ctx.pomodoroAutoStartPomodoro,
                pomodoroSoundEnabled: ctx.pomodoroSoundEnabled,
            });
            ctx.pomodoroWorkMinutes = payload.work_duration_minutes;
            ctx.pomodoroShortBreakMinutes = payload.short_break_minutes;
            ctx.pomodoroLongBreakMinutes = payload.long_break_minutes;
            ctx.pomodoroLongBreakAfter = payload.long_break_after_pomodoros;
            ctx.pomodoroSoundVolume = payload.sound_volume;
            const currentJson = JSON.stringify(payload);
            if (currentJson === ctx._pomodoroLastSavedPayload) return;
            const parent = ctx.$wire.$parent;
            try {
                const ok = await parent.$call('updatePomodoroSettings', payload);
                if (ok === true) ctx._pomodoroLastSavedPayload = currentJson;
                if (ok === false) {
                    const fresh = await parent.$call('getPomodoroSettings');
                    ctx.applyPomodoroSettings(fresh ?? {});
                }
            } catch (err) {
                ctx.pomodoroWorkMinutes = settingsSnapshot.pomodoroWorkMinutes;
                ctx.pomodoroShortBreakMinutes = settingsSnapshot.pomodoroShortBreakMinutes;
                ctx.pomodoroLongBreakMinutes = settingsSnapshot.pomodoroLongBreakMinutes;
                ctx.pomodoroLongBreakAfter = settingsSnapshot.pomodoroLongBreakAfter;
                ctx.pomodoroAutoStartBreak = settingsSnapshot.pomodoroAutoStartBreak;
                ctx.pomodoroAutoStartPomodoro = settingsSnapshot.pomodoroAutoStartPomodoro;
                ctx.pomodoroSoundEnabled = settingsSnapshot.pomodoroSoundEnabled;
                ctx.pomodoroSoundVolume = settingsSnapshot.pomodoroSoundVolume;
                ctx.$wire.$dispatch('toast', {
                    type: 'error',
                    message: err?.message ?? ctx.pomodoroSettingsSaveErrorToast ?? 'Could not save Pomodoro settings.',
                });
            }
        },

        enterFocusReady(ctx) {
            if (ctx.kind !== 'task' || !ctx.canEdit || ctx.isFocused) return;
            ctx.focusReady = true;
        },

        async startFocusFromReady(ctx) {
            try {
                await ctx.startFocusMode();
            } finally {
                ctx.focusReady = false;
            }
        },

        async markTaskDoneFromFocus(ctx) {
            if (ctx.kind !== 'task') return;
            const previousStatus = ctx.taskStatus;
            const activeFocusSessionSnapshot = ctx.activeFocusSession ? { ...ctx.activeFocusSession } : null;
            const sessionCompleteSnapshot = ctx.sessionComplete;
            const focusReadySnapshot = ctx.focusReady;
            const occurrenceDate = ctx.activeFocusSession?.payload?.occurrence_date
                ?? (ctx.isRecurringTask && ctx.listFilterDate ? String(ctx.listFilterDate).slice(0, 10) : null);
            try {
                ctx.taskStatus = 'done';
                ctx.dismissCompletedFocus();
                window.dispatchEvent(new CustomEvent('task-status-updated', { detail: { itemId: ctx.itemId, status: 'done' }, bubbles: true }));
                const ok = await ctx.$wire.$parent.$call(ctx.updatePropertyMethod, ctx.itemId, 'status', 'done', false, occurrenceDate);
                if (ok === false) {
                    ctx.taskStatus = previousStatus;
                    ctx.activeFocusSession = activeFocusSessionSnapshot;
                    ctx.sessionComplete = sessionCompleteSnapshot;
                    ctx.focusReady = focusReadySnapshot;
                    if (activeFocusSessionSnapshot) ctx.dispatchFocusSessionUpdated(activeFocusSessionSnapshot);
                    window.dispatchEvent(new CustomEvent('task-status-updated', { detail: { itemId: ctx.itemId, status: previousStatus }, bubbles: true }));
                    ctx.$wire.$dispatch('toast', { type: 'error', message: ctx.focusMarkDoneErrorToast });
                }
            } catch (err) {
                ctx.taskStatus = previousStatus;
                ctx.activeFocusSession = activeFocusSessionSnapshot;
                ctx.sessionComplete = sessionCompleteSnapshot;
                ctx.focusReady = focusReadySnapshot;
                if (activeFocusSessionSnapshot) ctx.dispatchFocusSessionUpdated(activeFocusSessionSnapshot);
                window.dispatchEvent(new CustomEvent('task-status-updated', { detail: { itemId: ctx.itemId, status: previousStatus }, bubbles: true }));
                ctx.$wire.$dispatch('toast', { type: 'error', message: err.message ?? ctx.focusMarkDoneErrorToast });
            }
        },

        async pauseFocus(ctx) {
            if (!ctx.isFocused || ctx.focusIsPaused) return;
            const sessionId = ctx.activeFocusSession?.id;
            const snapshot = {
                focusIsPaused: ctx.focusIsPaused,
                focusPauseStartedAt: ctx.focusPauseStartedAt,
                focusPausedSecondsAccumulated: ctx.focusPausedSecondsAccumulated,
                activeFocusSession: ctx.activeFocusSession ? { ...ctx.activeFocusSession } : null,
            };
            try {
                ctx.focusIsPaused = true;
                ctx.focusPauseStartedAt = Date.now();
                ctx.focusTickerNow = Date.now();
                const remaining = ctx.focusRemainingSeconds;
                ctx.focusElapsedPercentValue = ctx.focusElapsedPercent;
                ctx.focusCountdownText = formatFocusCountdown(remaining);
                const pct = ctx.focusElapsedPercentValue;
                ctx.focusProgressStyle = `width: ${pct}%; min-width: ${pct > 0 ? '2px' : '0'}`;
                if (sessionId != null && !isTempSessionId(sessionId)) {
                    const ok = await ctx.$wire.$parent.$call('pauseFocusSession', sessionId);
                    if (ok === false) {
                        ctx.focusIsPaused = snapshot.focusIsPaused;
                        ctx.focusPauseStartedAt = snapshot.focusPauseStartedAt;
                        ctx.activeFocusSession = snapshot.activeFocusSession;
                        ctx.dispatchFocusSessionUpdated(snapshot.activeFocusSession);
                        ctx.$wire.$dispatch('toast', { type: 'error', message: ctx.focusSessionNoLongerActiveToast });
                    }
                }
            } catch (err) {
                ctx.focusIsPaused = snapshot.focusIsPaused;
                ctx.focusPauseStartedAt = snapshot.focusPauseStartedAt;
                ctx.focusPausedSecondsAccumulated = snapshot.focusPausedSecondsAccumulated;
                ctx.$wire.$dispatch('toast', { type: 'error', message: ctx.focusStopErrorToast });
            }
        },

        async resumeFocus(ctx) {
            if ((!ctx.isFocused && !ctx.isBreakFocused) || !ctx.focusIsPaused || !ctx.focusPauseStartedAt) return;
            const sessionId = ctx.activeFocusSession?.id;
            const segmentSec = (Date.now() - ctx.focusPauseStartedAt) / 1000;
            const snapshot = {
                focusIsPaused: ctx.focusIsPaused,
                focusPauseStartedAt: ctx.focusPauseStartedAt,
                focusPausedSecondsAccumulated: ctx.focusPausedSecondsAccumulated,
                activeFocusSession: ctx.activeFocusSession ? { ...ctx.activeFocusSession } : null,
            };
            try {
                ctx.focusPausedSecondsAccumulated += segmentSec;
                ctx.focusPauseStartedAt = null;
                ctx.focusIsPaused = false;
                ctx.focusTickerNow = Date.now();
                const remaining = ctx.focusRemainingSeconds;
                ctx.focusElapsedPercentValue = ctx.focusElapsedPercent;
                ctx.focusCountdownText = formatFocusCountdown(remaining);
                const pct = ctx.focusElapsedPercentValue;
                ctx.focusProgressStyle = `width: ${pct}%; min-width: ${pct > 0 ? '2px' : '0'}`;
                ctx._focusJustResumed = true;
                if (sessionId != null && !isTempSessionId(sessionId)) {
                    const ok = await ctx.$wire.$parent.$call('resumeFocusSession', sessionId);
                    if (ok === false) {
                        ctx._focusJustResumed = false;
                        ctx.focusPausedSecondsAccumulated = snapshot.focusPausedSecondsAccumulated;
                        ctx.focusIsPaused = snapshot.focusIsPaused;
                        ctx.focusPauseStartedAt = snapshot.focusPauseStartedAt;
                        ctx.activeFocusSession = snapshot.activeFocusSession;
                        ctx.dispatchFocusSessionUpdated(snapshot.activeFocusSession);
                        ctx.$wire.$dispatch('toast', { type: 'error', message: ctx.focusSessionNoLongerActiveToast });
                    }
                }
            } catch (err) {
                ctx._focusJustResumed = false;
                ctx.focusPausedSecondsAccumulated = snapshot.focusPausedSecondsAccumulated;
                ctx.focusIsPaused = snapshot.focusIsPaused;
                ctx.focusPauseStartedAt = snapshot.focusPauseStartedAt;
                ctx.$wire.$dispatch('toast', { type: 'error', message: ctx.focusStopErrorToast });
            }
        },

        getFocusPausedSecondsTotal(ctx) {
            let total = ctx.focusPausedSecondsAccumulated;
            if (ctx.focusIsPaused && ctx.focusPauseStartedAt) {
                total += (Date.now() - ctx.focusPauseStartedAt) / 1000;
            }
            return Math.round(total);
        },

        dispatchFocusSessionUpdated(ctx, session) {
            window.dispatchEvent(new CustomEvent('focus-session-updated', { detail: { session: session ?? null }, bubbles: true, composed: true }));
        },

        async startFocusMode(ctx) {
            if (ctx.kind !== 'task' || !ctx.canEdit || ctx.isFocused) return;
            const previousTaskStatus = ctx.taskStatus;
            const types = ctx.focusModeTypes ?? [];
            const selected = types.find((t) => t.value === ctx.focusModeType);
            if (selected && !selected.available) {
                ctx.$wire.$dispatch('toast', {
                    type: 'info',
                    message: typeof ctx.focusModeComingSoonToast === 'string' ? ctx.focusModeComingSoonToast : 'Coming soon.',
                });
                return;
            }
            const isPomodoro = ctx.focusModeType === 'pomodoro';
            if (isPomodoro) ctx.pomodoroWorkCount = 1;
            const baseSequence = Number.isFinite(Number(ctx.pomodoroSequence)) && Number(ctx.pomodoroSequence) > 0 ? Number(ctx.pomodoroSequence) : 1;
            const minutes = isPomodoro
                ? Math.max(1, Math.min(120, Math.floor(Number(ctx.pomodoroWorkMinutes ?? 25))))
                : (ctx.taskDurationMinutes != null && ctx.taskDurationMinutes > 0 ? Number(ctx.taskDurationMinutes) : ctx.defaultWorkDurationMinutes);
            const durationSeconds = Math.max(60, minutes * 60);
            const startedAt = new Date().toISOString();
            const sequenceNumber = isPomodoro ? baseSequence : 1;
            const payload = {
                type: 'work',
                duration_seconds: durationSeconds,
                started_at: startedAt,
                sequence_number: sequenceNumber,
                payload: {
                    used_task_duration: !isPomodoro && !!(ctx.taskDurationMinutes != null && ctx.taskDurationMinutes > 0),
                    focus_mode_type: ctx.focusModeType ?? 'countdown',
                },
            };
            if (ctx.isRecurringTask && ctx.listFilterDate) payload.occurrence_date = String(ctx.listFilterDate).slice(0, 10);
            const optimisticSession = {
                id: 'temp-' + Date.now(),
                task_id: ctx.itemId,
                started_at: startedAt,
                duration_seconds: durationSeconds,
                type: 'work',
                sequence_number: sequenceNumber,
                focus_mode_type: ctx.focusModeType ?? 'countdown',
                payload: { focus_mode_type: ctx.focusModeType ?? 'countdown' },
            };
            if (isPomodoro) {
                ctx.lastPomodoroTaskId = ctx.itemId;
                ctx.pomodoroSequence = sequenceNumber;
            }
            const promise = ctx.$wire.$parent.$call('startFocusSession', ctx.itemId, payload);
            ctx.pendingStartPromise = promise;
            try {
                if (ctx.kind === 'task' && ctx.taskStatus === 'to_do') {
                    ctx.taskStatus = 'doing';
                    window.dispatchEvent(new CustomEvent('task-status-updated', { detail: { itemId: ctx.itemId, status: 'doing' }, bubbles: true }));
                }
                ctx.activeFocusSession = optimisticSession;
                ctx.dispatchFocusSessionUpdated(optimisticSession);
                const result = await promise;
                ctx.pendingStartPromise = null;
                if (ctx.focusStopRequestedBeforeStartResolved) {
                    ctx.focusStopRequestedBeforeStartResolved = false;
                    if (result && !result.error && result.id) {
                        try {
                            await ctx.$wire.$parent.$call('abandonFocusSession', result.id);
                        } catch (_) {
                            ctx.$wire.$dispatch('toast', { type: 'error', message: ctx.focusStopErrorToast });
                        }
                    }
                    return;
                }
                if (result && result.error) {
                    if (ctx.kind === 'task' && ctx.taskStatus !== previousTaskStatus) {
                        ctx.taskStatus = previousTaskStatus;
                        window.dispatchEvent(new CustomEvent('task-status-updated', { detail: { itemId: ctx.itemId, status: previousTaskStatus }, bubbles: true }));
                    }
                    ctx.activeFocusSession = null;
                    ctx.dispatchFocusSessionUpdated(null);
                    ctx.$wire.$dispatch('toast', { type: 'error', message: (typeof result.error === 'string' ? result.error : null) || ctx.focusStartErrorToast });
                    return;
                }
                const merged = {
                    ...result,
                    started_at: ctx.activeFocusSession?.started_at || result.started_at,
                    focus_mode_type: ctx.activeFocusSession?.focus_mode_type ?? result.focus_mode_type ?? 'countdown',
                    payload: ctx.activeFocusSession?.payload ?? result.payload ?? {},
                };
                ctx.activeFocusSession = merged;
                if (ctx.isPomodoroSession && result.sequence_number) {
                    ctx.pomodoroSequence = result.sequence_number;
                    ctx.lastPomodoroTaskId = ctx.itemId;
                }
                ctx.dispatchFocusSessionUpdated(merged);
                if (ctx.focusIsPaused && result.id) {
                    try {
                        await ctx.$wire.$parent.$call('pauseFocusSession', result.id);
                    } catch (_) {
                        ctx.focusIsPaused = false;
                        ctx.focusPauseStartedAt = null;
                    }
                }
            } catch (error) {
                ctx.pendingStartPromise = null;
                ctx.focusStopRequestedBeforeStartResolved = false;
                ctx.activeFocusSession = null;
                ctx.dispatchFocusSessionUpdated(null);
                if (ctx.kind === 'task' && ctx.taskStatus !== previousTaskStatus) {
                    ctx.taskStatus = previousTaskStatus;
                    window.dispatchEvent(new CustomEvent('task-status-updated', { detail: { itemId: ctx.itemId, status: previousTaskStatus }, bubbles: true }));
                }
                ctx.$wire.$dispatch('toast', { type: 'error', message: error.message || ctx.focusStartErrorToast });
            }
        },

        async stopFocus(ctx) {
            if (!ctx.activeFocusSession || !ctx.activeFocusSession.id) return;
            const sessionSnapshot = { ...ctx.activeFocusSession };
            const focusReadySnapshot = ctx.focusReady;
            const isBreak = ctx.isBreakFocused;
            const wasPomodoro = ctx.isPomodoroSession;
            if (isTempSessionId(sessionSnapshot.id)) {
                ctx.focusStopRequestedBeforeStartResolved = true;
                ctx.activeFocusSession = null;
                ctx.isBreakSession = false;
                ctx.nextSessionInfo = null;
                ctx.dispatchFocusSessionUpdated(null);
                ctx.focusReady = true;
                if (wasPomodoro) {
                    ctx.pomodoroSequence = 1;
                    ctx.lastPomodoroTaskId = null;
                    ctx.pomodoroWorkCount = 0;
                }
                return;
            }
            const pausedSeconds = ctx.getFocusPausedSecondsTotal();
            try {
                ctx.activeFocusSession = null;
                ctx.isBreakSession = false;
                ctx.nextSessionInfo = null;
                ctx.dispatchFocusSessionUpdated(null);
                ctx.focusReady = true;
                await ctx.$wire.$parent.$call('abandonFocusSession', sessionSnapshot.id, { paused_seconds: pausedSeconds });
                if (wasPomodoro) {
                    ctx.pomodoroSequence = 1;
                    ctx.lastPomodoroTaskId = null;
                    ctx.pomodoroWorkCount = 0;
                }
            } catch (error) {
                ctx.activeFocusSession = sessionSnapshot;
                ctx.isBreakSession = isBreak;
                ctx.dispatchFocusSessionUpdated(sessionSnapshot);
                ctx.focusReady = focusReadySnapshot;
                ctx.$wire.$dispatch('toast', { type: 'error', message: error.message || ctx.focusStopErrorToast });
            }
        },

        onFocusSessionUpdated(ctx, incoming) {
            if (!incoming) {
                ctx.activeFocusSession = null;
                ctx.isBreakSession = false;
                ctx.pomodoroSequence = 1;
                ctx.lastPomodoroTaskId = null;
                ctx.pomodoroWorkCount = 0;
                return;
            }
            const isBreak = incoming.type === 'short_break' || incoming.type === 'long_break';
            const taskId = incoming.task_id != null ? Number(incoming.task_id) : null;
            const ownerId = incoming.owner_task_id != null ? Number(incoming.owner_task_id) : null;
            if (ctx.kind !== 'task') {
                if (!isBreak || ownerId == null) return;
                if (ownerId !== Number(ctx.itemId)) return;
            } else {
                if (!isBreak && taskId !== Number(ctx.itemId)) return;
                if (isBreak && ownerId != null && ownerId !== Number(ctx.itemId)) return;
            }
            const isForThisCard = isBreak
                ? (incoming.owner_task_id != null ? (ctx.kind === 'task' && Number(incoming.owner_task_id) === Number(ctx.itemId)) : ctx.isBreakFocused)
                : Number(incoming.task_id) === Number(ctx.itemId);
            if (isForThisCard && ctx.activeFocusSession?.started_at) {
                ctx.activeFocusSession = {
                    ...incoming,
                    started_at: ctx.activeFocusSession.started_at,
                    focus_mode_type: ctx.activeFocusSession.focus_mode_type ?? incoming.focus_mode_type ?? 'countdown',
                };
            } else {
                if (isBreak) return;
                ctx.activeFocusSession = incoming;
            }
            ctx.isBreakSession = isBreak;
            if (ctx.isPomodoroSession && incoming.sequence_number) ctx.pomodoroSequence = incoming.sequence_number;
            if (ctx.isPomodoroSession && incoming.task_id && !isBreak) ctx.lastPomodoroTaskId = incoming.task_id;
            if (isForThisCard || isBreak) {
                if (incoming.paused_seconds != null && Number.isFinite(Number(incoming.paused_seconds))) {
                    if (!ctx._focusJustResumed) ctx.focusPausedSecondsAccumulated = Math.max(0, Math.floor(Number(incoming.paused_seconds)));
                }
                if (incoming.paused_at) {
                    ctx._focusJustResumed = false;
                    ctx.focusIsPaused = true;
                    if (ctx.focusPauseStartedAt == null) ctx.focusPauseStartedAt = parseFocusStartedAt(incoming.paused_at);
                } else {
                    ctx._focusJustResumed = false;
                    ctx.focusIsPaused = false;
                    ctx.focusPauseStartedAt = null;
                }
            }
        },

        destroy(ctx) {
            ctx.stopFocusTicker();
            if (ctx._savePomodoroSettingsTimeout) {
                clearTimeout(ctx._savePomodoroSettingsTimeout);
                ctx._savePomodoroSettingsTimeout = null;
            }
            if (ctx._focusVisibilityListener) {
                document.removeEventListener('visibilitychange', ctx._focusVisibilityListener);
                ctx._focusVisibilityListener = null;
            }
            if (ctx._onPomodoroStartNextWork) {
                window.removeEventListener('pomodoro-start-next-work', ctx._onPomodoroStartNextWork);
                ctx._onPomodoroStartNextWork = null;
            }
            if (ctx._audioContext) {
                try {
                    ctx._audioContext.close();
                } catch (_) {}
                ctx._audioContext = null;
                ctx._audioGainNode = null;
            }
        },
    };
}

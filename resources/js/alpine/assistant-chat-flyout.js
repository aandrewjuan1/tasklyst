/**
 * Alpine.js component for the assistant chat flyout.
 * Config is provided by the Livewire component (no $wire param); use this.$wire for server calls.
 *
 * @param {Object} config - Initial state from server (no functions).
 * @param {number|null} config.threadId
 * @param {string} config.workspaceUrl
 * @param {Array<Object>} config.messages
 * @param {number} config.pendingAssistantCount
 * @param {string|null} config.currentTraceId
 * @param {Array<string>} config.suggestedPrompts
 * @param {string} [config.appTimezone] Application timezone for schedule display (e.g. 'Asia/Manila')
 * @param {string} [config.timeoutMessage] Message shown in chat when the assistant response times out
 * @returns {Object} Alpine component object (state + methods).
 */

/** Single source list for actionability; keep in sync with backend (RecommendationDisplayBuilder::buildAppliableChanges). */
const ACTIONABLE_INTENTS = [
    'schedule_task',
    'adjust_task_deadline',
    'create_task',
    'update_task_properties',
    'schedule_event',
    'adjust_event_time',
    'create_event',
    'update_event_properties',
    'schedule_project',
    'adjust_project_timeline',
    'create_project',
    'update_project_properties',
    'resolve_dependency',
    'prioritize_tasks',
    'schedule_tasks_and_events',
    'schedule_tasks_and_projects',
];

export function assistantChatFlyout(config) {
    return {
        threadId: config.threadId ?? null,
        workspaceUrl: config.workspaceUrl ?? '',
        input: '',
        isSending: false,
        isSubmittingMessage: false,
        isRateLimited: false,
        errorMessage: '',
        messages: Array.isArray(config.messages) ? config.messages : [],
        pendingAssistantCount: Number.isFinite(config.pendingAssistantCount) ? config.pendingAssistantCount : 0,
        _subscribedThreadId: null,
        _pendingTimeoutId: null,
        _onScroll: null,
        ignoreNextAssistant: false,
        currentTraceId: config.currentTraceId ?? null,
        appTimezone: config.appTimezone ?? 'Asia/Manila',
        suggestedPrompts: Array.isArray(config.suggestedPrompts) ? config.suggestedPrompts : [],
        timeoutMessage: typeof config.timeoutMessage === 'string' ? config.timeoutMessage : '',
        computedFollowups: [],
        resizeScheduled: false,
        pendingRecommendationIds: new Set(),

        findMessageIndexById(id) {
            return this.messages.findIndex((m) => String(m.id) === String(id));
        },

        /**
         * Returns a shallow copy of this.messages suitable for full restore on rollback.
         * Used by acceptRecommendation/rejectRecommendation (Phase 2 optimistic pattern).
         * @returns {Array<Object>}
         */
        cloneMessages() {
            return this.messages.map((m) => ({
                ...m,
                metadata: m.metadata && typeof m.metadata === 'object' ? { ...m.metadata } : {},
            }));
        },

        usePrompt(prompt) {
            this.input = prompt || '';
            this.$nextTick(() => this.$refs.input && this.$refs.input.focus());
        },

        hasAppliableChanges(message) {
            const snap = this.getSnapshot(message);
            const changes = snap.appliable_changes ?? {};
            const props = changes.properties;

            return (
                typeof props === 'object' &&
                props !== null &&
                !Array.isArray(props) &&
                Object.keys(props).length > 0
            );
        },

        /** Optimistic apply: 5-phase pattern (snapshot → update UI → call server → rollback on error). */
        async acceptRecommendation(message) {
            if (!message || !message.id) return;
            if (this.pendingRecommendationIds.has(message.id)) return;

            const backupMessages = this.cloneMessages();

            try {
                const idx = this.findMessageIndexById(message.id);
                if (idx === -1) return;

                const current = this.messages[idx];
                const meta =
                    current.metadata && typeof current.metadata === 'object' ? { ...current.metadata } : {};
                const snapshot =
                    meta.recommendation_snapshot && typeof meta.recommendation_snapshot === 'object'
                        ? { ...meta.recommendation_snapshot }
                        : {};

                snapshot.user_action = 'accept';
                snapshot.applied = true;
                meta.recommendation_snapshot = snapshot;

                this.messages[idx] = { ...current, metadata: { ...meta, recommendation_snapshot: snapshot } };

                this.pendingRecommendationIds.add(message.id);
                this.errorMessage = '';

                const messageId = Number(message.id);
                if (!Number.isInteger(messageId) || !this.$wire) {
                    this.messages = backupMessages;
                    this.pendingRecommendationIds.delete(message.id);
                    this.errorMessage = 'Unable to apply. Please refresh and try again.';

                    return;
                }
                await this.$wire.$call('acceptRecommendation', messageId);

                this.pendingRecommendationIds.delete(message.id);
            } catch (error) {
                this.messages = backupMessages;
                this.pendingRecommendationIds.delete(message.id);
                this.setRecommendationErrorMessage(error, 'apply');
            }
        },

        /** Optimistic dismiss: 5-phase pattern (snapshot → update UI → call server → rollback on error). */
        async rejectRecommendation(message) {
            if (!message || !message.id) return;
            if (this.pendingRecommendationIds.has(message.id)) return;

            const backupMessages = this.cloneMessages();

            try {
                const idx = this.findMessageIndexById(message.id);
                if (idx === -1) return;

                const current = this.messages[idx];
                const meta =
                    current.metadata && typeof current.metadata === 'object' ? { ...current.metadata } : {};
                const snapshot =
                    meta.recommendation_snapshot && typeof meta.recommendation_snapshot === 'object'
                        ? { ...meta.recommendation_snapshot }
                        : {};

                snapshot.user_action = 'reject';
                snapshot.applied = false;
                meta.recommendation_snapshot = snapshot;

                this.messages[idx] = { ...current, metadata: { ...meta, recommendation_snapshot: snapshot } };

                this.pendingRecommendationIds.add(message.id);
                this.errorMessage = '';

                const messageId = Number(message.id);
                if (!Number.isInteger(messageId) || !this.$wire) {
                    this.messages = backupMessages;
                    this.pendingRecommendationIds.delete(message.id);
                    this.errorMessage = 'Unable to dismiss. Please refresh and try again.';

                    return;
                }
                await this.$wire.$call('rejectRecommendation', messageId);

                this.pendingRecommendationIds.delete(message.id);
            } catch (error) {
                this.messages = backupMessages;
                this.pendingRecommendationIds.delete(message.id);
                this.setRecommendationErrorMessage(error, 'dismiss');
            }
        },

        /**
         * Set user-visible errorMessage from a failed accept/reject call.
         * Phase 3 rules: 422 → validation message; 403/404 → specific; else → generic. No silent failures.
         * @param {Error} error - Thrown from this.$wire.$call; may have .status, .data, .message (see optimistic-ui-guide).
         * @param {'apply'|'dismiss'} action
         */
        setRecommendationErrorMessage(error, action) {
            const status = error?.status;
            const data = error?.data;
            const isApply = action === 'apply';

            if (status === 419) {
                this.errorMessage =
                    'Session expired. Please refresh the page and try again.';
            } else if (status === 422) {
                this.errorMessage =
                    'Validation error: ' + (data?.message || error?.message || 'Validation failed');
            } else if (status === 403) {
                this.errorMessage = isApply
                    ? 'Permission denied while applying this suggestion.'
                    : 'Permission denied while dismissing this suggestion.';
            } else if (status === 404) {
                this.errorMessage = isApply
                    ? 'The referenced item no longer exists. The suggestion could not be applied.'
                    : 'The referenced item no longer exists. The suggestion was not processed.';
            } else {
                this.errorMessage =
                    error?.message ||
                    (isApply
                        ? 'Something went wrong while applying this suggestion. Please try again.'
                        : 'Something went wrong while dismissing this suggestion. Please try again.');
            }
        },

        isRecommendationApplied(message) {
            const snap = this.getSnapshot(message);
            return (
                snap.applied === true ||
                (typeof snap.user_action === 'string' && snap.user_action.length > 0)
            );
        },

        /** True when the Apply/Dismiss bar should be shown (actionable, has appliable changes, not yet applied/dismissed). */
        showApplyDismissBar(message) {
            return (
                this.isActionableIntent(message) &&
                this.hasAppliableChanges(message) &&
                !this.isRecommendationApplied(message)
            );
        },

        /** True when the post-action chip (Applied / Dismissed) should be shown. */
        showPostActionChip(message) {
            return (
                this.isActionableIntent(message) &&
                this.hasAppliableChanges(message) &&
                this.isRecommendationApplied(message)
            );
        },

        formatAppliableSummary(message) {
            const snap = this.getSnapshot(message);
            const changes = snap.appliable_changes || {};
            const props = changes.properties || {};
            const labels = [];

            if ('startDatetime' in props) {
                labels.push('start ' + this.formatInAppTimezone(props.startDatetime));
            }

            if ('endDatetime' in props) {
                labels.push('end ' + this.formatInAppTimezone(props.endDatetime));
            }

            if ('duration' in props) {
                labels.push('duration ' + props.duration + ' min');
            }

            if ('priority' in props) {
                labels.push('priority ' + String(props.priority).toUpperCase());
            }

            if ('title' in props) {
                labels.push('title "' + String(props.title) + '"');
            }

            if ('description' in props) {
                labels.push('description');
            }

            if ('complexity' in props) {
                labels.push('complexity ' + String(props.complexity).toUpperCase());
            }

            if ('status' in props) {
                labels.push('status ' + String(props.status).toUpperCase());
            }

            if (labels.length === 0) {
                return 'Apply these suggested changes?';
            }

            return 'Apply these changes: ' + labels.join(', ');
        },

        lastAssistant() {
            for (let i = this.messages.length - 1; i >= 0; i--) {
                const m = this.messages[i];
                if (m && m.role === 'assistant') return m;
            }

            return null;
        },

        followupSuggestions() {
            const msg = this.lastAssistant();
            if (!msg || !msg.metadata) return [];
            const snap = msg.metadata.recommendation_snapshot || {};
            const raw = Array.isArray(snap.followup_suggestions) ? snap.followup_suggestions : [];
            const intent = snap.intent || (msg.metadata && msg.metadata.intent) || null;
            const reasoning = snap.reasoning || null;

            if (reasoning === 'social_closing') {
                return [];
            }

            let cleaned = raw
                .filter((item) => typeof item === 'string')
                .map((item) => item.trim())
                .filter((item) => item.length > 0);

            const seen = new Set();
            cleaned = cleaned.filter((item) => {
                const key = item.toLowerCase();
                if (seen.has(key)) return false;
                seen.add(key);

                return true;
            });

            if (cleaned.length === 0 && intent) {
                cleaned = this.defaultFollowupsForIntent(intent);
            }

            return cleaned.slice(0, 3).map((prompt) => ({ prompt }));
        },

        formatInAppTimezone(isoString) {
            if (!isoString) return '';
            try {
                return new Date(isoString).toLocaleString(undefined, { timeZone: this.appTimezone });
            } catch (e) {
                return isoString;
            }
        },

        formatTimeRange(structured) {
            if (!structured || typeof structured !== 'object') return '';

            if (structured._formatted_time_range) {
                return structured._formatted_time_range;
            }

            const parts = [];

            if (structured.start_datetime) {
                parts.push(this.formatInAppTimezone(structured.start_datetime));
            }

            if (structured.end_datetime) {
                parts.push(this.formatInAppTimezone(structured.end_datetime));
            }

            const value = parts.filter(Boolean).join(' \u2192 ');
            structured._formatted_time_range = value;

            return value;
        },

        formatItemRange(item) {
            if (!item || typeof item !== 'object') return '';

            if (item._formatted_range) {
                return item._formatted_range;
            }

            const parts = [];

            if (item.start_datetime) {
                parts.push(this.formatInAppTimezone(item.start_datetime));
            }

            if (item.end_datetime) {
                parts.push(this.formatInAppTimezone(item.end_datetime));
            }

            const value = parts.filter(Boolean).join(' \u2192 ');
            item._formatted_range = value;

            return value;
        },

        formatItemEnd(item) {
            if (!item || typeof item !== 'object') return '';

            if (item._formatted_end) {
                return item._formatted_end;
            }

            if (!item.end_datetime) {
                item._formatted_end = '';

                return '';
            }

            const formatted = this.formatInAppTimezone(item.end_datetime);
            const value = ` \u2014 ${formatted}`;
            item._formatted_end = value;

            return value;
        },

        defaultFollowupsForIntent(intent) {
            switch (intent) {
                case 'prioritize_tasks':
                    return [
                        'Schedule the top task for today.',
                        'Show my tasks with no due date.',
                    ];
                case 'prioritize_events':
                    return [
                        'Which events should I focus on this week?',
                    ];
                case 'prioritize_tasks_and_events':
                    return [
                        'Schedule the top task for today.',
                        'Which events should I focus on this week?',
                    ];
                case 'prioritize_tasks_and_projects':
                    return [
                        'Schedule the top task for today.',
                        'Break my top project into steps.',
                    ];
                case 'prioritize_events_and_projects':
                    return [
                        'Which events this week?',
                        'Help me plan my top project.',
                    ];
                case 'prioritize_all':
                    return [
                        'Schedule the top task for today.',
                        'Which events should I focus on this week?',
                        'Break my top project into steps.',
                    ];
                case 'prioritize_projects':
                    return [
                        'Help me break my top project into smaller steps.',
                    ];
                case 'schedule_task':
                case 'adjust_task_deadline':
                    return [
                        'Can you suggest another time slot for this task?',
                    ];
                case 'schedule_event':
                case 'adjust_event_time':
                    return [
                        'Suggest a different time for this event.',
                    ];
                case 'schedule_project':
                case 'adjust_project_timeline':
                    return [
                        'Help me plan milestones for this project.',
                    ];
                case 'schedule_tasks_and_events':
                    return [
                        'Adjust the time for my top task.',
                        'Schedule another event.',
                    ];
                case 'schedule_tasks_and_projects':
                    return [
                        'Adjust the time for my top task.',
                        'Help me plan milestones for a project.',
                    ];
                case 'schedule_events_and_projects':
                    return [
                        'Suggest a different time for an event.',
                        'Help me plan milestones for a project.',
                    ];
                case 'schedule_all':
                    return [
                        'Adjust the time for my top task.',
                        'Schedule another item.',
                    ];
                case 'resolve_dependency':
                    return [
                        'Show me which tasks are still blocked.',
                    ];
                case 'general_query':
                    return [
                        'What should I focus on next?',
                    ];
                default:
                    return [];
            }
        },

        getSnapshot(message) {
            if (!message || typeof message.metadata !== 'object' || message.metadata === null) {
                return {};
            }
            const snap = message.metadata.recommendation_snapshot;
            return typeof snap === 'object' && snap !== null && !Array.isArray(snap) ? snap : {};
        },

        getStructured(message) {
            const snap = this.getSnapshot(message);

            return snap.structured || {};
        },

        /**
         * Merged schedule fields for the "Proposed schedule" block (structured + proposed_properties).
         * Use so we show dates when the LLM puts them only in proposed_properties.
         */
        getScheduleDisplay(message) {
            const s = this.getStructured(message);
            const p =
                s?.proposed_properties && typeof s.proposed_properties === 'object'
                    ? s.proposed_properties
                    : {};
            return {
                start_datetime: s?.start_datetime ?? p?.start_datetime,
                end_datetime: s?.end_datetime ?? p?.end_datetime,
                duration: s?.duration ?? p?.duration,
                priority: s?.priority ?? p?.priority,
                timezone: s?.timezone ?? p?.timezone,
                location: s?.location ?? p?.location,
            };
        },

        /** True when we have at least one schedule field to show (when/duration/priority). Avoids empty "Proposed schedule" block. */
        hasScheduleDisplayData(message) {
            const d = this.getScheduleDisplay(message);
            return !!(
                (d?.start_datetime ?? d?.end_datetime ?? d?.duration ?? d?.priority ?? d?.timezone ?? d?.location)
            );
        },

        isSchedulingIntent(message) {
            const snap = this.getSnapshot(message);
            const intent = snap.intent || (message.metadata && message.metadata.intent) || null;

            if (!intent) return false;

            return [
                'schedule_task',
                'adjust_task_deadline',
                'schedule_event',
                'adjust_event_time',
                'schedule_project',
                'adjust_project_timeline',
            ].includes(intent);
        },

        isActionableIntent(message) {
            const snap = this.getSnapshot(message);
            const intent =
                snap.intent ?? (message?.metadata && message.metadata.intent) ?? null;
            return typeof intent === 'string' && intent.length > 0 && ACTIONABLE_INTENTS.includes(intent);
        },

        contextEntityLabel(message) {
            const snap = this.getSnapshot(message);
            const entity = snap.entity_type || (message.metadata && message.metadata.entity_type) || null;

            if (entity === 'task') {
                return 'Tasks';
            }

            if (entity === 'event') {
                return 'Events';
            }

            if (entity === 'project') {
                return 'Projects';
            }

            return '';
        },

        contextIntentLabel(message) {
            const snap = this.getSnapshot(message);
            const intent = snap.intent || (message.metadata && message.metadata.intent) || null;

            if (!intent) {
                return '';
            }

            if (intent.startsWith('prioritize_')) {
                return 'Prioritisation';
            }

            if (intent.startsWith('schedule_') || intent.startsWith('adjust_')) {
                return 'Scheduling';
            }

            if (intent === 'resolve_dependency') {
                return 'Dependencies';
            }

            if (intent === 'general_query') {
                return 'General';
            }

            return '';
        },

        scrollToBottom(force = false) {
            const scroller = this.$refs.scroller;
            if (!scroller) return;

            const distance = scroller.scrollHeight - (scroller.scrollTop + scroller.clientHeight);
            const nearBottom = distance < 40;

            if (force || nearBottom) {
                scroller.scrollTop = scroller.scrollHeight;
                this.showJumpToLatest = false;
            } else {
                this.showJumpToLatest = true;
            }
        },

        showJumpToLatest: false,

        handleScroll() {
            const scroller = this.$refs.scroller;
            if (!scroller) return;

            const distance = scroller.scrollHeight - (scroller.scrollTop + scroller.clientHeight);
            const nearBottom = distance < 40;
            this.showJumpToLatest = !nearBottom && this.pendingAssistantCount === 0;
        },

        resizeInput() {
            if (this.resizeScheduled) {
                return;
            }

            this.resizeScheduled = true;

            window.requestAnimationFrame(() => {
                const el = this.$refs.input;
                if (!el) {
                    this.resizeScheduled = false;

                    return;
                }

                const maxHeight = 96;

                el.style.height = 'auto';
                const next = Math.min(el.scrollHeight, maxHeight);
                el.style.height = `${next}px`;

                this.resizeScheduled = false;
            });
        },

        startPendingTimeout() {
            if (this._pendingTimeoutId) {
                clearTimeout(this._pendingTimeoutId);
                this._pendingTimeoutId = null;
            }

            if (this.pendingAssistantCount <= 0) return;

            this._pendingTimeoutId = window.setTimeout(() => {
                if (this.pendingAssistantCount > 0) {
                    this.pendingAssistantCount = 0;
                    this._pendingTimeoutId = null;
                    const text = this.timeoutMessage || 'The assistant is taking longer than expected. Please try again in a moment.';
                    this.messages.push({
                        id: `timeout-${Date.now()}`,
                        role: 'assistant',
                        content: text,
                        created_at: new Date().toISOString(),
                        metadata: {},
                    });
                    this.$nextTick(() => this.scrollToBottom(true));
                }
            }, 45000);
        },

        async startNewChat() {
            if (this.isSubmittingMessage || this.pendingAssistantCount > 0 || this.currentTraceId) {
                await this.cancelPending();
            }

            this.ignoreNextAssistant = false;

            this.isRateLimited = false;
            this.errorMessage = '';
            this.input = '';
            this.showJumpToLatest = false;

            if (this._pendingTimeoutId) {
                clearTimeout(this._pendingTimeoutId);
                this._pendingTimeoutId = null;
            }

            this.isSending = true;

            try {
                const payload = await this.$wire.$call('newThread');

                const threadId = payload.thread_id ?? null;
                const messages = payload.messages ?? [];

                this.threadId = threadId;
                this.messages = messages;
                this.pendingAssistantCount = 0;
                this.computedFollowups = this.followupSuggestions();

                if (this.threadId) {
                    this.subscribeToThread();
                }

                this.$nextTick(() => {
                    this.scrollToBottom(true);
                    if (this.$refs.input) {
                        this.$refs.input.focus();
                    }
                });
            } catch (error) {
                console.error(error);
                this.errorMessage = 'Unable to start a new chat right now. Please try again.';
            } finally {
                this.isSending = false;
            }
        },

        subscribeToThread() {
            if (!this.threadId) return;
            if (this._subscribedThreadId === this.threadId) return;
            if (typeof window === 'undefined' || !window.Echo) return;

            const threadId = this.threadId;
            const bareName = `assistant.thread.${threadId}`;
            const fullName = `private-${bareName}`;

            if (this._subscribedThreadId && window.Echo.leave) {
                const prevBare = `assistant.thread.${this._subscribedThreadId}`;
                const prevFull = `private-${prevBare}`;
                window.Echo.leave(prevFull);
            }

            window.Echo
                .private(bareName)
                .listen('AssistantMessageCreated', (event) => {
                    if (!event) return;
                    const eventThreadId = event.thread_id ?? event.threadId ?? null;
                    if (!eventThreadId || String(eventThreadId) !== String(this.threadId)) {
                        return;
                    }

                    const id = event.id ?? null;
                    const role = event.role ?? null;
                    const content = event.content ?? '';
                    const createdAt = event.created_at ?? null;
                    const metadata = event.metadata ?? {};

                    if (!id || role !== 'assistant') {
                        return;
                    }

                    const exists = this.messages.some((m) => String(m.id) === String(id));
                    if (exists) {
                        return;
                    }

                    if (this.ignoreNextAssistant && this.pendingAssistantCount === 0) {
                        this.ignoreNextAssistant = false;
                        return;
                    }

                    this.messages.push({
                        id,
                        role,
                        content,
                        created_at: createdAt,
                        metadata,
                    });

                    const snap = metadata.recommendation_snapshot || {};
                    if (snap.reasoning === 'rate_limited') {
                        this.isRateLimited = true;
                    } else if (snap.reasoning && snap.reasoning !== 'rate_limited') {
                        this.isRateLimited = false;
                    }

                    if (this.pendingAssistantCount > 0) {
                        this.pendingAssistantCount = Math.max(0, this.pendingAssistantCount - 1);
                    }

                    if (this.pendingAssistantCount === 0 && this._pendingTimeoutId) {
                        clearTimeout(this._pendingTimeoutId);
                        this._pendingTimeoutId = null;
                    }

                    this.$nextTick(() => {
                        this.scrollToBottom(true);
                    });

                    this.computedFollowups = this.followupSuggestions();
                });

            this._subscribedThreadId = threadId;
        },

        async cancelPending() {
            if (!this.isSending && this.pendingAssistantCount <= 0 && !this.currentTraceId) {
                return;
            }

            this.isSending = false;
            this.isSubmittingMessage = false;
            this.pendingAssistantCount = 0;
            this.ignoreNextAssistant = true;

            if (this._pendingTimeoutId) {
                clearTimeout(this._pendingTimeoutId);
                this._pendingTimeoutId = null;
            }

            let lastUserMessageId = null;
            for (let i = this.messages.length - 1; i >= 0; i--) {
                const m = this.messages[i];
                if (m && m.role === 'user') {
                    const meta = m.metadata && typeof m.metadata === 'object' ? { ...m.metadata } : {};
                    meta.llm_cancelled = true;
                    this.messages[i] = { ...m, metadata: meta };
                    if (m.id && typeof m.id === 'number') {
                        lastUserMessageId = m.id;
                    }
                    break;
                }
            }

            const traceIdToCancel = this.currentTraceId || null;
            this.currentTraceId = null;

            const tasks = [];

            if (traceIdToCancel) {
                tasks.push(this.$wire.$call('cancelInference', traceIdToCancel));
            }

            if (lastUserMessageId !== null) {
                tasks.push(this.$wire.$call('markMessageCancelled', lastUserMessageId));
            }

            if (tasks.length > 0) {
                try {
                    await Promise.all(tasks);
                } catch (error) {
                    console.error(error);
                    if (!this.errorMessage) {
                        this.errorMessage =
                            'We had trouble stopping the previous assistant request. Please check your queue worker.';
                    }
                }
            }
        },

        async submit() {
            if (this.isRateLimited || this.isSending || this.pendingAssistantCount > 0) return;
            const text = (this.input || '').trim();
            if (!text) return;

            const clientId = `temp-${Date.now()}`;
            const snapshot = [...this.messages];

            const optimistic = {
                id: clientId,
                client_id: clientId,
                role: 'user',
                content: text,
                created_at: new Date().toISOString(),
                metadata: {},
            };

            this.messages.push(optimistic);
            this.$nextTick(() => {
                this.scrollToBottom(true);
            });
            this.input = '';
            this.isSending = true;
            this.isSubmittingMessage = true;
            this.errorMessage = '';

            try {
                const result = await this.$wire.$call('send', text, clientId);

                const payload = result || {};
                const serverThreadId = payload.thread_id ?? null;
                const message = payload.message ?? null;

                if (serverThreadId && !this.threadId) {
                    this.threadId = serverThreadId;
                    this.subscribeToThread();
                }

                if (message && message.role === 'user') {
                    const idx = this.messages.findIndex((m) => m.client_id === clientId);
                    if (idx !== -1) {
                        this.messages[idx] = {
                            ...this.messages[idx],
                            ...message,
                            id: message.id,
                        };
                    } else {
                        this.messages.push(message);
                    }

                    this.pendingAssistantCount += 1;
                    this.startPendingTimeout();
                    this.currentTraceId = message.metadata && message.metadata.llm_trace_id
                        ? message.metadata.llm_trace_id
                        : null;
                } else if (message && message.role === 'assistant') {
                    this.messages.push(message);

                    const snap = (message.metadata && message.metadata.recommendation_snapshot) || {};
                    if (snap.reasoning === 'rate_limited') {
                        this.isRateLimited = true;
                    } else if (snap.reasoning && snap.reasoning !== 'rate_limited') {
                        this.isRateLimited = false;
                    }

                    this.currentTraceId = null;

                    this.computedFollowups = this.followupSuggestions();
                }
            } catch (error) {
                console.error(error);
                this.messages = snapshot;
                this.errorMessage =
                    error?.message || 'Something went wrong while sending your message. Please try again.';
            } finally {
                this.isSending = false;
                this.isSubmittingMessage = false;
                this.$nextTick(() => {
                    this.scrollToBottom(true);
                });
            }
        },

        async submitPrompt(prompt) {
            if (this.isRateLimited || this.isSending || this.pendingAssistantCount > 0) return;
            const text = (prompt || '').trim();
            if (!text) return;

            const clientId = `temp-${Date.now()}`;
            const snapshot = [...this.messages];

            const optimistic = {
                id: clientId,
                client_id: clientId,
                role: 'user',
                content: text,
                created_at: new Date().toISOString(),
                metadata: {},
            };

            this.messages.push(optimistic);
            this.$nextTick(() => {
                this.scrollToBottom(true);
            });
            this.isSending = true;
            this.isSubmittingMessage = true;
            this.errorMessage = '';

            try {
                const result = await this.$wire.$call('send', text, clientId);

                const payload = result || {};
                const serverThreadId = payload.thread_id ?? null;
                const message = payload.message ?? null;

                if (serverThreadId && !this.threadId) {
                    this.threadId = serverThreadId;
                    this.subscribeToThread();
                }

                if (message && message.role === 'user') {
                    const idx = this.messages.findIndex((m) => m.client_id === clientId);
                    if (idx !== -1) {
                        this.messages[idx] = {
                            ...this.messages[idx],
                            ...message,
                            id: message.id,
                        };
                    } else {
                        this.messages.push(message);
                    }

                    this.pendingAssistantCount += 1;
                    this.startPendingTimeout();
                    this.currentTraceId = message.metadata && message.metadata.llm_trace_id
                        ? message.metadata.llm_trace_id
                        : null;
                } else if (message && message.role === 'assistant') {
                    this.messages.push(message);

                    const snap = (message.metadata && message.metadata.recommendation_snapshot) || {};
                    if (snap.reasoning === 'rate_limited') {
                        this.isRateLimited = true;
                    } else if (snap.reasoning && snap.reasoning !== 'rate_limited') {
                        this.isRateLimited = false;
                    }

                    this.currentTraceId = null;

                    this.computedFollowups = this.followupSuggestions();
                }
            } catch (error) {
                console.error(error);
                this.messages = snapshot;
                this.errorMessage =
                    error?.message || 'Something went wrong while sending your message. Please try again.';
            } finally {
                this.isSending = false;
                this.isSubmittingMessage = false;
                this.$nextTick(() => {
                    this.scrollToBottom(true);
                });
            }
        },

        init() {
            this.$nextTick(() => {
                if (this.$refs.scroller) {
                    this.$refs.scroller.scrollTop = this.$refs.scroller.scrollHeight;
                    this._onScroll = () => {
                        this.handleScroll();
                    };
                    this.$refs.scroller.addEventListener('scroll', this._onScroll, { passive: true });
                }
                if (this.threadId) {
                    this.subscribeToThread();
                }
                if (this.pendingAssistantCount > 0) {
                    this.startPendingTimeout();
                }
                if (this.$refs.input) {
                    this.resizeInput();
                }

                this.computedFollowups = this.followupSuggestions();
            });
        },
    };
}


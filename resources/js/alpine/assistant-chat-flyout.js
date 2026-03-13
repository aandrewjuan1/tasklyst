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
    'schedule_tasks',
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

        /** LLM output has suggested properties we can apply (e.g. startDatetime, duration). Purely UI-level check. */
        hasAppliableChanges(message) {
            const snap = this.getSnapshot(message);
            const changes = snap.appliable_changes ?? snap.appliableChanges ?? {};
            const props = changes.properties;
            const updates = changes.updates;
            const structured = this.getStructured(message);
            const hasProps =
                typeof props === 'object' &&
                props !== null &&
                !Array.isArray(props) &&
                Object.keys(props).length > 0;
            if (hasProps) return true;
            if (Array.isArray(updates) && updates.length > 0) return true;
            const startDt = structured.start_datetime ?? structured.startDatetime;
            const duration = structured.duration;
            return !!(startDt && (duration != null || startDt));
        },

        /** LLM output has the item id so we can update the correct task (required for Apply to work). */
        hasTaskIdInSnapshot(message) {
            const structured = this.getStructured(message);
            const id = structured.target_task_id ?? structured.id;
            return id != null && !Number.isNaN(Number(id)) && Number(id) > 0;
        },

        /** True when this message has an actionable task suggestion: id + suggested properties. */
        hasSuggestedTaskUpdate(message) {
            return this.hasTaskIdInSnapshot(message) && this.hasAppliableChanges(message);
        },

        /** Optimistic apply: update local snapshot; backend wiring will be added separately. */
        acceptRecommendation(message) {
            if (!message || !message.id) return;
            if (this.pendingRecommendationIds.has(message.id)) return;

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

            this.$dispatch('assistant-chat:apply', { messageId: message.id, snapshot });

            this.pendingRecommendationIds.delete(message.id);
        },

        /** Optimistic dismiss: update local snapshot; backend wiring will be added separately. */
        rejectRecommendation(message) {
            if (!message || !message.id) return;
            if (this.pendingRecommendationIds.has(message.id)) return;

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

            this.$dispatch('assistant-chat:dismiss', { messageId: message.id, snapshot });

            this.pendingRecommendationIds.delete(message.id);
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

        /** True when the Apply/Dismiss bar should be shown: has task id + suggested properties, not yet applied/dismissed. */
        showApplyDismissBar(message) {
            const hasActionable = this.hasSuggestedTaskUpdate(message) || (this.isActionableIntent(message) && this.hasAppliableChanges(message));

            return hasActionable && !this.isRecommendationApplied(message);
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

        /**
         * Structured output from the LLM (single source of truth; stored raw in snapshot).
         * Normalized to a single object (backend may send array from Prism).
         */
        getStructured(message) {
            const snap = this.getSnapshot(message);
            const s = snap.structured;
            if (!s || typeof s !== 'object') return {};
            if (Array.isArray(s) && s[0] && typeof s[0] === 'object') return s[0];
            return s;
        },

        /**
         * Merged schedule fields for the "Proposed schedule" block (from structured + proposed_properties).
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
                (d?.start_datetime ?? d?.end_datetime ?? d?.duration ?? d?.priority)
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

        startNewChat() {
            this.ignoreNextAssistant = false;

            this.isRateLimited = false;
            this.errorMessage = '';
            this.input = '';
            this.showJumpToLatest = false;

            if (this._pendingTimeoutId) {
                clearTimeout(this._pendingTimeoutId);
                this._pendingTimeoutId = null;
            }

            this.threadId = null;
            this.messages = [];
            this.pendingAssistantCount = 0;

            this.$dispatch('assistant-chat:new-thread');

            this.$nextTick(() => {
                this.scrollToBottom(true);
                if (this.$refs.input) {
                    this.$refs.input.focus();
                }
            });
        },

        subscribeToThread() {
            if (!this.threadId) return;

            this._subscribedThreadId = this.threadId;
        },

        cancelPending() {
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

            this.$dispatch('assistant-chat:cancel', {
                traceId: traceIdToCancel,
                lastUserMessageId,
            });
        },

        submit() {
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
            this.$dispatch('assistant-chat:submit', { text, clientId });
            this.isSending = false;
            this.isSubmittingMessage = false;
            this.$nextTick(() => {
                this.scrollToBottom(true);
            });
        },

        submitPrompt(prompt) {
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
            this.$dispatch('assistant-chat:submit', { text, clientId });
            this.isSending = false;
            this.isSubmittingMessage = false;
            this.$nextTick(() => {
                this.scrollToBottom(true);
            });
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
                if (this.pendingAssistantCount > 0) {
                    this.startPendingTimeout();
                }
                if (this.$refs.input) {
                    this.resizeInput();
                }

            });
        },
    };
}


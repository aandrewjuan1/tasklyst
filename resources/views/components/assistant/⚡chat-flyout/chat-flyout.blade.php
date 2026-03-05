
<div
    wire:ignore
    x-data="{
        threadId: @js($this->threadId),
        workspaceUrl: @js(route('workspace')),
        input: '',
        isSending: false,
        isRateLimited: false,
        errorMessage: '',
        messages: @js($this->messages),
        pendingAssistantCount: @js($this->pendingAssistantCount),
        _subscribedThreadId: null,
        _pendingTimeoutId: null,
        ignoreNextAssistant: false,
        currentTraceId: @js($this->currentTraceId),
        suggestedPrompts: [
            {{ json_encode(__('Prioritise my tasks for this week.')) }},
            {{ json_encode(__('Schedule my events for the next few days.')) }},
            {{ json_encode(__('List tasks, events, and projects with no due date.')) }},
            {{ json_encode(__('Filter my projects to those I should focus on today.')) }},
        ],
        usePrompt(prompt) {
            this.input = prompt || '';
            this.$nextTick(() => this.$refs.input && this.$refs.input.focus());
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

            return raw
                .filter((item) => typeof item === 'string' && item.trim().length > 0)
                .map((prompt) => ({ prompt }));
        },
        getSnapshot(message) {
            if (!message || !message.metadata) return {};
            return message.metadata.recommendation_snapshot || {};
        },
        getStructured(message) {
            const snap = this.getSnapshot(message);
            return snap.structured || {};
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
            const intent = snap.intent || (message.metadata && message.metadata.intent) || null;

            if (!intent) return false;

            return [
                'schedule_task',
                'schedule_event',
                'schedule_project',
                'adjust_task_deadline',
                'adjust_event_time',
                'adjust_project_timeline',
                'resolve_dependency',
                'prioritize_tasks',
            ].includes(intent);
        },
        contextEntityLabel(message) {
            const snap = this.getSnapshot(message);
            const entity = snap.entity_type || (message.metadata && message.metadata.entity_type) || null;

            if (entity === 'task') {
                return '{{ __('Tasks') }}';
            }

            if (entity === 'event') {
                return '{{ __('Events') }}';
            }

            if (entity === 'project') {
                return '{{ __('Projects') }}';
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
                return '{{ __('Prioritisation') }}';
            }

            if (intent.startsWith('schedule_') || intent.startsWith('adjust_')) {
                return '{{ __('Scheduling') }}';
            }

            if (intent === 'resolve_dependency') {
                return '{{ __('Dependencies') }}';
            }

            if (intent === 'general_query') {
                return '{{ __('General') }}';
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
            const el = this.$refs.input;
            if (!el) return;

            const maxHeight = 96; // ~3–4 lines

            el.style.height = 'auto';
            const next = Math.min(el.scrollHeight, maxHeight);
            el.style.height = `${next}px`;
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
                    this.errorMessage = '{{ __('The assistant is taking longer than usual. Please make sure the background queue is running and try again.') }}';
                }
            }, 45000);
        },
        startNewChat() {
            if (this.isSending || this.pendingAssistantCount > 0) return;

            this.isRateLimited = false;
            this.errorMessage = '';
            this.input = '';
            this.showJumpToLatest = false;

            if (this._pendingTimeoutId) {
                clearTimeout(this._pendingTimeoutId);
                this._pendingTimeoutId = null;
            }

            this.isSending = true;

            $wire.$call('newThread')
                .then((payload) => {
                    const threadId = payload.thread_id ?? null;
                    const messages = payload.messages ?? [];

                    this.threadId = threadId;
                    this.messages = messages;
                    this.pendingAssistantCount = 0;

                    if (this.threadId) {
                        this.subscribeToThread();
                    }

                    this.$nextTick(() => {
                        this.scrollToBottom(true);
                        if (this.$refs.input) {
                            this.$refs.input.focus();
                        }
                    });
                })
                .catch((error) => {
                    console.error(error);
                    this.errorMessage = '{{ __('Unable to start a new chat right now. Please try again.') }}';
                })
                .finally(() => {
                    this.isSending = false;
                });
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

                    const exists = this.messages.some(m => String(m.id) === String(id));
                    if (exists) {
                        return;
                    }

                    if (this.ignoreNextAssistant) {
                        this.ignoreNextAssistant = false;
                        return;
                    }

                    this.messages = [
                        ...this.messages,
                        {
                            id,
                            role,
                            content,
                            created_at: createdAt,
                            metadata,
                        },
                    ];

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
                });

            this._subscribedThreadId = threadId;
        },
        cancelPending() {
            if (!this.isSending && this.pendingAssistantCount <= 0) return;

            this.isSending = false;
            this.pendingAssistantCount = 0;
            this.ignoreNextAssistant = true;

            if (this._pendingTimeoutId) {
                clearTimeout(this._pendingTimeoutId);
                this._pendingTimeoutId = null;
            }

            // Mark the latest user message as stopped in the UI and backend
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

            if (this.currentTraceId) {
                $wire.$call('cancelInference', this.currentTraceId);
                this.currentTraceId = null;
            }

            if (lastUserMessageId !== null) {
                $wire.$call('markMessageCancelled', lastUserMessageId);
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

            this.messages = [...this.messages, optimistic];
            this.$nextTick(() => {
                this.scrollToBottom(true);
            });
            this.input = '';
            this.isSending = true;
            this.errorMessage = '';

            try {
                const result = await $wire.$call('send', text, clientId);

                const payload = result || {};
                const serverThreadId = payload.thread_id ?? null;
                const message = payload.message ?? null;

                if (serverThreadId && !this.threadId) {
                    this.threadId = serverThreadId;
                    this.subscribeToThread();
                }

                if (message && message.role === 'user') {
                    const idx = this.messages.findIndex(m => m.client_id === clientId);
                    if (idx !== -1) {
                        this.messages[idx] = {
                            ...this.messages[idx],
                            ...message,
                            id: message.id,
                        };
                    } else {
                        this.messages = [...this.messages, message];
                    }

                    this.pendingAssistantCount += 1;
                    this.startPendingTimeout();
                    this.currentTraceId = message.metadata && message.metadata.llm_trace_id
                        ? message.metadata.llm_trace_id
                        : null;
                } else if (message && message.role === 'assistant') {
                    this.messages = [...this.messages, message];

                    const snap = (message.metadata && message.metadata.recommendation_snapshot) || {};
                    if (snap.reasoning === 'rate_limited') {
                        this.isRateLimited = true;
                    } else if (snap.reasoning && snap.reasoning !== 'rate_limited') {
                        this.isRateLimited = false;
                    }

                    this.currentTraceId = null;
                }
            } catch (error) {
                console.error(error);
                this.messages = snapshot;
                this.errorMessage = error?.message || '{{ __('Something went wrong while sending your message. Please try again.') }}';
            } finally {
                this.isSending = false;
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

            this.messages = [...this.messages, optimistic];
            this.$nextTick(() => {
                this.scrollToBottom(true);
            });
            this.isSending = true;
            this.errorMessage = '';

            try {
                const result = await $wire.$call('send', text, clientId);

                const payload = result || {};
                const serverThreadId = payload.thread_id ?? null;
                const message = payload.message ?? null;

                if (serverThreadId && !this.threadId) {
                    this.threadId = serverThreadId;
                    this.subscribeToThread();
                }

                if (message && message.role === 'user') {
                    const idx = this.messages.findIndex(m => m.client_id === clientId);
                    if (idx !== -1) {
                        this.messages[idx] = {
                            ...this.messages[idx],
                            ...message,
                            id: message.id,
                        };
                    } else {
                        this.messages = [...this.messages, message];
                    }

                    this.pendingAssistantCount += 1;
                    this.startPendingTimeout();
                    this.currentTraceId = message.metadata && message.metadata.llm_trace_id
                        ? message.metadata.llm_trace_id
                        : null;
                } else if (message && message.role === 'assistant') {
                    this.messages = [...this.messages, message];

                    const snap = (message.metadata && message.metadata.recommendation_snapshot) || {};
                    if (snap.reasoning === 'rate_limited') {
                        this.isRateLimited = true;
                    } else if (snap.reasoning && snap.reasoning !== 'rate_limited') {
                        this.isRateLimited = false;
                    }

                    this.currentTraceId = null;
                }
            } catch (error) {
                console.error(error);
                this.messages = snapshot;
                this.errorMessage = error?.message || '{{ __('Something went wrong while sending your message. Please try again.') }}';
            } finally {
                this.isSending = false;
                this.$nextTick(() => {
                    this.scrollToBottom(true);
                });
            }
        },
    }"
    class="flex h-full flex-col"
    x-init="
        $nextTick(() => {
            if ($refs.scroller) {
                $refs.scroller.scrollTop = $refs.scroller.scrollHeight;
                $refs.scroller.addEventListener('scroll', () => { handleScroll() });
            }
            if (threadId) {
                subscribeToThread();
            }
            if (pendingAssistantCount > 0) {
                startPendingTimeout();
            }
            if ($refs.input) {
                resizeInput();
            }
        })
    "
>
    <div class="border-b border-border/60 px-4 py-3">
        <div class="flex items-center gap-2 min-w-0">
            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                <img
                    src="{{ asset('images/robot-face-svgrepo-com.svg') }}"
                    alt="{{ __('TaskLyst assistant avatar') }}"
                    class="h-7 w-7 rounded-full border border-emerald-500/40 bg-background"
                >
            </div>
            <div class="flex-1 min-w-0">
                <flux:heading size="md" class="truncate">
                    {{ __('TaskLyst Assistant') }}
                </flux:heading>
                <flux:text class="mt-0.5 text-xs text-muted-foreground">
                    {{ __('Helps you prioritise and schedule tasks, events, and projects.') }}
                </flux:text>
            </div>
        </div>
    </div>

    <div
        x-ref="scroller"
        class="flex-1 overflow-y-auto px-4 py-3 space-y-3"
    >
        <template x-if="messages.length === 0">
            <div class="flex h-full flex-col items-center justify-center text-center gap-3">
                <div class="space-y-1">
                    <p class="text-sm font-medium text-foreground">
                        {{ __('Plan what to work on next') }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ __('Use the assistant to prioritise, schedule, and filter your tasks, events, and projects.') }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center justify-center gap-2 max-w-sm">
                    <template x-for="prompt in suggestedPrompts" :key="prompt">
                        <flux:button
                            type="button"
                            size="sm"
                            variant="outline"
                            class="text-[11px]! px-3 py-1.5! whitespace-normal text-left cursor-pointer hover:bg-muted"
                            @click="usePrompt(prompt)"
                        >
                            <span x-text="prompt"></span>
                        </flux:button>
                    </template>
                </div>
            </div>
        </template>

        <template x-for="message in messages" :key="message.id">
            <div
                class="flex w-full"
                :class="message.role === 'user' ? 'justify-end' : 'justify-start'"
            >
                <div class="flex items-start gap-2 max-w-[80%]">
                    <template x-if="message.role === 'assistant'">
                        <div class="mt-0.5 shrink-0">
                            <img
                                src="{{ asset('images/robot-face-svgrepo-com.svg') }}"
                                alt="{{ __('TaskLyst assistant avatar') }}"
                                class="h-6 w-6 rounded-full border border-border/60 bg-background"
                            >
                        </div>
                    </template>

                    <div
                        class="flex-1 rounded-lg px-3 py-2 text-xs leading-relaxed"
                        :class="message.role === 'user'
                            ? 'bg-emerald-500 text-white dark:bg-emerald-500'
                            : 'bg-muted text-foreground'"
                    >
                    <p class="whitespace-pre-wrap" x-text="message.content"></p>

                    <template
                        x-if="message.role === 'user'
                            && message.metadata
                            && message.metadata.llm_cancelled"
                    >
                        <div class="mt-1 inline-flex items-center gap-1 rounded-full bg-background/20 px-2 py-0.5 text-[10px] font-medium text-zinc-900/90 dark:text-zinc-100/90">
                            <flux:icon name="stop-circle" class="size-3" />
                            <span>{{ __('Request stopped') }}</span>
                        </div>
                    </template>

                    <template x-if="message.role === 'assistant'">
                        <div class="mt-2 space-y-2">
                            <template x-if="getSnapshot(message).reasoning === 'off_topic_query'">
                                <div class="flex gap-1.5 rounded-md border border-amber-500/40 bg-amber-500/5 px-2.5 py-2 text-[11px] text-amber-900 dark:text-amber-100">
                                    <flux:icon name="shield-exclamation" class="mt-0.5 size-3.5 text-amber-500" />
                                    <div class="space-y-0.5">
                                        <p class="font-medium">
                                            {{ __('Out of scope for TaskLyst Assistant') }}
                                        </p>
                                        <p class="text-[10px] text-amber-900/80 dark:text-amber-100/80">
                                            {{ __('I can only help with your tasks, events, and projects. Try asking about your schedule, priorities, or workload.') }}
                                        </p>
                                    </div>
                                </div>
                            </template>

                            <template x-if="getSnapshot(message).reasoning === 'social_closing'">
                                <div class="inline-flex items-center gap-1 rounded-full bg-muted px-2.5 py-1 text-[10px] text-muted-foreground">
                                    <flux:icon name="hand-thumb-up" class="size-3" />
                                    <span>{{ __('Closing reply') }}</span>
                                </div>
                            </template>

                            <template x-if="isSchedulingIntent(message)">
                                <div class="space-y-1">
                                    <p class="text-[11px] font-medium text-muted-foreground">
                                        {{ __('Proposed schedule') }}
                                    </p>

                                    <div class="space-y-0.5 text-[11px] text-muted-foreground">
                                        <template x-if="getStructured(message).start_datetime || getStructured(message).end_datetime">
                                            <p>
                                                <span class="font-medium text-foreground">
                                                    {{ __('When:') }}
                                                </span>
                                                <span
                                                    x-text="[
                                                        getStructured(message).start_datetime ? new Date(getStructured(message).start_datetime).toLocaleString() : null,
                                                        getStructured(message).end_datetime ? new Date(getStructured(message).end_datetime).toLocaleString() : null,
                                                    ].filter(Boolean).join(' → ')"
                                                ></span>
                                            </p>
                                        </template>

                                        <template x-if="getStructured(message).duration">
                                            <p>
                                                <span class="font-medium text-foreground">
                                                    {{ __('Duration:') }}
                                                </span>
                                                <span x-text="getStructured(message).duration"></span>
                                            </p>
                                        </template>

                                        <template x-if="getStructured(message).timezone">
                                            <p>
                                                <span class="font-medium text-foreground">
                                                    {{ __('Timezone:') }}
                                                </span>
                                                <span x-text="getStructured(message).timezone"></span>
                                            </p>
                                        </template>

                                        <template x-if="getStructured(message).location">
                                            <p>
                                                <span class="font-medium text-foreground">
                                                    {{ __('Location:') }}
                                                </span>
                                                <span x-text="getStructured(message).location"></span>
                                            </p>
                                        </template>

                                        <template x-if="getStructured(message).priority">
                                            <p class="flex items-center gap-1.5">
                                                <span class="font-medium text-foreground">
                                                    {{ __('Priority:') }}
                                                </span>
                                                <span
                                                    class="inline-flex rounded-full bg-emerald-500/10 px-1.5 py-0.5 text-[10px] uppercase tracking-tight text-emerald-700 dark:text-emerald-300"
                                                    x-text="getStructured(message).priority"
                                                ></span>
                                            </p>
                                        </template>
                                    </div>

                                    <template x-if="getStructured(message).blockers && getStructured(message).blockers.length">
                                        <div class="mt-1 space-y-0.5">
                                            <p class="text-[11px] font-medium text-muted-foreground">
                                                {{ __('Blockers') }}
                                            </p>
                                            <ul class="list-disc pl-4 space-y-0.5 text-[11px] text-muted-foreground">
                                                <template
                                                    x-for="(blocker, index) in getStructured(message).blockers"
                                                    :key="`${index}-${blocker}`"
                                                >
                                                    <li x-text="blocker"></li>
                                                </template>
                                            </ul>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <template x-if="getStructured(message).ranked_tasks && getStructured(message).ranked_tasks.length">
                                <div class="space-y-1">
                                    <p class="text-[11px] font-medium text-muted-foreground">
                                        {{ __('Prioritised tasks') }}
                                    </p>
                                    <ol class="list-decimal pl-4 space-y-0.5">
                                        <template
                                            x-for="item in getStructured(message).ranked_tasks"
                                            :key="`${item.rank}-${item.title}`"
                                        >
                                            <li class="text-[11px]">
                                                <span x-text="`#${item.rank} ${item.title}`"></span>
                                                <span
                                                    x-show="item.end_datetime"
                                                    class="text-[11px] text-muted-foreground"
                                                    x-text="` — ${new Date(item.end_datetime).toLocaleString()}`"
                                                ></span>
                                            </li>
                                        </template>
                                    </ol>
                                </div>
                            </template>

                            <template x-if="getStructured(message).ranked_events && getStructured(message).ranked_events.length">
                                <div class="space-y-1">
                                    <p class="text-[11px] font-medium text-muted-foreground">
                                        {{ __('Prioritised events') }}
                                    </p>
                                    <ol class="list-decimal pl-4 space-y-0.5">
                                        <template
                                            x-for="event in getStructured(message).ranked_events"
                                            :key="`${event.rank}-${event.title}`"
                                        >
                                            <li class="text-[11px]">
                                                <span x-text="`#${event.rank} ${event.title}`"></span>
                                                <span
                                                    x-show="event.start_datetime || event.end_datetime"
                                                    class="text-[11px] text-muted-foreground"
                                                    x-text="[
                                                        event.start_datetime ? new Date(event.start_datetime).toLocaleString() : null,
                                                        event.end_datetime ? new Date(event.end_datetime).toLocaleString() : null,
                                                    ].filter(Boolean).join(' → ')"
                                                ></span>
                                            </li>
                                        </template>
                                    </ol>
                                </div>
                            </template>

                            <template x-if="getStructured(message).ranked_projects && getStructured(message).ranked_projects.length">
                                <div class="space-y-1">
                                    <p class="text-[11px] font-medium text-muted-foreground">
                                        {{ __('Prioritised projects') }}
                                    </p>
                                    <ol class="list-decimal pl-4 space-y-0.5">
                                        <template
                                            x-for="project in getStructured(message).ranked_projects"
                                            :key="`${project.rank}-${project.name}`"
                                        >
                                            <li class="text-[11px]">
                                                <span x-text="`#${project.rank} ${project.name}`"></span>
                                                <span
                                                    x-show="project.end_datetime"
                                                    class="text-[11px] text-muted-foreground"
                                                    x-text="` — ${new Date(project.end_datetime).toLocaleString()}`"
                                                ></span>
                                            </li>
                                        </template>
                                    </ol>
                                </div>
                            </template>

                            <template x-if="getStructured(message).scheduled_tasks && getStructured(message).scheduled_tasks.length">
                                <div class="space-y-1">
                                    <p class="text-[11px] font-medium text-muted-foreground">
                                        {{ __('Scheduled tasks') }}
                                    </p>
                                    <ul class="list-disc pl-4 space-y-0.5">
                                        <template
                                            x-for="item in getStructured(message).scheduled_tasks"
                                            :key="item.title"
                                        >
                                            <li class="text-[11px]">
                                                <span x-text="item.title"></span>
                                                <span
                                                    x-show="item.start_datetime || item.end_datetime"
                                                    class="text-[11px] text-muted-foreground"
                                                    x-text="[
                                                        item.start_datetime ? new Date(item.start_datetime).toLocaleString() : null,
                                                        item.end_datetime ? new Date(item.end_datetime).toLocaleString() : null,
                                                    ].filter(Boolean).join(' → ')"
                                                ></span>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                            </template>

                            <template x-if="getStructured(message).scheduled_events && getStructured(message).scheduled_events.length">
                                <div class="space-y-1">
                                    <p class="text-[11px] font-medium text-muted-foreground">
                                        {{ __('Scheduled events') }}
                                    </p>
                                    <ul class="list-disc pl-4 space-y-0.5">
                                        <template
                                            x-for="event in getStructured(message).scheduled_events"
                                            :key="event.title"
                                        >
                                            <li class="text-[11px]">
                                                <span x-text="event.title"></span>
                                                <span
                                                    x-show="event.start_datetime || event.end_datetime"
                                                    class="text-[11px] text-muted-foreground"
                                                    x-text="[
                                                        event.start_datetime ? new Date(event.start_datetime).toLocaleString() : null,
                                                        event.end_datetime ? new Date(event.end_datetime).toLocaleString() : null,
                                                    ].filter(Boolean).join(' → ')"
                                                ></span>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                            </template>

                            <template x-if="getStructured(message).scheduled_projects && getStructured(message).scheduled_projects.length">
                                <div class="space-y-1">
                                    <p class="text-[11px] font-medium text-muted-foreground">
                                        {{ __('Scheduled projects') }}
                                    </p>
                                    <ul class="list-disc pl-4 space-y-0.5">
                                        <template
                                            x-for="project in getStructured(message).scheduled_projects"
                                            :key="project.name"
                                        >
                                            <li class="text-[11px]">
                                                <span x-text="project.name"></span>
                                                <span
                                                    x-show="project.start_datetime || project.end_datetime"
                                                    class="text-[11px] text-muted-foreground"
                                                    x-text="[
                                                        project.start_datetime ? new Date(project.start_datetime).toLocaleString() : null,
                                                        project.end_datetime ? new Date(project.end_datetime).toLocaleString() : null,
                                                    ].filter(Boolean).join(' → ')"
                                                ></span>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                            </template>

                            <template x-if="getStructured(message).listed_items && getStructured(message).listed_items.length">
                                <div class="space-y-1">
                                    <p class="text-[11px] font-medium text-muted-foreground">
                                        {{ __('Suggested items') }}
                                    </p>
                                    <ul class="list-disc pl-4 space-y-0.5">
                                        <template
                                            x-for="item in getStructured(message).listed_items"
                                            :key="item.title"
                                        >
                                            <li class="text-[11px]">
                                                <span x-text="item.title"></span>
                                                <span
                                                    x-show="item.priority"
                                                    class="ml-1 inline-flex rounded-full bg-emerald-500/10 px-1.5 py-0.5 text-[10px] uppercase tracking-tight text-emerald-700 dark:text-emerald-300"
                                                    x-text="item.priority"
                                                ></span>
                                                <span
                                                    x-show="item.end_datetime"
                                                    class="ml-1 text-[11px] text-muted-foreground"
                                                    x-text="new Date(item.end_datetime).toLocaleString()"
                                                ></span>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                            </template>

                            <template x-if="getStructured(message).next_steps && getStructured(message).next_steps.length">
                                <div class="space-y-1">
                                    <p class="text-[11px] font-medium text-muted-foreground">
                                        {{ __('Next steps') }}
                                    </p>
                                    <ol class="list-decimal pl-4 space-y-0.5">
                                        <template
                                            x-for="(step, index) in getStructured(message).next_steps"
                                            :key="`${index}-${step}`"
                                        >
                                            <li class="text-[11px]" x-text="step"></li>
                                        </template>
                                    </ol>
                                </div>
                            </template>

                            <div
                                x-show="
                                    getSnapshot(message).used_fallback
                                    || (
                                        typeof getSnapshot(message).validation_confidence === 'number'
                                        && getSnapshot(message).validation_confidence < 0.5
                                    )
                                "
                                x-cloak
                                class="mt-1 flex items-center gap-1.5"
                            >
                                <flux:icon name="information-circle" class="size-3 text-muted-foreground" />
                                <p class="text-[10px] text-muted-foreground">
                                    <span x-show="getSnapshot(message).used_fallback">
                                        {{ __('This suggestion used a fallback. Consider double-checking details.') }}
                                    </span>
                                    <span
                                        x-show="
                                            !getSnapshot(message).used_fallback
                                            && typeof getSnapshot(message).validation_confidence === 'number'
                                            && getSnapshot(message).validation_confidence < 0.5
                                        "
                                    >
                                        {{ __('Confidence is lower than usual. Check details before acting.') }}
                                    </span>
                                </p>
                            </div>

                            <div
                                x-show="contextEntityLabel(message) || contextIntentLabel(message)"
                                x-cloak
                                class="mt-1 flex flex-wrap items-center gap-1.5"
                            >
                                <span
                                    x-show="contextEntityLabel(message)"
                                    class="inline-flex items-center rounded-full bg-background/70 px-2 py-0.5 text-[10px] font-medium text-muted-foreground ring-1 ring-border/60"
                                    x-text="contextEntityLabel(message)"
                                ></span>
                                <span
                                    x-show="contextIntentLabel(message)"
                                    class="inline-flex items-center rounded-full bg-background/70 px-2 py-0.5 text-[10px] font-medium text-muted-foreground ring-1 ring-border/60"
                                    x-text="contextIntentLabel(message)"
                                ></span>
                                <span
                                    x-show="isActionableIntent(message)"
                                    class="inline-flex items-center rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-medium text-emerald-700 ring-1 ring-emerald-500/40 dark:text-emerald-300"
                                >
                                    {{ __('Actionable') }}
                                </span>
                            </div>
                        </div>
                    </template>
                    </div>
                </div>
            </div>
        </template>

        <div
            x-show="isSending || pendingAssistantCount > 0"
            x-cloak
            class="flex w-full justify-start"
            aria-live="polite"
            aria-busy="true"
        >
            <div class="max-w-[80%] rounded-lg bg-muted px-3 py-2 text-xs text-foreground">
                <div class="flex items-center gap-2">
                    <flux:icon name="arrow-path" class="size-3.5 animate-spin text-muted-foreground" />
                    <span>{{ __('The assistant is reviewing everything and preparing a recommendation…') }}</span>
                    <flux:button
                        type="button"
                        size="xs"
                        variant="ghost"
                        class="ml-1 inline-flex items-center gap-1 text-[10px] text-muted-foreground hover:text-foreground px-1.5 py-0.5"
                        @click="cancelPending()"
                    >
                        <flux:icon name="stop-circle" class="size-3" />
                        <span>{{ __('Stop') }}</span>
                    </flux:button>
                </div>
            </div>
        </div>

    </div>

    <div
        x-show="showJumpToLatest"
        x-cloak
        class="flex justify-center px-4 pb-1"
    >
        <button
            type="button"
            class="inline-flex items-center gap-1 rounded-full bg-zinc-900/90 px-3 py-1 text-[11px] font-medium text-zinc-50 shadow-lg ring-1 ring-black/30 dark:bg-zinc-100 dark:text-zinc-900 dark:ring-white/40"
            @click="scrollToBottom(true)"
        >
            <flux:icon name="chevron-down" class="size-3" />
            <span>{{ __('Jump to latest') }}</span>
        </button>
    </div>

    <div class="border-t border-border/60 px-3 py-2">
        <div class="flex flex-col gap-1.5">
            <div
                x-show="followupSuggestions().length > 0 && !isSending && pendingAssistantCount === 0"
                x-cloak
                class="mb-1.5 rounded-md bg-emerald-500/5 px-2.5 py-2 ring-1 ring-emerald-500/40 dark:bg-emerald-500/10"
            >
                <div class="mb-1 flex items-center gap-1.5 text-[11px] font-medium text-emerald-800 dark:text-emerald-100">
                    <flux:icon name="sparkles" class="size-3.5 text-emerald-600 dark:text-emerald-300" />
                    <span>{{ __('Follow-up suggestions') }}</span>
                </div>

                <div class="flex flex-wrap items-center gap-1.5">
                    <template x-for="item in followupSuggestions()" :key="item.prompt">
                        <flux:button
                            type="button"
                            size="xs"
                            variant="outline"
                            class="text-[11px]! px-2.5 py-1! whitespace-normal text-left cursor-pointer border-emerald-500/60 text-emerald-800 hover:bg-emerald-500/10 dark:text-emerald-100 dark:border-emerald-400/70"
                            x-bind:disabled="isRateLimited || isSending || pendingAssistantCount > 0"
                            @click="submitPrompt(item.prompt)"
                        >
                            <span class="inline-flex items-center gap-1">
                                <flux:icon name="arrow-up" class="size-3 text-emerald-600 dark:text-emerald-300" />
                                <span x-text="item.prompt"></span>
                            </span>
                        </flux:button>
                    </template>
                </div>
            </div>

            <div
                x-show="errorMessage"
                x-cloak
                class="mb-1 flex items-center gap-1.5 rounded-md bg-red-500/5 px-2 py-1 text-[11px] text-red-600 dark:text-red-400"
            >
                <flux:icon name="exclamation-triangle" class="size-3.5" />
                <p x-text="errorMessage"></p>
            </div>

            <div class="flex items-center gap-2">
                <flux:textarea
                    x-ref="input"
                    x-model="input"
                    rows="2"
                    class="flex-1 resize-none text-xs! max-h-24"
                    style="resize: none;"
                    placeholder="{{ __('Ask about your tasks, events, or projects…') }}"
                    x-bind:disabled="isRateLimited || isSending || pendingAssistantCount > 0"
                    @keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); submit(); }"
                    @input="resizeInput()"
                />

                <flux:button
                    type="button"
                    size="sm"
                    variant="primary"
                    class="shrink-0 inline-flex h-9 w-9 items-center justify-center rounded-full p-0"
                    x-bind:disabled="isRateLimited || isSending || pendingAssistantCount > 0 || !input.trim()"
                    aria-label="{{ __('Send') }}"
                    @click="submit()"
                >
                    <flux:icon name="arrow-up" class="size-4" />
                </flux:button>
            </div>

            <div class="mt-2 flex items-center justify-start">
                <flux:tooltip :content="__('Start a new conversation')">
                    <flux:button
                        type="button"
                        size="xs"
                        variant="ghost"
                        icon="plus"
                        class="inline-flex h-7 px-2 items-center gap-1 rounded-full text-[11px]"
                        @click="startNewChat()"
                        aria-label="{{ __('New chat') }}"
                    >
                        <span>{{ __('New chat') }}</span>
                    </flux:button>
                </flux:tooltip>
            </div>

        </div>
    </div>
</div>

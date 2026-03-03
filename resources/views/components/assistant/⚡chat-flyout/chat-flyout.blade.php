
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
        suggestedPrompts: [
            {{ json_encode(__('What should I focus on today?')) }},
            {{ json_encode(__('Show my tasks with no due date.')) }},
            {{ json_encode(__('Help me plan study time for my exam.')) }},
            {{ json_encode(__('Which tasks can I drop if I’m overwhelmed?')) }},
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
            const intent = snap.intent || msg.metadata.intent || null;
            const entityType = snap.entity_type || msg.metadata.entity_type || null;

            const out = [];

            if (!intent) return out;

            if (intent === 'prioritize_tasks') {
                out.push({ prompt: '{{ __('Schedule the top task for today.') }}' });
                out.push({ prompt: '{{ __('Show my tasks with no due date.') }}' });
            } else if (intent === 'prioritize_events') {
                out.push({ prompt: '{{ __('Which events should I focus on this week?') }}' });
            } else if (intent === 'prioritize_projects') {
                out.push({ prompt: '{{ __('Help me break my top project into smaller steps.') }}' });
            } else if (intent === 'schedule_task' || intent === 'adjust_task_deadline') {
                out.push({ prompt: '{{ __('Can you suggest another time slot for this task?') }}' });
            } else if (intent === 'schedule_event' || intent === 'adjust_event_time') {
                out.push({ prompt: '{{ __('Suggest a different time for this event.') }}' });
            } else if (intent === 'schedule_project' || intent === 'adjust_project_timeline') {
                out.push({ prompt: '{{ __('Help me plan milestones for this project.') }}' });
            } else if (intent === 'resolve_dependency') {
                out.push({ prompt: '{{ __('Show me which tasks are still blocked.') }}' });
            } else if (intent === 'general_query') {
                out.push({ prompt: '{{ __('What should I focus on next?') }}' });
            }

            return out;
        },
        getSnapshot(message) {
            if (!message || !message.metadata) return {};
            return message.metadata.recommendation_snapshot || {};
        },
        getStructured(message) {
            const snap = this.getSnapshot(message);
            return snap.structured || {};
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

                    this.$nextTick(() => {
                        this.scrollToBottom(true);
                    });
                });

            this._subscribedThreadId = threadId;
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
                } else if (message && message.role === 'assistant') {
                    this.messages = [...this.messages, message];

                    const snap = (message.metadata && message.metadata.recommendation_snapshot) || {};
                    if (snap.reasoning === 'rate_limited') {
                        this.isRateLimited = true;
                    } else if (snap.reasoning && snap.reasoning !== 'rate_limited') {
                        this.isRateLimited = false;
                    }
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
            if ($refs.input) {
                resizeInput();
            }
        })
    "
>
    <div class="border-b border-border/60 px-4 py-3">
        <div class="flex items-center gap-2">
            <div class="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                <flux:icon name="sparkles" class="size-4" />
            </div>
            <div class="min-w-0">
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
                        {{ __('Ask about your work') }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ __('Ask about tasks, events, or projects. The assistant uses your TaskLyst data to respond.') }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center justify-center gap-2 max-w-sm">
                    <template x-for="prompt in suggestedPrompts" :key="prompt">
                        <flux:button
                            type="button"
                            size="xs"
                            variant="ghost"
                            class="text-[11px]! px-2.5 py-1! whitespace-normal text-left"
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
                <div
                    class="max-w-[80%] rounded-lg px-3 py-2 text-xs leading-relaxed"
                    :class="message.role === 'user'
                        ? 'bg-emerald-500 text-white dark:bg-emerald-500'
                        : 'bg-muted text-foreground'"
                >
                    <p class="whitespace-pre-wrap" x-text="message.content"></p>

                    <template x-if="message.role === 'assistant'">
                        <div class="mt-2 space-y-2">
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
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </template>

        <div
            x-show="pendingAssistantCount > 0"
            x-cloak
            class="flex w-full justify-start"
            aria-live="polite"
            aria-busy="true"
        >
            <div class="max-w-[80%] rounded-lg bg-muted px-3 py-2 text-xs text-foreground">
                <div class="flex items-center gap-2">
                    <flux:icon name="arrow-path" class="size-3.5 animate-spin text-muted-foreground" />
                    <span>{{ __('Thinking…') }}</span>
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

            <div
                x-show="followupSuggestions().length > 0"
                x-cloak
                class="mt-1 flex flex-wrap items-center gap-1.5"
            >
                <template x-for="item in followupSuggestions()" :key="item.prompt">
                    <flux:button
                        type="button"
                        size="xs"
                        variant="ghost"
                        class="text-[11px]! px-2.5 py-1! whitespace-normal text-left"
                        @click="usePrompt(item.prompt)"
                    >
                        <span x-text="item.prompt"></span>
                    </flux:button>
                </template>
            </div>

        </div>
    </div>
</div>

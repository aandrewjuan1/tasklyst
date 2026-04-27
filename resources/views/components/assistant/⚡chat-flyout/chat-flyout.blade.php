<div
    class="relative isolate grid h-full min-h-[min(400px,80dvh)] grid-rows-[auto_1fr_auto] overflow-hidden rounded-xl bg-linear-to-r from-brand-light-blue via-white to-white text-zinc-900 shadow-sm ring-1 ring-black/5 dark:bg-zinc-900 dark:text-zinc-100 dark:ring-white/5"
    x-data="{
        loadingPhrases: [
            @js(__('Thinking through this for you...')),
            @js(__('Looking at your context...')),
            @js(__('Putting together a helpful reply...')),
            @js(__('Making sure this is useful...')),
        ],
        loadingPhraseIndex: 0,
        loadingTimer: null,
        streamingTimeoutPollTimer: null,
        streamingFallbackPollTimer: null,
        streamingFallbackStartedAtMs: null,
        pageVisibilityListener: null,
        fallbackPollInitialMs: @js(max(250, (int) config('task-assistant.streaming.fallback_poll_initial_ms', 2000))),
        fallbackPollMidMs: @js(max(250, (int) config('task-assistant.streaming.fallback_poll_mid_ms', 3500))),
        fallbackPollSlowMs: @js(max(250, (int) config('task-assistant.streaming.fallback_poll_slow_ms', 5000))),
        fallbackPollMidAfterMs: @js(max(1000, (int) config('task-assistant.streaming.fallback_poll_mid_after_ms', 10000))),
        fallbackPollSlowAfterMs: @js(max(2000, (int) config('task-assistant.streaming.fallback_poll_slow_after_ms', 25000))),
        timeoutPollMs: @js(max(1000, (int) config('task-assistant.streaming.timeout_poll_ms', 10000))),
        expectsRealtimeBroadcast: @js($expectsRealtimeBroadcast),
        scrollQueued: false,
        pendingScrollBehavior: 'smooth',
        wasStreaming: false,
        scrollStateRaf: null,
        visibleScrollRetryTimer: null,
        flyoutVisibilityObserver: null,
        allowAutoScrollOnStreamEnd: true,
        noRealtimeBroadcast: false,
        currentLoadingPhrase() {
            return this.loadingPhrases[this.loadingPhraseIndex] ?? this.loadingPhrases[0];
        },
        startLoadingPhraseRotation() {
            if (this.loadingTimer) {
                clearInterval(this.loadingTimer);
            }

            this.loadingPhraseIndex = 0;
            this.loadingTimer = setInterval(() => {
                this.loadingPhraseIndex = (this.loadingPhraseIndex + 1) % this.loadingPhrases.length;
            }, 1500);
        },
        stopLoadingPhraseRotation() {
            if (this.loadingTimer) {
                clearInterval(this.loadingTimer);
                this.loadingTimer = null;
            }

            this.loadingPhraseIndex = 0;
        },
        startStreamingTimeoutPolling() {
            if (this.streamingTimeoutPollTimer) {
                return;
            }
            this.queueStreamingTimeoutPoll();
        },
        queueStreamingTimeoutPoll() {
            this.streamingTimeoutPollTimer = setTimeout(() => {
                this.streamingTimeoutPollTimer = null;
                if (!this.$wire.isStreaming) {
                    return;
                }
                if (document.visibilityState !== 'visible') {
                    this.queueStreamingTimeoutPoll();

                    return;
                }
                this.$wire.checkStreamingTimeout();
                this.queueStreamingTimeoutPoll();
            }, this.timeoutPollMs);
        },
        stopStreamingTimeoutPolling() {
            if (this.streamingTimeoutPollTimer) {
                clearTimeout(this.streamingTimeoutPollTimer);
                this.streamingTimeoutPollTimer = null;
            }
        },
        detectRealtimeBroadcastAvailability() {
            if (!this.expectsRealtimeBroadcast) {
                this.noRealtimeBroadcast = true;

                return;
            }

            this.noRealtimeBroadcast = typeof window.Echo === 'undefined' || !window.Echo;
        },
        startStreamingFallbackPolling() {
            if (!this.noRealtimeBroadcast || this.streamingFallbackPollTimer) {
                return;
            }
            this.streamingFallbackStartedAtMs = Date.now();
            this.queueStreamingFallbackPoll();
        },
        getStreamingFallbackPollIntervalMs() {
            const startedAtMs = this.streamingFallbackStartedAtMs ?? Date.now();
            const elapsedMs = Math.max(0, Date.now() - startedAtMs);
            if (elapsedMs >= this.fallbackPollSlowAfterMs) {
                return this.fallbackPollSlowMs;
            }
            if (elapsedMs >= this.fallbackPollMidAfterMs) {
                return this.fallbackPollMidMs;
            }

            return this.fallbackPollInitialMs;
        },
        queueStreamingFallbackPoll() {
            const intervalMs = this.getStreamingFallbackPollIntervalMs();
            this.streamingFallbackPollTimer = setTimeout(() => {
                this.streamingFallbackPollTimer = null;
                if (!this.$wire.isStreaming) {
                    return;
                }
                if (document.visibilityState !== 'visible') {
                    this.queueStreamingFallbackPoll();

                    return;
                }
                this.$wire.pollStreamingFallback();
                this.queueStreamingFallbackPoll();
            }, intervalMs);
        },
        stopStreamingFallbackPolling() {
            if (this.streamingFallbackPollTimer) {
                clearTimeout(this.streamingFallbackPollTimer);
                this.streamingFallbackPollTimer = null;
            }
            this.streamingFallbackStartedAtMs = null;
        },
        registerVisibilityPollingListener() {
            if (this.pageVisibilityListener) {
                return;
            }
            this.pageVisibilityListener = () => {
                if (document.visibilityState !== 'visible' || !this.$wire.isStreaming) {
                    return;
                }
                if (this.noRealtimeBroadcast && !this.streamingFallbackPollTimer) {
                    this.queueStreamingFallbackPoll();
                }
                if (!this.streamingTimeoutPollTimer) {
                    this.queueStreamingTimeoutPoll();
                }
            };
            document.addEventListener('visibilitychange', this.pageVisibilityListener);
        },
        unregisterVisibilityPollingListener() {
            if (!this.pageVisibilityListener) {
                return;
            }
            document.removeEventListener('visibilitychange', this.pageVisibilityListener);
            this.pageVisibilityListener = null;
        },
        isNearBottom(thresholdPx = 80) {
            const container = this.$refs.messagesContainer ?? null;
            if (!container) {
                return true;
            }

            const remaining = container.scrollHeight - container.scrollTop - container.clientHeight;
            return remaining <= thresholdPx;
        },
        queueScrollToBottom(behavior = 'smooth') {
            if (this.scrollQueued) {
                this.pendingScrollBehavior = behavior;

                return;
            }

            this.scrollQueued = true;
            this.pendingScrollBehavior = behavior;

            requestAnimationFrame(() => {
                const end = this.$refs.messagesEnd ?? null;
                if (!end) {
                    this.scrollQueued = false;

                    return;
                }

                end.scrollIntoView({ behavior: this.pendingScrollBehavior, block: 'end' });
                this.scrollQueued = false;
            });
        },
        queueScrollToBottomWhenVisible(behavior = 'auto', retries = 16) {
            if (this.visibleScrollRetryTimer) {
                clearTimeout(this.visibleScrollRetryTimer);
                this.visibleScrollRetryTimer = null;
            }

            this.$nextTick(() => {
                const container = this.$refs.messagesContainer ?? null;
                const end = this.$refs.messagesEnd ?? null;
                if (!container || !end) {
                    return;
                }

                const isVisible = container.clientHeight > 0 && container.getClientRects().length > 0;
                if (isVisible) {
                    this.queueScrollToBottom(behavior);

                    return;
                }

                if (retries <= 0) {
                    this.queueScrollToBottom('auto');

                    return;
                }

                this.visibleScrollRetryTimer = setTimeout(() => {
                    this.visibleScrollRetryTimer = null;
                    this.queueScrollToBottomWhenVisible(behavior, retries - 1);
                }, 50);
            });
        },
        registerFlyoutVisibilityObserver() {
            if (this.flyoutVisibilityObserver || typeof IntersectionObserver === 'undefined') {
                return;
            }

            const container = this.$refs.messagesContainer ?? null;
            if (!container) {
                return;
            }

            this.flyoutVisibilityObserver = new IntersectionObserver((entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    this.queueScrollToBottomWhenVisible('auto');
                }
            }, { threshold: 0.05 });

            this.flyoutVisibilityObserver.observe(container);
        },
        unregisterFlyoutVisibilityObserver() {
            if (!this.flyoutVisibilityObserver) {
                return;
            }

            this.flyoutVisibilityObserver.disconnect();
            this.flyoutVisibilityObserver = null;
        },
        handleMessagesScroll() {
            if (this.scrollStateRaf) {
                return;
            }

            this.scrollStateRaf = requestAnimationFrame(() => {
                this.scrollStateRaf = null;

                if (this.$wire.isStreaming && !this.isNearBottom(180)) {
                    this.allowAutoScrollOnStreamEnd = false;
                }
            });
        },
        init() {
            this.detectRealtimeBroadcastAvailability();
            this.registerVisibilityPollingListener();
            this.$nextTick(() => {
                this.registerFlyoutVisibilityObserver();
                this.queueScrollToBottomWhenVisible('auto');
            });
            this.wasStreaming = !! this.$wire.isStreaming;
            this.allowAutoScrollOnStreamEnd = this.isNearBottom(180);
            this.$watch('$wire.streamingContent', () => {
                // Streaming content updates frequently; only auto-scroll when the user is near the bottom.
                if (this.isNearBottom()) {
                    this.queueScrollToBottom('auto');
                }
            });
            this.$watch(() => ($wire.chatMessages?.length ?? 0), () => {
                if (this.isNearBottom(140)) {
                    this.queueScrollToBottom('smooth');
                }
            });
            this.$watch('$wire.isStreaming', (value) => {
                const wasStreaming = this.wasStreaming;
                this.wasStreaming = !! value;

                if (value) {
                    this.allowAutoScrollOnStreamEnd = this.isNearBottom(180);
                    this.startLoadingPhraseRotation();
                    this.startStreamingTimeoutPolling();
                    this.startStreamingFallbackPolling();
                } else {
                    this.stopLoadingPhraseRotation();
                    this.stopStreamingTimeoutPolling();
                    this.stopStreamingFallbackPolling();

                    // Stream just finished; only snap if user did not scroll away while waiting.
                    if (wasStreaming && this.allowAutoScrollOnStreamEnd) {
                        this.queueScrollToBottom('smooth');
                    }
                }
            });

            if (this.$wire.isStreaming) {
                this.startLoadingPhraseRotation();
                this.startStreamingTimeoutPolling();
                this.startStreamingFallbackPolling();
            }
        },
        destroy() {
            this.stopLoadingPhraseRotation();
            this.stopStreamingTimeoutPolling();
            this.stopStreamingFallbackPolling();
            this.unregisterVisibilityPollingListener();
            this.unregisterFlyoutVisibilityObserver();
            if (this.visibleScrollRetryTimer) {
                clearTimeout(this.visibleScrollRetryTimer);
                this.visibleScrollRetryTimer = null;
            }
            if (this.scrollStateRaf) {
                cancelAnimationFrame(this.scrollStateRaf);
                this.scrollStateRaf = null;
            }
        },
    }"
>
    <div class="relative z-20 flex shrink-0 items-start gap-3 overflow-visible border-b border-border/60 px-4 py-3 dark:border-zinc-800">
        <div class="flex size-10 shrink-0 items-center justify-center rounded-2xl border border-brand-blue/20 bg-white text-brand-blue shadow-sm dark:border-brand-blue/30 dark:bg-zinc-900/20 dark:text-brand-light-blue">
            <x-icons.assistant-robot class="size-5 text-brand-navy-blue dark:text-brand-light-blue" title="" />
        </div>
        <div class="min-w-0 flex-1 pt-0.5">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <div class="inline-flex items-center gap-x-1.5">
                    <flux:text class="block text-xs font-semibold uppercase leading-none tracking-[0.12em] text-brand-blue dark:text-brand-light-blue">
                        {{ __('taskLyst assistant') }}
                    </flux:text>

                    <flux:modal.trigger name="task-assistant-help">
                        <button
                            type="button"
                            aria-label="{{ __('How this assistant works') }}"
                            class="inline-flex shrink-0 items-center justify-center rounded-md p-px text-zinc-500 transition-colors hover:text-zinc-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/35 focus-visible:ring-offset-1 dark:text-zinc-400 dark:hover:text-zinc-200 dark:focus-visible:ring-offset-zinc-900"
                        >
                            <flux:icon name="information-circle" class="size-4" />
                        </button>
                    </flux:modal.trigger>
                </div>

                <flux:modal
                    name="task-assistant-help"
                    scroll="body"
                    class="w-full max-w-lg"
                >
                    <div class="space-y-2.5 text-sm leading-snug text-zinc-700 dark:text-zinc-300">
                            <div>
                                <p class="font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ __('Where the model runs') }}
                                </p>
                                <p class="mt-1">
                                    {{ __('This assistant uses Hermes 3:3b, a compact model running on infrastructure we control. Requests stay inside taskLyst\'s managed environment and are not sent to public AI tools. Because it runs on limited local resources, responses may take some time to generate. As a smaller model, outputs may also be less nuanced than larger cloud-based assistants.') }}
                                </p>
                            </div>
                            <div>
                                <p class="font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ __('How your data is used') }}
                                </p>
                                <p class="mt-1">
                                    {{ __('The assistant only uses the information needed to answer your request, such as relevant tasks, schedules, and timing details from your workspace. Data is used to generate the current response and improve in-app assistance quality for your account experience. It does not browse unrelated personal data, and it cannot access external apps unless taskLyst is explicitly connected to them.') }}
                                </p>
                            </div>
                            <div>
                                <p class="font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ __('How we keep it safe') }}
                                </p>
                                <p class="mt-1">
                                    {{ __('We apply access controls and secure handling in our systems so assistant processing stays protected. You stay in control: review AI suggestions before acting on them, and avoid sharing highly sensitive information in prompts when it is not needed.') }}
                                </p>
                            </div>
                            <div>
                                <p class="font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ __('What it is for') }}
                                </p>
                                <p class="mt-1">
                                    {{ __('It helps with prioritization, scheduling, and planning using your tasks and calendar data in taskLyst. You\'ll get ranked suggestions, proposed time blocks, and quick follow-up prompts to refine results. It is not a general-purpose chatbot, so unrelated or vague prompts may return limited guidance.') }}
                                </p>
                            </div>
                            <div>
                                <p class="font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ __('How to use it well') }}
                                </p>
                                <p class="mt-1">
                                    {{ __('Send one clear goal per message and include a timeframe when relevant, such as "today" or "this week." This helps the assistant generate more accurate priorities and schedules. You can refine outputs using follow-ups or suggested chips, like adjusting time blocks or narrowing the scope.') }}
                                </p>
                            </div>
                            <div>
                                <p class="font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ __('Limitations') }}
                                </p>
                                <p class="mt-1">
                                    {{ __('It only supports task-related use and cannot browse the web or access data outside taskLyst. Smaller models may occasionally misinterpret wording, counts, or dates, so double-check important details. Responses are generated on the server and may take a moment to complete after you send a message.') }}
                                </p>
                            </div>
                    </div>
                </flux:modal>
            </div>
            <flux:heading size="md">{{ __('Plan, prioritize, and organize faster') }}</flux:heading>
            <div class="mt-1">
                <a
                    href="{{ route('settings.preference') }}"
                    wire:navigate
                    class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-blue transition hover:text-brand-navy-blue dark:text-brand-light-blue dark:hover:text-brand-blue"
                    data-testid="assistant-workflow-settings-link"
                >
                    <flux:icon name="adjustments-horizontal" class="size-3.5" />
                    <span>{{ __('Using your workflow preferences') }}</span>
                </a>
            </div>
        </div>
    </div>

    <div
        class="relative z-10 flex min-h-0 flex-col gap-4 overflow-y-auto p-4"
        wire:key="messages-container"
        x-ref="messagesContainer"
        x-on:scroll.passive="handleMessagesScroll()"
        x-on:assistant-chat-open-requested.window="queueScrollToBottomWhenVisible('auto')"
    >
        @if ($chatMessages->isEmpty() && ! $isStreaming)
            <div class="flex flex-1 flex-col items-center justify-center gap-4 text-center">
                <div class="max-w-[24rem]">
                    <flux:text class="block text-lg font-semibold tracking-tight text-zinc-900 dark:text-zinc-100">
                        {{ __('Send a message to get started') }}
                    </flux:text>
                    <flux:text class="mt-2 block text-base leading-relaxed text-zinc-700 dark:text-zinc-300">
                        {{ __('You can ask to prioritize tasks, create a plan, or schedule focus blocks.') }}
                    </flux:text>
                </div>

                <div class="flex max-w-md flex-wrap items-center justify-center gap-2.5">
                    @foreach ($emptyStateQuickChips as $chipText)
                        <flux:button
                            type="button"
                            size="sm"
                            variant="ghost"
                            class="rounded-full border border-brand-blue/18 bg-white/90 px-3 py-1 text-sm font-medium text-black shadow-sm transition-colors hover:border-brand-blue/30 hover:bg-brand-light-blue/70 hover:text-black focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/40 focus-visible:ring-offset-1 dark:border-brand-blue/30 dark:bg-zinc-900/30 dark:text-black dark:hover:bg-zinc-800/50 dark:hover:text-black dark:focus-visible:ring-offset-zinc-900 disabled:pointer-events-none disabled:opacity-60"
                            x-on:click.prevent="$dispatch('quick-prompt', { value: $event.currentTarget.textContent.trim() })"
                        >
                            {{ $chipText }}
                        </flux:button>
                    @endforeach
                </div>
            </div>
        @else
            @foreach ($chatMessages as $message)
                @if ($message->role->value === 'user')
                    <div
                        wire:key="message-{{ $message->id }}"
                        class="flex justify-end"
                    >
                        <div class="max-w-[85%] min-w-0 rounded-xl border border-brand-blue/20 bg-brand-blue/15 px-3 py-2 shadow-sm ring-1 ring-brand-blue/10 dark:border-brand-blue/30 dark:bg-brand-blue/20 dark:ring-white/5">
                            <flux:text class="wrap-break-word text-base text-black dark:text-black">{{ $message->content }}</flux:text>
                        </div>
                    </div>
                @elseif ($message->role->value === 'assistant' && $message->id !== $streamingMessageId)
                    <div
                        wire:key="message-{{ $message->id }}"
                        class="flex justify-start"
                    >
                        <div class="flex max-w-[85%] items-start gap-3">
                            <div class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-xl border border-brand-blue/20 bg-white text-brand-blue shadow-sm dark:border-brand-blue/30 dark:bg-zinc-900/20 dark:text-brand-light-blue">
                                <x-icons.assistant-robot class="size-4 text-brand-navy-blue dark:text-brand-light-blue" title="" />
                            </div>
                                <div class="min-w-0 flex-1 rounded-xl border border-border/60 bg-muted/30 px-3 py-2 shadow-sm ring-1 ring-black/5 dark:border-border/60 dark:bg-muted/15 dark:ring-white/5">
                            @php
                                $isStopped = data_get($message->metadata, 'stream.status') === 'stopped';
                                // Always display formatted content - ResponseProcessor ensures all messages are student-friendly
                                $display = $isStopped ? '' : ($message->content ?: __('…'));
                                $proposals = data_get($message->metadata, 'schedule.proposals', data_get($message->metadata, 'daily_schedule.proposals', data_get($message->metadata, 'structured.data.proposals', [])));
                                $scheduleConfirmationRequired = (bool) data_get($message->metadata, 'schedule.confirmation_required', data_get($message->metadata, 'structured.data.confirmation_required', false));
                                $scheduleAwaitingDecision = (bool) data_get($message->metadata, 'schedule.awaiting_user_decision', data_get($message->metadata, 'structured.data.awaiting_user_decision', false));
                                $hideScheduleProposalCards = $scheduleConfirmationRequired || $scheduleAwaitingDecision;
                                $fallbackOptionActions = data_get($message->metadata, 'schedule.confirmation_context.option_actions', []);
                                if (! is_array($fallbackOptionActions)) {
                                    $fallbackOptionActions = [];
                                }
                                $fallbackOptionChips = array_values(array_filter(array_map(
                                    static fn (mixed $option): string => is_array($option) ? trim((string) ($option['label'] ?? '')) : '',
                                    $fallbackOptionActions
                                ), static fn (string $label): bool => $label !== ''));
                                $prioritizeChips = data_get($message->metadata, 'prioritize.next_options_chip_texts', []);
                                $guidanceChips = data_get($message->metadata, 'general_guidance.next_options_chip_texts', []);
                                $scheduleChips = data_get($message->metadata, 'schedule.next_options_chip_texts', []);
                                $listingFollowupChips = data_get($message->metadata, 'listing_followup.next_options_chip_texts', []);
                                $structuredChips = data_get($message->metadata, 'structured.data.next_options_chip_texts', []);
                                $nextOptionChips = count($fallbackOptionChips) > 0
                                    ? $fallbackOptionChips
                                    : (is_array($prioritizeChips) && count($prioritizeChips) > 0
                                    ? $prioritizeChips
                                    : (is_array($guidanceChips) && count($guidanceChips) > 0
                                        ? $guidanceChips
                                        : (is_array($scheduleChips) && count($scheduleChips) > 0
                                            ? $scheduleChips
                                            : (is_array($listingFollowupChips) && count($listingFollowupChips) > 0
                                                ? $listingFollowupChips
                                                : (is_array($structuredChips) ? $structuredChips : [])))));
                                if (! is_array($nextOptionChips)) {
                                    $nextOptionChips = [];
                                }
                                $nextOptionChips = array_values(array_filter(
                                    array_map(static fn (mixed $chip): string => trim((string) $chip), $nextOptionChips),
                                    static fn (string $chip): bool => $chip !== ''
                                ));
                                if (count($fallbackOptionChips) === 0) {
                                    $nextOptionChips = $this->filterContinueStyleQuickChips($nextOptionChips);
                                }
                                $isLatestAssistant = $latestAssistantMessageId !== null && $message->id === $latestAssistantMessageId;
                                $chipsDismissed = (bool) ($dismissedNextOptionChipsByMessage[$message->id] ?? false);
                            @endphp
                            @if ($isStopped)
                                <div class="min-w-0">
                                    <flux:text class="block text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                        {{ __('Response stopped') }}
                                    </flux:text>
                                    <flux:text class="mt-0.5 block text-xs leading-relaxed text-zinc-600 dark:text-zinc-400">
                                        {{ __('Generation was stopped before the reply finished. You can send a new message whenever you’re ready.') }}
                                    </flux:text>
                                </div>
                            @endif
                            @if ($display !== '')
                                <flux:text class="wrap-break-word whitespace-pre-wrap text-base text-black dark:text-black">{{ $display }}</flux:text>
                            @endif

                            @if (! $hideScheduleProposalCards && is_array($proposals) && count($proposals) > 0)
                                @php
                                    $pendingSchedulableCount = 0;
                                    foreach ($proposals as $p) {
                                        if (! is_array($p)) {
                                            continue;
                                        }
                                        if (\App\Support\LLM\SchedulableProposalPolicy::isPendingSchedulable($p)) {
                                            $pendingSchedulableCount++;
                                        }
                                    }
                                    $showAcceptAll = $isLatestAssistant
                                        && ! $isStopped
                                        && $pendingSchedulableCount > 0;
                                @endphp
                                <div class="mt-3 flex flex-col gap-2">
                                    @foreach ($proposals as $proposal)
                                        @if (is_array($proposal))
                                            @php
                                                $proposalId = (string) ($proposal['proposal_uuid'] ?? $proposal['proposal_id'] ?? '');
                                                $status = (string) ($proposal['status'] ?? 'pending');
                                                $startAt = (string) ($proposal['start_datetime'] ?? '');
                                                $endAt = (string) ($proposal['end_datetime'] ?? '');
                                                $title = (string) ($proposal['title'] ?? 'Scheduled item');
                                                $statusLabel = \Illuminate\Support\Str::headline($status);
                                                $statusClass = match ($status) {
                                                    'accepted' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
                                                    'failed' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
                                                    'declined' => 'bg-zinc-200 text-zinc-700 dark:bg-zinc-700/60 dark:text-zinc-300',
                                                    default => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
                                                };

                                                $start = null;
                                                $end = null;
                                                if ($startAt !== '') {
                                                    try {
                                                        $start = \Carbon\CarbonImmutable::parse($startAt);
                                                    } catch (\Throwable) {
                                                        $start = null;
                                                    }
                                                }
                                                if ($endAt !== '') {
                                                    try {
                                                        $end = \Carbon\CarbonImmutable::parse($endAt);
                                                    } catch (\Throwable) {
                                                        $end = null;
                                                    }
                                                }

                                                $timeLabel = '';
                                                if ($start instanceof \Carbon\CarbonImmutable && $end instanceof \Carbon\CarbonImmutable) {
                                                    $sameDay = $start->isSameDay($end);
                                                    if ($sameDay) {
                                                        $timeLabel = $start->format('M j, Y').' · '.$start->format('g:i A').'–'.$end->format('g:i A');
                                                    } else {
                                                        $timeLabel = $start->format('M j, Y').' · '.$start->format('g:i A').'–'.$end->format('M j, Y').' · '.$end->format('g:i A');
                                                    }
                                                } elseif ($start instanceof \Carbon\CarbonImmutable) {
                                                    $timeLabel = $start->format('M j, Y').' · '.$start->format('g:i A');
                                                } else {
                                                    $timeLabel = $startAt;
                                                }
                                            @endphp
                                            <div class="rounded-lg border border-border/65 bg-white/90 px-2.5 py-2.5 shadow-sm ring-1 ring-black/5 dark:border-zinc-700/70 dark:bg-zinc-900/55 dark:ring-white/5">
                                                <div class="flex min-w-0 items-start justify-between gap-2">
                                                    <flux:text class="min-w-0 flex-1 text-sm font-semibold leading-tight text-zinc-900 dark:text-zinc-100">
                                                        {{ $title }}
                                                    </flux:text>
                                                    <span class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.06em] {{ $statusClass }}">
                                                        {{ $statusLabel }}
                                                    </span>
                                                </div>
                                                <div class="mt-2 rounded-md border border-brand-blue/20 bg-brand-light-blue/45 px-2 py-1.5 dark:border-brand-blue/35 dark:bg-brand-blue/15">
                                                    <div class="flex items-center gap-1.5">
                                                        <flux:icon name="calendar-days" class="size-3.5 shrink-0 text-brand-blue dark:text-brand-light-blue" />
                                                        <flux:text class="text-xs font-semibold text-brand-navy-blue dark:text-brand-light-blue">
                                                            {{ $timeLabel }}
                                                        </flux:text>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                                @if ($showAcceptAll)
                                    <div class="mt-3">
                                        <button
                                            type="button"
                                            wire:click="acceptAllScheduleProposals({{ $message->id }})"
                                            wire:loading.attr="disabled"
                                            class="inline-flex items-center gap-2 rounded-xl bg-brand-blue px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-blue/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/50 disabled:pointer-events-none disabled:opacity-70"
                                        >
                                            <flux:icon name="check" class="size-4" />
                                            {{ __('Accept all') }}
                                        </button>
                                    </div>
                                @endif
                            @endif

                            @if (! $isStopped && $isLatestAssistant && ! $chipsDismissed && count($nextOptionChips) > 0)
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach ($nextOptionChips as $chipIndex => $chipText)
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            class="rounded-full border border-brand-blue/18 bg-white/90 px-3.5 py-1.5 text-base font-medium text-black shadow-sm transition-colors hover:border-brand-blue/30 hover:bg-brand-light-blue/70 hover:text-black focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/40 focus-visible:ring-offset-1 dark:border-brand-blue/30 dark:bg-zinc-900/30 dark:text-black dark:hover:bg-zinc-800/50 dark:hover:text-black dark:focus-visible:ring-offset-zinc-900 disabled:pointer-events-none disabled:opacity-60"
                                            wire:click="submitNextOptionChip({{ $message->id }}, {{ $chipIndex }})"
                                            wire:loading.attr="disabled"
                                            wire:target="submitNextOptionChip,submitMessage"
                                        >
                                            <span class="inline-flex items-center gap-1.5 text-left leading-normal">
                                                <flux:icon name="chevron-right" class="size-3.5 shrink-0 self-center opacity-70" />
                                                <span>{{ $chipText }}</span>
                                            </span>
                                        </flux:button>
                                    @endforeach
                                </div>
                            @endif
                            </div>
                        </div>
                    </div>
                @endif
            @endforeach

            @if ($isStreaming && $streamingMessageId)
                <div
                    wire:key="streaming-assistant"
                    class="flex justify-start"
                >
                    <div class="flex max-w-[85%] items-start gap-3">
                        <div class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-xl border border-brand-blue/20 bg-white text-brand-blue shadow-sm dark:border-brand-blue/30 dark:bg-zinc-900/20 dark:text-brand-light-blue">
                            <x-icons.assistant-robot class="size-4 text-brand-navy-blue dark:text-brand-light-blue" title="" />
                        </div>
                        <div class="min-w-0 flex-1 overflow-visible rounded-xl border border-border/60 bg-white/85 px-3 py-3 shadow-sm ring-1 ring-black/5 dark:border-border/60 dark:bg-zinc-900/70 dark:ring-white/5">
                        <div
                            x-show="$wire.isStreaming && ($wire.streamingContent?.length ?? 0) === 0"
                            x-transition.opacity.duration.200ms
                            class="mb-2 space-y-2"
                        >
                            <div class="flex items-start gap-3">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <flux:text class="block text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                                {{ __('Task assistant') }}
                                            </flux:text>
                                            <flux:text class="mt-0.5 block text-sm text-zinc-700 dark:text-zinc-300" x-text="currentLoadingPhrase()">
                                                {{ __('Thinking through this for you...') }}
                                            </flux:text>
                                        </div>
                                        <button
                                            type="button"
                                            wire:click="requestStopStreaming"
                                            wire:loading.attr="disabled"
                                            wire:target="requestStopStreaming"
                                            class="inline-flex shrink-0 items-center rounded-lg border border-border/60 bg-muted/20 px-2.5 py-1 text-xs font-medium text-zinc-600 transition hover:bg-muted/40 hover:text-zinc-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/35 focus-visible:ring-offset-1 dark:border-border/70 dark:bg-muted/10 dark:text-zinc-300 dark:hover:bg-muted/20 dark:hover:text-zinc-100"
                                            aria-label="{{ __('Cancel generation') }}"
                                        >
                                            <flux:icon name="stop" class="mr-1 size-3.5" />
                                            <span>{{ __('Cancel') }}</span>
                                        </button>
                                    </div>
                                    <div class="mt-2 flex items-center gap-1.5 text-zinc-500 dark:text-zinc-400" aria-hidden="true">
                                        <span class="size-2 rounded-full bg-brand-blue/70 animate-pulse"></span>
                                        <span class="size-2 rounded-full bg-brand-blue/55 animate-pulse [animation-delay:180ms]"></span>
                                        <span class="size-2 rounded-full bg-brand-blue/40 animate-pulse [animation-delay:360ms]"></span>
                                    </div>
                                    <flux:text class="mt-2 block text-xs text-zinc-600 dark:text-zinc-400">
                                        {{ __('This usually takes just a moment.') }}
                                    </flux:text>
                                </div>
                            </div>
                        </div>
                        <flux:text class="wrap-break-word whitespace-pre-wrap text-base text-black dark:text-black">{{ $streamingContent }}</flux:text>
                        </div>
                    </div>
                </div>
            @endif

            <div wire:key="messages-end" x-ref="messagesEnd"></div>
        @endif
    </div>

    <form
        class="relative z-10 flex shrink-0 items-center gap-2 border-t border-border/60 p-4 dark:border-zinc-800"
        x-data="{
            hasText: false,
            applyQuickPrompt(value) {
                const trimmed = (value ?? '').toString().trim();
                this.$refs.input.value = trimmed;
                this.$refs.input.dispatchEvent(new Event('input', { bubbles: true }));
            },
            appendQuickPrompt(value) {
                const trimmed = (value ?? '').toString().trim();
                if (trimmed === '') {
                    return;
                }

                const existing = (this.$refs.input.value ?? '').toString().trim();
                if (existing === trimmed || existing.includes(trimmed)) {
                    return;
                }
                const nextValue = existing === '' ? trimmed : `${existing} ${trimmed}`;
                this.$refs.input.value = nextValue;
                this.$refs.input.dispatchEvent(new Event('input', { bubbles: true }));
            },
            submit() {
                const value = ($refs.input ? $refs.input.value : '').trim();

                if (value.length === 0) {
                    return;
                }

                // Ensure Livewire receives the latest value even if wire:model.defer is used.
                $wire.set('newMessage', value);
                $wire.submitMessage();
            },
            init() {
                this.hasText = ($wire.newMessage ?? '').toString().trim().length > 0;
            },
        }"
        @submit.prevent="submit()"
        x-init="init()"
        x-effect="hasText = ($wire.newMessage ?? '').toString().trim().length > 0"
        x-on:quick-prompt.window="applyQuickPrompt($event.detail.value); $wire.applyQuickPromptChip(($event.detail.value ?? '').toString())"
        x-on:quick-prompt-append.window="appendQuickPrompt($event.detail.value)"
    >
        <button
            type="button"
            wire:click="startNewChat"
            @disabled($isStreaming)
            class="inline-flex shrink-0 items-center rounded-lg border border-border/60 bg-white/90 px-3 py-2 text-xs font-semibold text-zinc-700 shadow-sm transition hover:bg-white/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/35 focus-visible:ring-offset-1 disabled:pointer-events-none disabled:opacity-60 dark:border-border/70 dark:bg-zinc-900/20 dark:text-zinc-300 dark:hover:bg-zinc-900/30"
        >
            {{ __('New chat') }}
        </button>

        <div class="min-w-0 flex-1">
            <input
                type="text"
                placeholder="{{ __('Type a message…') }}"
                aria-label="{{ __('Message') }}"
                x-ref="input"
                maxlength="16000"
                wire:model.defer="newMessage"
                @disabled($isStreaming)
                x-bind:disabled="$wire.isStreaming"
                x-on:input="hasText = $event.target.value.trim().length > 0"
                class="block h-10 w-full min-w-0 rounded-lg border border-border/70 bg-background px-3 py-2 text-base text-zinc-900 shadow-xs placeholder:text-zinc-500 outline-none transition focus:border-brand-blue/45 focus:ring-2 focus:ring-brand-blue/30 disabled:opacity-70 dark:border-border/70 dark:bg-zinc-900/20 dark:text-zinc-100 dark:placeholder:text-zinc-400 dark:focus:border-brand-blue/55 dark:focus:ring-brand-blue/40 sm:text-sm"
            />
            @error('newMessage')
                <flux:text class="mt-1 text-sm text-red-600 dark:text-red-400" role="alert">{{ $message }}</flux:text>
            @enderror
        </div>
        <button
            type="button"
            class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-brand-blue px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-blue/90 focus:outline-none focus:ring-2 focus:ring-brand-blue/50 disabled:pointer-events-none disabled:opacity-75"
            aria-label="{{ __('Send') }}"
            @disabled($isStreaming)
            x-bind:disabled="$wire.isStreaming || !hasText"
            @click.prevent="submit()"
        >
            <flux:icon name="paper-airplane" class="size-4" />
        </button>
    </form>
</div>

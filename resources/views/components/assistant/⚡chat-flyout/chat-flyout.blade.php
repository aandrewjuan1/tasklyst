<div
    class="relative isolate grid h-full min-h-[min(400px,80dvh)] grid-rows-[auto_1fr_auto] overflow-hidden rounded-xl bg-linear-to-r from-brand-light-blue via-white to-white text-zinc-900 shadow-sm ring-1 ring-black/5 dark:bg-zinc-900 dark:text-zinc-100 dark:ring-white/5"
    wire:poll.5s="checkStreamingTimeout"
    x-data="{
        loadingPhrases: [
            @js(__('Thinking...')),
            @js(__('Calculating...')),
            @js(__('Reviewing context...')),
            @js(__('Drafting response...')),
        ],
        loadingPhraseIndex: 0,
        loadingTimer: null,
        scrollQueued: false,
        pendingScrollBehavior: 'smooth',
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
        init() {
            this.$nextTick(() => this.queueScrollToBottom('auto'));
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
                if (value) {
                    this.startLoadingPhraseRotation();
                } else {
                    this.stopLoadingPhraseRotation();
                }
            });

            if (this.$wire.isStreaming) {
                this.startLoadingPhraseRotation();
            }
        },
    }"
>
    <div class="relative z-10 flex shrink-0 items-center gap-2 border-b border-border/60 px-4 py-3 dark:border-zinc-800">
        <flux:heading size="md">{{ __('Task assistant') }}</flux:heading>
    </div>

    <div
        class="relative z-10 flex min-h-0 flex-col gap-4 overflow-y-auto p-4"
        wire:key="messages-container"
        x-ref="messagesContainer"
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
                    <flux:button
                        type="button"
                        size="sm"
                        variant="ghost"
                        class="rounded-full border border-brand-blue/18 bg-white/90 px-4 py-2 text-sm font-medium text-zinc-800 shadow-sm transition-colors hover:border-brand-blue/28 hover:bg-brand-light-blue/70 hover:text-zinc-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/40 focus-visible:ring-offset-1 dark:border-border/70 dark:bg-zinc-900/30 dark:text-zinc-200 dark:hover:bg-zinc-800/50 dark:hover:text-zinc-100 dark:focus-visible:ring-offset-zinc-900 disabled:pointer-events-none disabled:opacity-60"
                        wire:click="applyQuickPromptChip('{{ __('What should I do first') }}')"
                    >
                        {{ __('What should I do first') }}
                    </flux:button>
                    <flux:button
                        type="button"
                        size="sm"
                        variant="ghost"
                        class="rounded-full border border-brand-blue/18 bg-white/90 px-4 py-2 text-sm font-medium text-zinc-800 shadow-sm transition-colors hover:border-brand-blue/28 hover:bg-brand-light-blue/70 hover:text-zinc-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/40 focus-visible:ring-offset-1 dark:border-border/70 dark:bg-zinc-900/30 dark:text-zinc-200 dark:hover:bg-zinc-800/50 dark:hover:text-zinc-100 dark:focus-visible:ring-offset-zinc-900 disabled:pointer-events-none disabled:opacity-60"
                        wire:click="applyQuickPromptChip('{{ __('Schedule my most important task') }}')"
                    >
                        {{ __('Schedule my most important task') }}
                    </flux:button>
                    <flux:button
                        type="button"
                        size="sm"
                        variant="ghost"
                        class="rounded-full border border-brand-blue/18 bg-white/90 px-4 py-2 text-sm font-medium text-zinc-800 shadow-sm transition-colors hover:border-brand-blue/28 hover:bg-brand-light-blue/70 hover:text-zinc-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/40 focus-visible:ring-offset-1 dark:border-border/70 dark:bg-zinc-900/30 dark:text-zinc-200 dark:hover:bg-zinc-800/50 dark:hover:text-zinc-100 dark:focus-visible:ring-offset-zinc-900 disabled:pointer-events-none disabled:opacity-60"
                        wire:click="applyQuickPromptChip('{{ __('Create a plan for today') }}')"
                    >
                        {{ __('Create a plan for today') }}
                    </flux:button>
                </div>
            </div>
        @else
            @foreach ($chatMessages as $message)
                @if ($message->role->value === 'user')
                    <div
                        wire:key="message-{{ $message->id }}"
                        class="flex justify-end"
                    >
                        <div class="max-w-[85%] min-w-0 rounded-xl border border-brand-blue/25 bg-brand-blue/15 px-3 py-2 shadow-sm dark:border-brand-blue/35 dark:bg-brand-blue/20">
                            <flux:text class="wrap-break-word text-sm text-zinc-900 dark:text-zinc-100">{{ $message->content }}</flux:text>
                        </div>
                    </div>
                @elseif ($message->role->value === 'assistant' && $message->id !== $streamingMessageId)
                    <div
                        wire:key="message-{{ $message->id }}"
                        class="flex justify-start"
                    >
                        <div class="max-w-[85%] min-w-0 rounded-xl border border-border/70 bg-muted/30 px-3 py-2 shadow-sm dark:border-border/70 dark:bg-muted/15">
                            @php
                                $isStopped = data_get($message->metadata, 'stream.status') === 'stopped';
                                // Always display formatted content - ResponseProcessor ensures all messages are student-friendly
                                $display = $isStopped ? '' : ($message->content ?: __('…'));
                                $proposals = data_get($message->metadata, 'schedule.proposals', data_get($message->metadata, 'daily_schedule.proposals', data_get($message->metadata, 'structured.data.proposals', [])));
                                $scheduleConfirmationRequired = (bool) data_get($message->metadata, 'schedule.confirmation_required', data_get($message->metadata, 'structured.data.confirmation_required', false));
                                $scheduleAwaitingDecision = (bool) data_get($message->metadata, 'schedule.awaiting_user_decision', data_get($message->metadata, 'structured.data.awaiting_user_decision', false));
                                $hideScheduleProposalCards = $scheduleConfirmationRequired || $scheduleAwaitingDecision;
                                $prioritizeChips = data_get($message->metadata, 'prioritize.next_options_chip_texts', []);
                                $guidanceChips = data_get($message->metadata, 'general_guidance.next_options_chip_texts', []);
                                $scheduleChips = data_get($message->metadata, 'schedule.next_options_chip_texts', []);
                                $structuredChips = data_get($message->metadata, 'structured.data.next_options_chip_texts', []);
                                $nextOptionChips = is_array($prioritizeChips) && count($prioritizeChips) > 0
                                    ? $prioritizeChips
                                    : (is_array($guidanceChips) && count($guidanceChips) > 0
                                        ? $guidanceChips
                                        : (is_array($scheduleChips) && count($scheduleChips) > 0
                                            ? $scheduleChips
                                            : (is_array($structuredChips) ? $structuredChips : [])));
                                if (! is_array($nextOptionChips)) {
                                    $nextOptionChips = [];
                                }
                                $nextOptionChips = array_values(array_filter(
                                    array_map(static fn (mixed $chip): string => trim((string) $chip), $nextOptionChips),
                                    static fn (string $chip): bool => $chip !== ''
                                ));
                                $isLatestAssistant = $latestAssistantMessageId !== null && $message->id === $latestAssistantMessageId;
                                $chipsDismissed = (bool) ($dismissedNextOptionChipsByMessage[$message->id] ?? false);
                            @endphp
                            @if ($isStopped)
                                <flux:text class="mb-1 block text-xs text-zinc-600 dark:text-zinc-400">{{ __('Stopped') }}</flux:text>
                            @endif
                            @if ($display !== '')
                                <flux:text class="wrap-break-word whitespace-pre-wrap text-sm text-zinc-900 dark:text-zinc-100">{{ $display }}</flux:text>
                            @endif

                            @if (! $hideScheduleProposalCards && is_array($proposals) && count($proposals) > 0)
                                @php
                                    $pendingSchedulableCount = 0;
                                    foreach ($proposals as $p) {
                                        if (! is_array($p)) {
                                            continue;
                                        }
                                        if (($p['status'] ?? 'pending') !== 'pending') {
                                            continue;
                                        }
                                        $ptitle = (string) ($p['title'] ?? '');
                                        if ($ptitle === 'No schedulable items found') {
                                            continue;
                                        }
                                        $ap = $p['apply_payload'] ?? null;
                                        $hasPayload = is_array($ap) && $ap !== [];
                                        $et = (string) ($p['entity_type'] ?? '');
                                        $eid = (int) ($p['entity_id'] ?? 0);
                                        $st = (string) ($p['start_datetime'] ?? '');
                                        $en = (string) ($p['end_datetime'] ?? '');
                                        $legacyOk = ($et === 'task' && $eid > 0 && $st !== '')
                                            || ($et === 'event' && $eid > 0 && $st !== '' && $en !== '')
                                            || ($et === 'project' && $eid > 0 && $st !== '');
                                        if ($hasPayload || $legacyOk) {
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
                                            <div class="rounded-md border border-border/60 bg-muted/20 p-2 dark:border-border/60 dark:bg-muted/15">
                                                <flux:text class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $title }}</flux:text>
                                                <flux:text class="text-xs text-zinc-700 dark:text-zinc-300">
                                                    {{ $timeLabel }}
                                                </flux:text>
                                                <flux:text class="text-xs text-zinc-700 dark:text-zinc-300">{{ __('Status: :status', ['status' => $status]) }}</flux:text>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                                @if ($showAcceptAll)
                                    <div class="mt-3">
                                        <flux:button
                                            variant="primary"
                                            size="sm"
                                            wire:click="acceptAllScheduleProposals({{ $message->id }})"
                                            wire:loading.attr="disabled"
                                        >
                                            {{ __('Accept all') }}
                                        </flux:button>
                                    </div>
                                @endif
                            @endif

                            @if (! $isStopped && $isLatestAssistant && ! $chipsDismissed && count($nextOptionChips) > 0)
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach ($nextOptionChips as $chipIndex => $chipText)
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            class="rounded-full border border-border/60 bg-muted/20 px-2 text-zinc-700 transition-colors hover:bg-muted/40 hover:text-zinc-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/40 focus-visible:ring-offset-1 dark:border-border/70 dark:bg-muted/10 dark:text-zinc-300 dark:hover:text-zinc-100 dark:hover:bg-muted/20 dark:focus-visible:ring-offset-zinc-900 disabled:pointer-events-none disabled:opacity-60"
                                            wire:click="submitNextOptionChip({{ $message->id }}, {{ $chipIndex }})"
                                            wire:loading.attr="disabled"
                                            wire:target="submitNextOptionChip,submitMessage"
                                        >
                                            {{ $chipText }}
                                        </flux:button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            @endforeach

            @if ($isStreaming && $streamingMessageId)
                <div
                    wire:key="streaming-assistant"
                    class="flex justify-start"
                >
                    <div class="max-w-[85%] min-w-0 overflow-visible rounded-xl border border-border/70 bg-muted/30 px-3 py-2 shadow-sm dark:border-border/70 dark:bg-muted/15">
                        <div
                            x-show="$wire.isStreaming && ($wire.streamingContent?.length ?? 0) === 0"
                            x-transition.opacity.duration.200ms
                            class="mb-1 flex items-center gap-2 text-zinc-700 dark:text-zinc-300"
                        >
                            <flux:icon name="arrow-path" class="size-3.5 animate-spin" />
                            <flux:text class="text-sm" x-text="currentLoadingPhrase()">{{ __('Thinking...') }}</flux:text>
                            <flux:button
                                size="xs"
                                variant="ghost"
                                wire:click="requestStopStreaming"
                                wire:loading.attr="disabled"
                                wire:target="requestStopStreaming"
                                class="ml-1"
                                aria-label="{{ __('Stop generation') }}"
                            >
                                <flux:icon name="stop" class="size-3.5" />
                            </flux:button>
                        </div>
                        <flux:text class="wrap-break-word whitespace-pre-wrap text-sm">{{ $streamingContent }}</flux:text>
                    </div>
                </div>
            @endif

            <div wire:key="messages-end" x-ref="messagesEnd"></div>
        @endif
    </div>

    <form
        class="relative z-10 flex shrink-0 items-center gap-2 border-t border-border/60 p-4 dark:border-zinc-800"
        wire:submit="submitMessage"
    >
        <button
            type="button"
            wire:click="startNewChat"
            @disabled($isStreaming)
            class="inline-flex shrink-0 items-center rounded-lg border border-border/60 bg-muted/20 px-3 py-2 text-xs font-semibold text-zinc-700 shadow-sm transition hover:bg-muted/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/35 focus-visible:ring-offset-1 disabled:pointer-events-none disabled:opacity-60 dark:border-border/70 dark:bg-muted/10 dark:text-zinc-300 dark:hover:bg-muted/20"
        >
            {{ __('New chat') }}
        </button>

        <div class="min-w-0 flex-1">
            <input
                type="text"
                wire:model="newMessage"
                placeholder="{{ __('Type a message…') }}"
                aria-label="{{ __('Message') }}"
                class="block h-10 w-full min-w-0 rounded-lg border border-border/70 bg-background px-3 py-2 text-base text-zinc-900 shadow-xs placeholder:text-zinc-500 outline-none transition focus:border-brand-blue/45 focus:ring-2 focus:ring-brand-blue/30 disabled:opacity-70 dark:border-border/70 dark:bg-zinc-900/20 dark:text-zinc-100 dark:placeholder:text-zinc-400 dark:focus:border-brand-blue/55 dark:focus:ring-brand-blue/40 sm:text-sm"
                wire:loading.attr="disabled"
                wire:target="submitMessage"
                @disabled($isStreaming)
            />
            @error('newMessage')
                <flux:text class="mt-1 text-sm text-red-600 dark:text-red-400" role="alert">{{ $message }}</flux:text>
            @enderror
        </div>
        <button
            type="submit"
            class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-brand-blue px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-blue/90 focus:outline-none focus:ring-2 focus:ring-brand-blue/50 disabled:pointer-events-none disabled:opacity-75"
            wire:loading.attr="disabled"
            wire:target="submitMessage"
            aria-label="{{ __('Send') }}"
            @disabled($isStreaming)
        >
            <flux:icon name="paper-airplane" class="size-4" />
        </button>
    </form>
</div>

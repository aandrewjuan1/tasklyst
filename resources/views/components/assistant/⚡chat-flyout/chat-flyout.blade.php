<div
    class="grid h-full min-h-[min(400px,80dvh)] grid-rows-[auto_1fr_auto]"
    x-data="{
        loadingPhrases: [
            @js(__('Thinking...')),
            @js(__('Calculating...')),
            @js(__('Reviewing context...')),
            @js(__('Drafting response...')),
        ],
        loadingPhraseIndex: 0,
        loadingTimer: null,
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
        scrollToBottom() {
            $refs.messagesEnd?.scrollIntoView({ behavior: 'smooth' });
        },
        init() {
            this.$nextTick(() => this.scrollToBottom());
            this.$watch('$wire.streamingContent', () => {
                this.scrollToBottom();
            });
            this.$watch(() => ($wire.chatMessages?.length ?? 0), () => this.scrollToBottom());
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
    <div class="flex shrink-0 items-center gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
        <flux:heading size="md">{{ __('Task assistant') }}</flux:heading>

        <flux:button
            size="xs"
            variant="ghost"
            class="ml-auto"
            wire:click="startNewChat"
        >
            {{ __('New chat') }}
        </flux:button>
    </div>

    <div class="flex min-h-0 flex-col gap-4 overflow-y-auto p-4" wire:key="messages-container">
        @if ($chatMessages->isEmpty() && ! $isStreaming)
            <div class="flex flex-1 flex-col items-center justify-center gap-2 text-center">
                <flux:text class="text-zinc-500 dark:text-zinc-400">
                    {{ __('Send a message to get started.') }}
                </flux:text>
            </div>
        @else
            @foreach ($chatMessages as $message)
                @if ($message->role->value === 'user')
                    <div
                        wire:key="message-{{ $message->id }}"
                        class="flex justify-end"
                    >
                        <div class="max-w-[85%] min-w-0 rounded-lg bg-zinc-200 px-3 py-2 dark:bg-zinc-600">
                            <flux:text class="wrap-break-word text-sm">{{ $message->content }}</flux:text>
                        </div>
                    </div>
                @elseif ($message->role->value === 'assistant' && $message->id !== $streamingMessageId)
                    <div
                        wire:key="message-{{ $message->id }}"
                        class="flex justify-start"
                    >
                        <div class="max-w-[85%] min-w-0 rounded-lg bg-zinc-100 px-3 py-2 dark:bg-zinc-700">
                            @php
                                $isStopped = data_get($message->metadata, 'stream.status') === 'stopped';
                                // Always display formatted content - ResponseProcessor ensures all messages are student-friendly
                                $display = $isStopped ? '' : ($message->content ?: __('…'));
                                $proposals = data_get($message->metadata, 'daily_schedule.proposals', data_get($message->metadata, 'structured.data.proposals', []));
                            @endphp
                            @if ($isStopped)
                                <flux:text class="mb-1 block text-xs text-zinc-500 dark:text-zinc-400">{{ __('Stopped') }}</flux:text>
                            @endif
                            @if ($display !== '')
                                <flux:text class="wrap-break-word whitespace-pre-wrap text-sm">{{ $display }}</flux:text>
                            @endif

                            @if (is_array($proposals) && count($proposals) > 0)
                                <div class="mt-3 flex flex-col gap-2">
                                    @foreach ($proposals as $proposal)
                                        @if (is_array($proposal))
                                            @php
                                                $proposalId = (string) ($proposal['proposal_id'] ?? '');
                                                $status = (string) ($proposal['status'] ?? 'pending');
                                                $startAt = (string) ($proposal['start_datetime'] ?? '');
                                                $endAt = (string) ($proposal['end_datetime'] ?? '');
                                                $title = (string) ($proposal['title'] ?? 'Scheduled item');
                                                $isPending = $status === 'pending';

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
                                            <div class="rounded-md border border-zinc-300 p-2 dark:border-zinc-600">
                                                <flux:text class="text-sm font-medium">{{ $title }}</flux:text>
                                                <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                                                    {{ $timeLabel }}
                                                </flux:text>
                                                <flux:text class="text-xs">{{ __('Status: :status', ['status' => $status]) }}</flux:text>
                                                <div class="mt-2 flex gap-2">
                                                    <flux:button
                                                        size="xs"
                                                        variant="primary"
                                                        wire:click="acceptScheduleProposalItem({{ $message->id }}, '{{ $proposalId }}')"
                                                        wire:loading.attr="disabled"
                                                    >
                                                        {{ __('Accept') }}
                                                    </flux:button>
                                                    <flux:button
                                                        size="xs"
                                                        variant="ghost"
                                                        wire:click="declineScheduleProposalItem({{ $message->id }}, '{{ $proposalId }}')"
                                                        wire:loading.attr="disabled"
                                                    >
                                                        {{ __('Decline') }}
                                                    </flux:button>
                                                </div>
                                            </div>
                                        @endif
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
                    <div class="max-w-[85%] min-w-0 overflow-visible rounded-lg bg-zinc-100 px-3 py-2 dark:bg-zinc-700">
                        <div
                            x-show="$wire.isStreaming && ($wire.streamingContent?.length ?? 0) === 0"
                            x-transition.opacity.duration.200ms
                            class="mb-1 flex items-center gap-2 text-zinc-500 dark:text-zinc-400"
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
        class="flex shrink-0 items-center gap-2 border-t border-zinc-200 p-4 dark:border-zinc-700"
        wire:submit="submitMessage"
    >
        <div class="min-w-0 flex-1">
            <input
                type="text"
                wire:model="newMessage"
                placeholder="{{ __('Type a message…') }}"
                aria-label="{{ __('Message') }}"
                class="block h-10 w-full min-w-0 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-base text-zinc-700 shadow-xs placeholder-zinc-400 outline-none transition focus:border-zinc-400 focus:ring-2 focus:ring-zinc-200 disabled:opacity-70 dark:border-zinc-600 dark:bg-white/10 dark:text-zinc-300 dark:placeholder-zinc-500 dark:focus:border-zinc-500 dark:focus:ring-zinc-600 sm:text-sm"
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
            class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-zinc-800 focus:outline-none focus:ring-2 focus:ring-zinc-500 focus:ring-offset-2 disabled:pointer-events-none disabled:opacity-75 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-200 dark:focus:ring-zinc-400 dark:focus:ring-offset-zinc-900"
            wire:loading.attr="disabled"
            wire:target="submitMessage"
            aria-label="{{ __('Send') }}"
            @disabled($isStreaming)
        >
            <flux:icon name="paper-airplane" class="size-4" />
        </button>
    </form>
</div>

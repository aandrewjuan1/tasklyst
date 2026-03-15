<div
    class="grid h-full min-h-[min(400px,80dvh)] grid-rows-[auto_1fr_auto]"
    x-data="{
        scrollToBottom() {
            $refs.messagesEnd?.scrollIntoView({ behavior: 'smooth' });
        },
        init() {
            this.$nextTick(() => this.scrollToBottom());
            this.$watch('$wire.streamingContent', () => this.scrollToBottom());
            this.$watch(() => ($wire.chatMessages?.length ?? 0), () => this.scrollToBottom());
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
                            <flux:text class="break-words text-sm">{{ $message->content }}</flux:text>
                        </div>
                    </div>
                @elseif ($message->role->value === 'assistant' && $message->id !== $streamingMessageId)
                    <div
                        wire:key="message-{{ $message->id }}"
                        class="flex justify-start"
                    >
                        <div class="max-w-[85%] min-w-0 rounded-lg bg-zinc-100 px-3 py-2 dark:bg-zinc-700">
                            <flux:text class="break-words whitespace-pre-wrap text-sm">{{ $message->content ?: __('…') }}</flux:text>
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
                        @if ($showWorking)
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Working…') }}</flux:text>
                        @endif
                        <flux:text class="break-words whitespace-pre-wrap text-sm">{{ $streamingContent }}<span class="animate-pulse">|</span></flux:text>
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
                @if($isStreaming) disabled @endif
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
            {{ $isStreaming ? 'disabled' : '' }}
        >
            <flux:icon name="paper-airplane" class="size-4" />
        </button>
    </form>
</div>

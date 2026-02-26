<div class="fixed inset-x-0 bottom-0 z-40 flex flex-col" x-data="{ open: @entangle('dockOpen') }">
    {{-- Closed state: thin bar with Assistant button --}}
    <div
        x-show="!open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
        class="flex h-12 items-center justify-center border-t border-zinc-200 bg-white/95 shadow-[0_-2px_10px_rgba(0,0,0,0.05)] backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/95"
    >
        <flux:button
            variant="ghost"
            size="sm"
            icon="sparkles"
            wire:click="openDock"
        >
            {{ __('Workspace Assistant') }}
        </flux:button>
    </div>

    {{-- Open state: dock panel --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-4"
        x-transition:enter-end="opacity-100 translate-y-0"
        class="flex max-h-[40vh] min-h-[280px] flex-col rounded-t-xl border border-b-0 border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-900 md:max-h-[45vh]"
    >
        {{-- Header --}}
        <div class="flex shrink-0 items-center justify-between gap-2 border-b border-zinc-200 px-3 py-2 dark:border-zinc-700">
            <flux:heading>{{ __('Workspace Assistant') }}</flux:heading>
            <div class="flex items-center gap-1">
                <flux:button variant="ghost" size="xs" wire:click="newSession">
                    {{ __('New session') }}
                </flux:button>
                <flux:button variant="ghost" size="xs" icon="x-mark" wire:click="closeDock" />
            </div>
        </div>

        {{-- Error banner --}}
        @if($errorMessage)
            <div class="shrink-0 border-b border-amber-200 bg-amber-50 px-3 py-2 dark:border-amber-800 dark:bg-amber-950/50">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm text-amber-800 dark:text-amber-200">{{ $errorMessage }}</span>
                    <flux:button variant="ghost" size="xs" icon="x-mark" wire:click="clearError" />
                </div>
            </div>
        @endif

        {{-- Conversation timeline --}}
        <div class="min-h-0 flex-1 overflow-y-auto p-3">
            <div class="flex flex-col gap-4">
                @forelse($this->messages as $message)
                    <div
                        wire:key="msg-{{ $message->id }}"
                        class="{{ $message->isUser() ? 'ml-0 mr-8' : 'ml-8 mr-0' }} flex flex-col gap-1"
                    >
                        <div
                            class="rounded-xl px-3 py-2 text-sm {{ $message->isUser() ? 'bg-zinc-100 dark:bg-zinc-800' : 'bg-zinc-50 dark:bg-zinc-800/70' }}"
                        >
                            @if($message->isAssistant())
                                @php
                                    $meta = $message->metadata ?? [];
                                    $intent = $meta['intent'] ?? null;
                                    $snapshot = $meta['recommendation_snapshot'] ?? null;
                                @endphp
                                @if($intent)
                                    <flux:badge size="sm" color="zinc" class="mb-1">
                                        {{ $intent }}
                                    </flux:badge>
                                @endif
                                <p class="whitespace-pre-wrap">{{ $message->content }}</p>
                                @if($snapshot)
                                    @include('components.workspace.⚡assistant-dock.partials.recommendation-card', [
                                        'snapshot' => $snapshot,
                                    ])
                                @endif
                            @else
                                <p class="whitespace-pre-wrap">{{ $message->content }}</p>
                            @endif
                        </div>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $message->created_at->diffForHumans() }}
                        </span>
                    </div>
                @empty
                    <p class="py-4 text-center text-sm text-zinc-500 dark:text-zinc-400">
                        {{ __('Send a message to get started. Try "Prioritize my tasks" or "Plan my day".') }}
                    </p>
                @endforelse
            </div>

            @if($isLoading && $statusMessage)
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $statusMessage }}</p>
            @endif
        </div>

        {{-- Input area --}}
        <div class="shrink-0 border-t border-zinc-200 p-3 dark:border-zinc-700">
            <form wire:submit="sendMessage" class="flex gap-2">
                <flux:textarea
                    wire:model="userInput"
                    placeholder="{{ __('Ask the assistant…') }}"
                    rows="2"
                    class="min-h-10 flex-1 resize-none"
                    :disabled="$isLoading"
                />
                <flux:button
                    type="submit"
                    variant="primary"
                    icon="paper-airplane"
                    :loading="$isLoading"
                    class="self-end"
                >
                    <span class="sr-only">{{ __('Send') }}</span>
                </flux:button>
            </form>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                {{ __('Enter to send. The assistant can prioritize tasks, suggest schedules, and answer questions.') }}
            </p>
        </div>
    </div>
</div>

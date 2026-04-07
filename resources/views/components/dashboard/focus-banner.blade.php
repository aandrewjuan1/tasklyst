@props([
    'focus',
    'workspaceUrl',
])

@php
    /** @var \App\Data\Dashboard\OperationalFocusSummary $focus */
@endphp

<div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 dark:border-amber-400/30 dark:bg-amber-400/10">
    <div class="min-w-0 flex-1">
        <div class="text-sm font-medium text-foreground">{{ __('Focus session in progress') }}</div>
        <div class="mt-1 text-xs text-muted-foreground">
            @if ($focus->taskTitle)
                {{ $focus->taskTitle }}
            @else
                {{ __('Session #:id', ['id' => $focus->sessionId]) }}
            @endif
            <span class="tabular-nums"> · {{ $focus->startedAt->diffForHumans() }}</span>
        </div>
    </div>
    <flux:button variant="ghost" size="sm" :href="$workspaceUrl" wire:navigate>
        {{ __('Open workspace') }}
    </flux:button>
</div>

{{-- Focus mode bar: timer, progress, Pause/Resume/Stop. Uses parent Alpine scope (listItemCard). --}}
<div
    x-show="isFocused"
    x-cloak
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0 -translate-y-0.5"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 -translate-y-0.5"
    class="flex flex-col gap-2 rounded-lg border border-primary/30 bg-primary/10 px-3 py-2 dark:bg-primary/20"
>
    <div class="flex items-center justify-between gap-2">
        <span class="text-sm font-medium text-primary">{{ __('Focus mode') }}</span>
        <span
            x-show="!sessionComplete"
            class="tabular-nums font-medium text-primary"
            x-text="formatFocusCountdown(focusRemainingSeconds)"
            aria-live="polite"
        ></span>
        <span
            x-show="sessionComplete"
            x-cloak
            class="font-medium text-primary"
        >{{ __('Session complete!') }}</span>
        <div class="flex shrink-0 items-center gap-1">
            <flux:button
                x-show="!sessionComplete && !focusIsPaused"
                variant="ghost"
                size="sm"
                icon="pause"
                class="shrink-0"
                @click="pauseFocus()"
            >
                {{ __('Pause') }}
            </flux:button>
            <flux:button
                x-show="!sessionComplete && focusIsPaused"
                x-cloak
                variant="ghost"
                size="sm"
                icon="play"
                class="shrink-0"
                @click="resumeFocus()"
            >
                {{ __('Resume') }}
            </flux:button>
            <flux:button
                x-show="!sessionComplete"
                variant="ghost"
                size="sm"
                icon="x-mark"
                class="shrink-0"
                @click="stopFocus()"
            >
                {{ __('Stop') }}
            </flux:button>
            <flux:button
                x-show="sessionComplete"
                x-cloak
                variant="ghost"
                size="sm"
                icon="x-mark"
                class="shrink-0"
                @click="dismissCompletedFocus()"
            >
                {{ __('Close') }}
            </flux:button>
        </div>
    </div>
    <div class="h-2 w-full overflow-hidden rounded-full border border-zinc-300 bg-zinc-200 dark:border-zinc-600 dark:bg-zinc-700" role="progressbar" :aria-valuenow="Math.round(focusElapsedPercentValue)" aria-valuemin="0" aria-valuemax="100" aria-label="{{ __('Time elapsed') }}">
        <div
            class="h-full rounded-full bg-(--color-accent) transition-[width] duration-1000 ease-linear"
            :style="{ width: Math.min(100, Math.max(0, focusElapsedPercentValue)) + '%', minWidth: focusElapsedPercentValue > 0 ? '2px' : '0' }"
        ></div>
    </div>
</div>

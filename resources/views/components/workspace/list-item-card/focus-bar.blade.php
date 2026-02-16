{{-- Focus mode bar: ready state (Start/Cancel) or active session (timer, progress, Pause/Resume/Stop). Uses parent Alpine scope (listItemCard). --}}
@php
    $focusBarCloak = empty($hasActiveFocusOnThisTask ?? false);
    $focusModeTypeDisabledExpr = '!((focusModeTypes || []).find(t => t.value === focusModeType)?.available)';
@endphp
<div
    x-show="focusReady || isFocused"
    @if($focusBarCloak) x-cloak @endif
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0 -translate-y-0.5"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 -translate-y-0.5"
    class="overflow-hidden rounded-lg border-0 border-t-4 border-blue-800 bg-primary/10 shadow-inner dark:bg-primary/20"
>
    <div class="px-4 py-3">
        {{-- Ready state: user has clicked Focus but not started the timer yet --}}
        <div
            x-show="focusReady && !isFocused"
            x-cloak
            class="flex flex-col gap-3"
        >
            {{-- Row 1: focus mode type selector --}}
            <flux:radio.group
                variant="segmented"
                size="sm"
                x-model="focusModeType"
                class="w-full"
            >
                @foreach($focusModeTypes ?? [] as $ft)
                    @php
                        $ftAvailable = $ft['available'] ?? true;
                    @endphp
                    @if($ftAvailable)
                        <flux:radio value="{{ $ft['value'] }}">
                            {{ $ft['label'] }}
                        </flux:radio>
                    @else
                        <flux:radio value="{{ $ft['value'] }}" disabled>
                            {{ $ft['label'] }}
                        </flux:radio>
                    @endif
                @endforeach
            </flux:radio.group>

            {{-- Row 2: mode info, duration, actions --}}
            <div class="flex flex-wrap items-center gap-4">
                <div class="min-w-0 flex-1 basis-0">
                    <div class="flex flex-col gap-0.5">
                        <template x-if="focusModeType === 'countdown'">
                            <div class="flex flex-col gap-0.5">
                                <span class="text-sm font-semibold tracking-tight text-primary">{{ __('Focus mode') }} · {{ __('Sprint') }}</span>
                                <span class="max-w-sm text-xs leading-snug text-zinc-500 dark:text-zinc-400">{{ __('Count down your set duration with no breaks—sprint through the task.') }}</span>
                            </div>
                        </template>
                        <template x-if="focusModeType === 'pomodoro'">
                            <div class="flex flex-col gap-0.5">
                                <span class="text-sm font-semibold tracking-tight text-primary">{{ __('Focus mode') }} · {{ __('Pomodoro') }}</span>
                                <span class="text-xs leading-snug text-zinc-500 dark:text-zinc-400">{{ __('Coming soon') }}</span>
                            </div>
                        </template>
                        <template x-if="focusModeType !== 'countdown' && focusModeType !== 'pomodoro'">
                            <div class="flex flex-col gap-0.5">
                                <span class="text-sm font-semibold tracking-tight text-primary">{{ __('Focus mode') }} · {{ __('Sprint') }}</span>
                                <span class="max-w-sm text-xs leading-snug text-zinc-500 dark:text-zinc-400">{{ __('Count down your set duration with no breaks—sprint through the task.') }}</span>
                            </div>
                        </template>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <span class="min-w-18 text-right text-base font-semibold tabular-nums text-primary" x-text="formatFocusReadyDuration()"></span>
                    <div class="flex shrink-0 items-center gap-1.5">
                        <flux:button
                            variant="primary"
                            size="sm"
                            icon="play"
                            class="shrink-0"
                            x-bind:disabled="$focusModeTypeDisabledExpr"
                            @click="startFocusFromReady()"
                        >
                            {{ __('Start') }}
                        </flux:button>
                        <flux:button
                            variant="ghost"
                            size="sm"
                            icon="x-mark"
                            class="shrink-0"
                            @click="focusReady = false"
                        >
                            {{ __('Cancel') }}
                        </flux:button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Active session: timer running (or paused), progress bar, Pause/Resume/Stop --}}
        <div x-show="isFocused" class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center gap-4">
                <div class="min-w-0 flex-1 basis-0">
                    <div class="flex flex-col gap-0.5">
                        <span class="text-sm font-semibold tracking-tight text-primary">{{ __('Focus mode') }} · {{ __('Sprint') }}</span>
                        <span class="max-w-sm text-xs leading-snug text-zinc-500 dark:text-zinc-400">{{ __('Count down your set duration with no breaks—sprint through the task.') }}</span>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <span
                        x-show="!sessionComplete"
                        class="min-w-16 text-right text-lg font-bold tabular-nums tracking-tight text-primary"
                        x-text="formatFocusCountdown(focusRemainingSeconds)"
                        aria-live="polite"
                    ></span>
                    <span
                        x-show="sessionComplete"
                        x-cloak
                        class="text-sm font-semibold text-primary"
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
                            x-show="sessionComplete && kind === 'task'"
                            x-cloak
                            variant="ghost"
                            size="sm"
                            icon="check-circle"
                            class="shrink-0"
                            @click="markTaskDoneFromFocus()"
                        >
                            {{ __('Mark as Done') }}
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
            </div>
            <div
                class="h-2 w-full shrink-0 overflow-hidden rounded-full border border-zinc-300 bg-zinc-200 dark:border-zinc-600 dark:bg-zinc-700"
                role="progressbar"
                :aria-valuenow="Math.round(focusElapsedPercentValue)"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-label="{{ __('Time elapsed') }}"
            >
                <div
                    class="block h-full min-w-0 rounded-full bg-(--color-accent) transition-[width] duration-300 ease-linear"
                    :style="{ width: Math.min(100, Math.max(0, focusElapsedPercentValue)) + '%', minWidth: focusElapsedPercentValue > 0 ? '2px' : '0' }"
                ></div>
            </div>
        </div>
    </div>
</div>

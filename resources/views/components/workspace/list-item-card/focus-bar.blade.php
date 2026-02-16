{{-- Focus mode bar: ready state (Start/Cancel) or active session (timer, progress, Pause/Resume/Stop). Uses parent Alpine scope (listItemCard). --}}
@php
    $focusBarCloak = empty($hasActiveFocusOnThisTask ?? false);
@endphp
<div
    x-show="focusReady || isFocused"
    @if($focusBarCloak) x-cloak @endif
    x-transition:enter="transition ease-out duration-150"
    x-transition:enter-start="opacity-0 -translate-y-0.5"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 -translate-y-0.5"
    class="overflow-hidden"
>
    <div class="flex flex-col gap-3 px-4 py-3">
        {{-- Row 1: Sprint/Pomodoro tab — always visible when bar is open; non-interactive during active session --}}
        <div
            class="w-full transition-opacity duration-150"
            :class="{ 'pointer-events-none select-none opacity-60': isFocused }"
        >
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
        </div>

        {{-- Row 2: single row — left = description, right = ready actions OR timer actions; content swaps in place, no wrapper swap --}}
        <div class="flex flex-wrap items-center gap-4">
            {{-- Left: mode description (same area, content toggled by state) --}}
            <div class="min-w-0 flex-1 basis-0">
                <div class="flex flex-col gap-0.5">
                    {{-- Ready: dynamic label by focusModeType --}}
                    <div class="flex flex-col gap-0.5" x-show="!isFocused">
                        <div class="flex flex-col gap-0.5" x-show="focusModeType === 'countdown'">
                            <span class="text-sm font-semibold tracking-tight text-primary">{{ __('Focus mode') }} · {{ __('Sprint') }}</span>
                            <span class="max-w-sm text-xs leading-snug text-zinc-500">{{ __('Count down your set duration with no breaks.') }}</span>
                        </div>
                        <div class="flex flex-col gap-0.5" x-show="focusModeType === 'pomodoro'" x-cloak>
                            <span class="text-sm font-semibold tracking-tight text-primary">{{ __('Focus mode') }} · {{ __('Pomodoro') }}</span>
                            <span class="text-xs leading-snug text-zinc-500">{{ __('Coming soon') }}</span>
                        </div>
                        <div class="flex flex-col gap-0.5" x-show="focusModeType !== 'countdown' && focusModeType !== 'pomodoro'" x-cloak>
                            <span class="text-sm font-semibold tracking-tight text-primary">{{ __('Focus mode') }} · {{ __('Sprint') }}</span>
                            <span class="max-w-sm text-xs leading-snug text-zinc-500">{{ __('Count down your set duration with no breaks.') }}</span>
                        </div>
                    </div>
                    {{-- Active: fixed Sprint session line --}}
                    <div class="flex flex-col gap-0.5" x-show="isFocused" x-cloak>
                        <span class="text-sm font-semibold tracking-tight text-primary">{{ __('Focus mode') }} · {{ __('Sprint') }}</span>
                        <span class="max-w-sm text-xs leading-snug text-zinc-500">{{ __('Count down your set duration with no breaks.') }}</span>
                    </div>
                </div>
            </div>
            {{-- Right: ready actions or timer actions (visibility only, no DOM swap) --}}
            <div class="flex shrink-0 items-center gap-3">
                {{-- Ready: duration + Start/Cancel --}}
                <div class="flex items-center gap-3" x-show="!isFocused">
                    <span class="min-w-18 text-right text-base font-semibold tabular-nums text-primary" x-text="formattedFocusReadyDuration"></span>
                    <div class="flex shrink-0 items-center gap-1.5">
                        <flux:button
                            variant="primary"
                            size="sm"
                            icon="play"
                            class="shrink-0"
                            x-bind:disabled="!((focusModeTypes || []).find(t => t.value === focusModeType)?.available)"
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
                {{-- Active: running — timer + Pause/Resume/Stop --}}
                <div class="flex min-w-16 shrink-0 items-center gap-3" x-show="isFocused && !sessionComplete" x-cloak>
                    <span
                        class="min-w-16 text-right text-lg font-bold tabular-nums tracking-tight text-primary"
                        x-text="focusCountdownText"
                        aria-live="polite"
                    ></span>
                    <div class="flex shrink-0 items-center gap-1">
                        <flux:button
                            x-show="!focusIsPaused"
                            variant="ghost"
                            size="sm"
                            icon="pause"
                            class="shrink-0"
                            @click="pauseFocus()"
                        >
                            {{ __('Pause') }}
                        </flux:button>
                        <flux:button
                            x-show="focusIsPaused"
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
                            variant="ghost"
                            size="sm"
                            icon="x-mark"
                            class="shrink-0"
                            @click="stopFocus()"
                        >
                            {{ __('Stop') }}
                        </flux:button>
                    </div>
                </div>
                {{-- Active: session complete --}}
                <div class="flex shrink-0 items-center gap-1" x-show="isFocused && sessionComplete" x-cloak>
                    <span class="text-sm font-semibold text-primary">{{ __('Session complete!') }}</span>
                    <flux:button
                        x-show="kind === 'task'"
                        variant="ghost"
                        size="sm"
                        icon="check-circle"
                        class="shrink-0"
                        @click="markTaskDoneFromFocus()"
                    >
                        {{ __('Mark as Done') }}
                    </flux:button>
                    <flux:button
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

        {{-- Progress bar: only when session is active --}}
        <div
            x-show="isFocused"
            x-cloak
            class="h-2 w-full shrink-0 overflow-hidden rounded-full"
            role="progressbar"
            :aria-valuenow="Math.round(focusElapsedPercentValue)"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-label="{{ __('Time elapsed') }}"
        >
            <div
                class="block h-full min-w-0 rounded-full bg-blue-800 transition-[width] duration-300 ease-linear"
                :style="focusProgressStyle"
            ></div>
        </div>
    </div>
</div>

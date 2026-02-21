{{-- Focus bar: inside modal; uses parent listItemCard scope (no separate Alpine component). --}}
<div
    id="focus-modal-title"
    class="overflow-hidden"
>
    <div class="flex flex-col gap-3 px-4 py-3">
        {{-- Row 1: Sprint/Pomodoro tab — hidden when a focus or break session is active --}}
        <div
            class="w-full"
            x-show="!isFocused && !isBreakFocused"
            x-cloak
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
                    {{-- Ready: dynamic label by focusModeType (hide when any active session is running) --}}
                    <div class="flex flex-col gap-0.5" x-show="!isFocused && !isBreakFocused">
                        <div class="flex flex-col gap-0.5" x-show="focusModeType === 'countdown'">
                            <span class="text-sm font-semibold tracking-tight text-primary">{{ __('Focus mode') }} · {{ __('Sprint') }}</span>
                            <span class="max-w-sm text-xs leading-snug text-zinc-500">{{ __('Count down your set duration with no breaks.') }}</span>
                        </div>
                        <div class="flex flex-col gap-0.5" x-show="focusModeType === 'pomodoro'" x-cloak>
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-semibold tracking-tight text-primary">{{ __('Focus mode') }} · {{ __('Pomodoro') }}</span>
                                <flux:tooltip toggleable position="top">
                                    <flux:button icon="information-circle" size="sm" variant="ghost" />
                                    <flux:tooltip.content class="max-w-[20rem] space-y-2">
                                        <p x-text="pomodoroTooltipWhat"></p>
                                        <p x-text="pomodoroTooltipHow"></p>
                                    </flux:tooltip.content>
                                </flux:tooltip>
                            </div>
                            <span class="max-w-sm text-xs leading-snug text-zinc-500" x-text="pomodoroSummaryText"></span>
                        </div>
                        <div class="flex flex-col gap-0.5" x-show="focusModeType !== 'countdown' && focusModeType !== 'pomodoro'" x-cloak>
                            <span class="text-sm font-semibold tracking-tight text-primary">{{ __('Focus mode') }} · {{ __('Sprint') }}</span>
                            <span class="max-w-sm text-xs leading-snug text-zinc-500">{{ __('Count down your set duration with no breaks.') }}</span>
                        </div>
                    </div>
                    {{-- Active: show Pomodoro or Sprint depending on session --}}
                    <div class="flex flex-col gap-0.5" x-show="isFocused && activeFocusSession?.focus_mode_type === 'pomodoro' && activeFocusSession?.type === 'work'" x-cloak>
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold tracking-tight text-primary">{{ __('Focus mode') }} · {{ __('Pomodoro') }}</span>
                            <span class="text-xs font-medium text-zinc-500" x-text="pomodoroSequenceText"></span>
                        </div>
                        <span class="max-w-sm text-xs leading-snug text-zinc-500" x-text="pomodoroSummaryText"></span>
                    </div>
                    <div class="flex flex-col gap-0.5" x-show="isBreakFocused && activeFocusSession?.type === 'short_break'" x-cloak>
                        <span class="text-sm font-semibold tracking-tight text-primary">{{ __('Break time') }} · {{ __('Short Break') }}</span>
                        <span class="max-w-sm text-xs leading-snug text-zinc-500">{{ __('Take a short break to recharge.') }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5" x-show="isBreakFocused && activeFocusSession?.type === 'long_break'" x-cloak>
                        <span class="text-sm font-semibold tracking-tight text-primary">{{ __('Break time') }} · {{ __('Long Break') }}</span>
                        <span class="max-w-sm text-xs leading-snug text-zinc-500">{{ __('Take a longer break to rest and recharge.') }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5" x-show="isFocused && activeFocusSession?.focus_mode_type !== 'pomodoro'" x-cloak>
                        <span class="text-sm font-semibold tracking-tight text-primary">{{ __('Focus mode') }} · {{ __('Sprint') }}</span>
                        <span class="max-w-sm text-xs leading-snug text-zinc-500">{{ __('Count down your set duration with no breaks.') }}</span>
                    </div>
                </div>
            </div>
            {{-- Right: ready actions or timer actions (visibility only, no DOM swap) --}}
            <div class="flex shrink-0 items-center gap-3">
                {{-- Ready: duration + Start/Cancel --}}
                <div class="flex items-center gap-3" x-show="!isFocused && !isBreakFocused">
                    <span class="min-w-18 text-right text-base font-semibold tabular-nums text-primary" x-text="focusModeType === 'pomodoro' ? formattedPomodoroWorkDuration : formattedFocusReadyDuration"></span>
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
                            @click="closeFocusModal()"
                        >
                            {{ __('Cancel') }}
                        </flux:button>
                    </div>
                </div>
                {{-- Active: session complete (non-pomodoro only to avoid flicker with next-session UI) --}}
                <div
                    class="flex shrink-0 items-center gap-1"
                    x-show="(isFocused || isBreakFocused) && sessionComplete && !nextSessionInfo && !isPomodoroSession"
                    x-cloak
                >
                    <span class="text-sm font-semibold text-primary" x-text="isBreakFocused ? '{{ __('Break complete!') }}' : '{{ __('Session complete!') }}'"></span>
                    <flux:button
                        x-show="kind === 'task' && !isBreakFocused"
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
                {{-- Next session ready (pomodoro flow) --}}
                <div class="flex shrink-0 items-center gap-3" x-show="sessionComplete && nextSessionInfo && !nextSessionInfo.auto_start" x-cloak>
                    <div class="flex flex-col gap-0.5 min-w-0">
                        <span class="text-sm font-semibold text-primary" x-text="nextSessionInfo?.type === 'short_break' || nextSessionInfo?.type === 'long_break' ? '{{ __('Break ready!') }}' : '{{ __('Next pomodoro ready!') }}'"></span>
                        <div class="flex items-center gap-2 text-xs text-zinc-500">
                            <span x-text="(nextSessionInfo?.type === 'short_break' ? '{{ __('Short break') }}' : nextSessionInfo?.type === 'long_break' ? '{{ __('Long break') }}' : '{{ __('Pomodoro') }}') + (nextSessionInfo ? ' · ' + nextSessionDurationText : '')"></span>
                        </div>
                    </div>
                    <flux:button
                        variant="primary"
                        size="sm"
                        icon="play"
                        class="shrink-0"
                        @click="startNextSession(nextSessionInfo)"
                    >
                        <span x-text="nextSessionInfo?.type === 'short_break' || nextSessionInfo?.type === 'long_break' ? '{{ __('Start Break') }}' : '{{ __('Start Pomodoro') }}'"></span>
                    </flux:button>
                    <flux:button
                        variant="ghost"
                        size="sm"
                        icon="x-mark"
                        class="shrink-0"
                        @click="dismissCompletedFocus()"
                    >
                        {{ __('Skip') }}
                    </flux:button>
                </div>
                {{-- Auto-starting indicator --}}
                <div class="flex shrink-0 items-center gap-2" x-show="sessionComplete && nextSessionInfo && nextSessionInfo.auto_start" x-cloak>
                    <span class="text-sm font-medium text-primary">{{ __('Starting next session...') }}</span>
                    <div class="h-4 w-4 animate-spin rounded-full border-2 border-primary border-t-transparent"></div>
                </div>
            </div>
        </div>

        {{-- Row: Active session — centered timer + Pause/Resume/Stop --}}
        <div
            class="flex w-full flex-col items-center justify-center gap-4 py-2"
            x-show="(isFocused || isBreakFocused) && !sessionComplete"
            x-cloak
        >
            <span
                class="min-w-24 text-center text-4xl font-bold tabular-nums tracking-tight text-primary"
                x-text="focusCountdownText"
                aria-live="polite"
            ></span>
            <div class="flex shrink-0 items-center gap-1">
                <flux:button
                    x-show="isFocused && !focusIsPaused"
                    x-cloak
                    variant="ghost"
                    size="sm"
                    icon="pause"
                    class="shrink-0"
                    @click="pauseFocus()"
                >
                    {{ __('Pause') }}
                </flux:button>
                <flux:button
                    x-show="isFocused && focusIsPaused"
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

        {{-- Row 3: Pomodoro settings — always visible when Pomodoro selected and not focused --}}
        <div
            x-show="!isFocused && !isBreakFocused && focusModeType === 'pomodoro'"
            x-cloak
            class="flex flex-wrap items-end gap-4"
        >
            {{-- Duration inputs --}}
            <div class="flex flex-col gap-2">
                <label class="text-xs font-medium text-zinc-600 dark:text-zinc-400" x-text="pomodoroWorkLabel"></label>
                <flux:input
                    type="number"
                    min="{{ $pomodoroWorkMin ?? 1 }}"
                    max="{{ $pomodoroWorkMax ?? 120 }}"
                    x-model.number="pomodoroWorkMinutes"
                    class="w-14 text-sm tabular-nums"
                    inputmode="numeric"
                    @blur="savePomodoroSettings()"
                    @keydown.enter.prevent="savePomodoroSettings()"
                />
            </div>
            <div class="flex flex-col gap-2">
                <label class="text-xs font-medium text-zinc-600 dark:text-zinc-400" x-text="pomodoroShortBreakLabel"></label>
                <flux:input
                    type="number"
                    min="1"
                    max="{{ $pomodoroShortBreakMax ?? 60 }}"
                    x-model.number="pomodoroShortBreakMinutes"
                    class="w-14 text-sm tabular-nums"
                    inputmode="numeric"
                    @blur="savePomodoroSettings()"
                    @keydown.enter.prevent="savePomodoroSettings()"
                />
            </div>
            <div class="flex flex-col gap-2">
                <label class="text-xs font-medium text-zinc-600 dark:text-zinc-400" x-text="pomodoroLongBreakLabel"></label>
                <flux:input
                    type="number"
                    min="1"
                    max="{{ $pomodoroLongBreakMax ?? 60 }}"
                    x-model.number="pomodoroLongBreakMinutes"
                    class="w-14 text-sm tabular-nums"
                    inputmode="numeric"
                    @blur="savePomodoroSettings()"
                    @keydown.enter.prevent="savePomodoroSettings()"
                />
            </div>
            <div class="flex flex-col gap-2">
                <label class="text-xs font-medium text-zinc-600 dark:text-zinc-400">
                    <span x-text="pomodoroEveryLabel"></span>
                    <span class="ml-1 text-[10px] font-normal text-zinc-400">
                        ({{ __('min 2') }})
                    </span>
                </label>
                <flux:input
                    type="number"
                    min="{{ $pomodoroLongBreakAfterMin ?? 2 }}"
                    max="{{ $pomodoroLongBreakAfterMax ?? 10 }}"
                    x-model.number="pomodoroLongBreakAfter"
                    class="w-14 text-sm tabular-nums"
                    inputmode="numeric"
                    @blur="savePomodoroSettings()"
                    @keydown.enter.prevent="savePomodoroSettings()"
                />
            </div>
            {{-- Checkboxes and volume --}}
            <label class="flex cursor-pointer items-center gap-2 pb-1">
                <flux:checkbox x-model="pomodoroAutoStartBreak" @change="savePomodoroSettings()" />
                <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300 whitespace-nowrap" x-text="pomodoroAutoStartBreakLabel"></span>
            </label>
            <label class="flex cursor-pointer items-center gap-2 pb-1">
                <flux:checkbox x-model="pomodoroAutoStartPomodoro" @change="savePomodoroSettings()" />
                <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300 whitespace-nowrap" x-text="pomodoroAutoStartPomodoroLabel"></span>
            </label>
            <div class="flex items-center gap-2 pb-1">
                <label class="flex cursor-pointer items-center gap-2">
                    <flux:checkbox x-model="pomodoroSoundEnabled" @change="savePomodoroSettings()" />
                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300 whitespace-nowrap" x-text="pomodoroSoundLabel"></span>
                </label>
                <template x-if="pomodoroSoundEnabled">
                    <div class="flex items-center gap-1.5">
                        <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400 shrink-0" x-text="pomodoroVolumeLabel"></span>
                        <input
                            type="range"
                            min="0"
                            max="100"
                            x-model.number="pomodoroSoundVolume"
                            class="h-2 w-12 min-w-0 accent-primary"
                            aria-label="Volume"
                            @change="savePomodoroSettings()"
                        />
                        <span class="w-6 text-right text-xs tabular-nums text-zinc-600 dark:text-zinc-400 shrink-0" x-text="pomodoroSoundVolume + '%'"></span>
                    </div>
                </template>
            </div>
        </div>

        {{-- Progress bar: only when session is active (work or break) --}}
        <div
            x-show="isFocused || isBreakFocused"
            x-cloak
            class="h-2 w-full shrink-0 overflow-hidden rounded-full"
            role="progressbar"
            :aria-valuenow="Math.round(focusElapsedPercentValue)"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-label="{{ __('Time elapsed') }}"
        >
            <div
                class="block h-full min-w-0 rounded-full transition-[width,background-color] duration-300 ease-linear"
                :class="isBreakFocused ? 'bg-green-600' : 'bg-blue-800'"
                :style="focusProgressStyle"
            ></div>
        </div>
    </div>
</div>

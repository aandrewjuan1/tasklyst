{{-- Focus mode bar: ready state (Start/Cancel) or active session (timer, progress, Pause/Resume/Stop). Uses parent Alpine scope (listItemCard). --}}
@php
    $focusBarCloak = empty($hasActiveFocusOnThisTask ?? false);
@endphp
<div
    x-show="focusReady || isFocused"
    @if($focusBarCloak) x-cloak @endif
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0.7 -translate-y-0.5"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-200"
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
                    <div class="flex flex-col gap-0.5" x-show="isFocused && activeFocusSession?.focus_mode_type === 'pomodoro'" x-cloak>
                        <span class="text-sm font-semibold tracking-tight text-primary">{{ __('Focus mode') }} · {{ __('Pomodoro') }}</span>
                        <span class="max-w-sm text-xs leading-snug text-zinc-500" x-text="pomodoroSummaryText"></span>
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
                <div class="flex items-center gap-3" x-show="!isFocused">
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

        {{-- Row 3: Pomodoro settings — toggleable; only when Pomodoro selected and not focused --}}
        <div
            x-show="!isFocused && focusModeType === 'pomodoro'"
            x-cloak
            class="flex flex-col gap-3"
        >
            <button
                type="button"
                @click="pomodoroSettingsOpen = !pomodoroSettingsOpen"
                class="w-fit text-sm font-medium text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 rounded"
                x-text="pomodoroSettingsLabel + (pomodoroSettingsOpen ? ' ▲' : ' ▼')"
            ></button>
            <div
                x-show="pomodoroSettingsOpen"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="rounded-xl border border-zinc-200/80 bg-zinc-50/60 px-4 py-4 dark:border-zinc-600/50 dark:bg-zinc-800/30"
            >
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
                    {{-- Left: durations in 2x2 grid --}}
                    <div class="grid grid-cols-2 gap-3 md:gap-4">
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
                            <label class="text-xs font-medium text-zinc-600 dark:text-zinc-400" x-text="pomodoroEveryLabel"></label>
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
                    </div>
                    {{-- Right: options — row 1: auto-start x2, row 2: sound + notify, row 3: volume --}}
                    <div class="flex flex-col gap-3 rounded-lg border border-zinc-200/80 bg-white/80 px-4 py-3 dark:border-zinc-600/50 dark:bg-zinc-800/50 md:h-fit">
                        <div class="flex flex-wrap items-center gap-4 lg:gap-6">
                            <label class="flex cursor-pointer items-center gap-2">
                                <flux:checkbox x-model="pomodoroAutoStartBreak" @change="savePomodoroSettings()" />
                                <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300" x-text="pomodoroAutoStartBreakLabel"></span>
                            </label>
                            <label class="flex cursor-pointer items-center gap-2">
                                <flux:checkbox x-model="pomodoroAutoStartPomodoro" @change="savePomodoroSettings()" />
                                <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300" x-text="pomodoroAutoStartPomodoroLabel"></span>
                            </label>
                        </div>
                        <div class="flex flex-wrap items-center gap-4 lg:gap-6">
                            <label class="flex cursor-pointer items-center gap-2">
                                <flux:checkbox x-model="pomodoroSoundEnabled" @change="savePomodoroSettings()" />
                                <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300" x-text="pomodoroSoundLabel"></span>
                            </label>
                            <label class="flex cursor-pointer items-center gap-2">
                                <flux:checkbox x-model="pomodoroNotificationOnComplete" @change="savePomodoroSettings()" />
                                <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300" x-text="pomodoroNotificationLabel"></span>
                            </label>
                        </div>
                        <div class="flex items-center gap-3" x-show="pomodoroSoundEnabled">
                            <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400 shrink-0" x-text="pomodoroVolumeLabel"></span>
                            <input
                                type="range"
                                min="0"
                                max="100"
                                x-model.number="pomodoroSoundVolume"
                                class="h-2 flex-1 min-w-0 accent-primary"
                                aria-label="Volume"
                                @change="savePomodoroSettings()"
                            />
                            <span class="w-8 text-right text-sm tabular-nums text-zinc-600 dark:text-zinc-400 shrink-0" x-text="pomodoroSoundVolume + '%'"></span>
                        </div>
                    </div>
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

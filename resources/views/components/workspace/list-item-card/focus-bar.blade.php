{{-- Focus bar: inside modal; uses parent listItemCard scope (no separate Alpine component). --}}
<div
    id="focus-modal-title"
    class="focus-modal-focusbar overflow-hidden"
>
    <div class="focus-modal-focusbar-inner flex flex-col gap-4 px-3 py-3 sm:px-4 sm:py-4">
        {{-- Row 1: Sprint/Pomodoro tab — hidden when a focus or break session is active --}}
        <div
            class="focus-modal-mode-switch w-full"
            x-show="!isFocused && !isBreakFocused"
            x-cloak
        >
            <flux:radio.group
                variant="segmented"
                size="sm"
                x-model="focusModeType"
                class="w-full text-sm font-semibold"
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
        <div class="focus-modal-main-row flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            {{-- Left: mode description (same area, content toggled by state) --}}
            <div class="focus-modal-mode-copy min-w-0 flex-1 basis-0">
                <div class="flex flex-col gap-0.5">
                    {{-- Ready: dynamic label by focusModeType (hide when any active session is running) --}}
                    <div class="flex flex-col gap-0.5" x-show="!isFocused && !isBreakFocused">
                        <div class="flex flex-col gap-0.5" x-show="focusModeType === 'countdown'">
                            <span class="text-base font-bold tracking-tight text-foreground">{{ __('Focus mode') }} · {{ __('Sprint') }}</span>
                            <span class="max-w-md text-sm leading-snug text-zinc-700 dark:text-zinc-300">{{ __('Count down your set duration with no breaks.') }}</span>
                        </div>
                        <div class="flex flex-col gap-0.5" x-show="focusModeType === 'pomodoro'" x-cloak>
                            <div class="flex items-center gap-2">
                                <span class="text-base font-bold tracking-tight text-foreground">{{ __('Focus mode') }} · {{ __('Pomodoro') }}</span>
                                <flux:tooltip toggleable position="top">
                                    <flux:button icon="information-circle" size="sm" variant="ghost" />
                                    <flux:tooltip.content class="max-w-[20rem] space-y-2">
                                        <p x-text="pomodoroTooltipWhat"></p>
                                        <p x-text="pomodoroTooltipHow"></p>
                                    </flux:tooltip.content>
                                </flux:tooltip>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-full border border-border/60 bg-background/80 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    :aria-expanded="showPomodoroSettings ? 'true' : 'false'"
                                    aria-controls="focus-pomodoro-settings-panel"
                                    @click="showPomodoroSettings = !showPomodoroSettings"
                                >
                                    <flux:icon name="chevron-up" class="size-3" x-show="showPomodoroSettings" x-cloak />
                                    <flux:icon name="chevron-down" class="size-3" x-show="!showPomodoroSettings" />
                                    <span x-text="showPomodoroSettings ? '{{ __('Hide settings') }}' : '{{ __('Show settings') }}'"></span>
                                </button>
                            </div>
                            <span class="max-w-md text-sm leading-snug text-zinc-700 dark:text-zinc-300" x-text="pomodoroSummaryText"></span>
                        </div>
                        <div class="flex flex-col gap-0.5" x-show="focusModeType !== 'countdown' && focusModeType !== 'pomodoro'" x-cloak>
                            <span class="text-base font-bold tracking-tight text-foreground">{{ __('Focus mode') }} · {{ __('Sprint') }}</span>
                            <span class="max-w-md text-sm leading-snug text-zinc-700 dark:text-zinc-300">{{ __('Count down your set duration with no breaks.') }}</span>
                        </div>
                    </div>
                    {{-- Active: show Pomodoro or Sprint depending on session --}}
                    <div class="flex flex-col gap-0.5" x-show="isFocused && activeFocusSession?.focus_mode_type === 'pomodoro' && activeFocusSession?.type === 'work'" x-cloak>
                        <div class="flex items-center gap-2">
                            <span class="text-base font-bold tracking-tight text-foreground">{{ __('Focus mode') }} · {{ __('Pomodoro') }}</span>
                            <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300" x-text="pomodoroSequenceText"></span>
                        </div>
                        <span class="max-w-md text-sm leading-snug text-zinc-700 dark:text-zinc-300" x-text="pomodoroSummaryText"></span>
                    </div>
                    <div class="flex flex-col gap-0.5" x-show="isBreakFocused && activeFocusSession?.type === 'short_break'" x-cloak>
                        <span class="text-base font-bold tracking-tight text-foreground">{{ __('Break time') }} · {{ __('Short Break') }}</span>
                        <span class="max-w-md text-sm leading-snug text-zinc-700 dark:text-zinc-300">{{ __('Take a short break to recharge.') }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5" x-show="isBreakFocused && activeFocusSession?.type === 'long_break'" x-cloak>
                        <span class="text-base font-bold tracking-tight text-foreground">{{ __('Break time') }} · {{ __('Long Break') }}</span>
                        <span class="max-w-md text-sm leading-snug text-zinc-700 dark:text-zinc-300">{{ __('Take a longer break to rest and recharge.') }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5" x-show="isFocused && activeFocusSession?.focus_mode_type !== 'pomodoro'" x-cloak>
                        <span class="text-base font-bold tracking-tight text-foreground">{{ __('Focus mode') }} · {{ __('Sprint') }}</span>
                        <span class="max-w-md text-sm leading-snug text-zinc-700 dark:text-zinc-300">{{ __('Count down your set duration with no breaks.') }}</span>
                    </div>
                </div>
            </div>
            {{-- Right: ready actions or timer actions (visibility only, no DOM swap) --}}
            <div class="focus-modal-actions flex shrink-0 items-center gap-3">
                {{-- Ready: duration + Start/Cancel --}}
                <div class="focus-modal-ready-actions flex items-center gap-3" x-show="!isFocused && !isBreakFocused">
                    <span class="focus-modal-duration min-w-20 text-right text-base font-semibold tabular-nums text-primary" x-text="focusModeType === 'pomodoro' ? formattedPomodoroWorkDuration : formattedFocusReadyDuration"></span>
                    <div class="focus-modal-action-group flex shrink-0 items-center gap-1.5" x-show="!showFocusStartChoice">
                        <button
                            type="button"
                            class="focus-modal-btn focus-modal-primary-action shrink-0"
                            x-bind:disabled="!((focusModeTypes || []).find(t => t.value === focusModeType)?.available)"
                            @click="startFocusFromReady()"
                        >
                            <flux:icon name="play" class="size-3.5" />
                            <span>{{ __('Start') }}</span>
                        </button>
                        <flux:button
                            variant="ghost"
                            size="sm"
                            icon="x-mark"
                            class="focus-modal-btn focus-modal-btn--secondary shrink-0"
                            @click="closeFocusModal()"
                        >
                            {{ __('Cancel') }}
                        </flux:button>
                    </div>
                    <div class="focus-modal-action-group flex shrink-0 items-center gap-1.5" x-show="showFocusStartChoice" x-cloak>
                        <button
                            type="button"
                            class="focus-modal-btn focus-modal-primary-action shrink-0"
                            @click="chooseFocusStart('resume')"
                        >
                            <flux:icon name="play" class="size-3.5" />
                            <span>{{ __('Resume') }}</span>
                        </button>
                        <flux:button
                            variant="ghost"
                            size="sm"
                            icon="arrow-path"
                            class="focus-modal-btn focus-modal-btn--secondary shrink-0"
                            @click="chooseFocusStart('restart')"
                        >
                            {{ __('Restart') }}
                        </flux:button>
                    </div>
                </div>
                {{-- Active: session complete (non-pomodoro only to avoid flicker with next-session UI) --}}
                <div
                    class="focus-modal-complete-actions flex shrink-0 items-center gap-1"
                    x-show="(isFocused || isBreakFocused) && sessionComplete && !nextSessionInfo && !isPomodoroSession"
                    x-cloak
                >
                    <span class="text-sm font-semibold text-primary" x-text="isBreakFocused ? '{{ __('Break complete!') }}' : '{{ __('Session complete!') }}'"></span>
                    <flux:button
                        x-show="kind === 'task' && !isBreakFocused"
                        variant="ghost"
                        size="sm"
                        icon="check-circle"
                        class="focus-modal-btn focus-modal-btn--primary shrink-0"
                        @click="markTaskDoneFromFocus()"
                    >
                        {{ __('Mark as Done') }}
                    </flux:button>
                    <flux:button
                        variant="ghost"
                        size="sm"
                        icon="x-mark"
                        class="focus-modal-btn focus-modal-btn--secondary shrink-0"
                        @click="dismissCompletedFocus()"
                    >
                        {{ __('Close') }}
                    </flux:button>
                </div>
                {{-- Next session ready (pomodoro flow) --}}
                <div class="focus-modal-next-session flex shrink-0 items-center gap-3" x-show="sessionComplete && nextSessionInfo && !nextSessionInfo.auto_start" x-cloak>
                    <div class="flex flex-col gap-0.5 min-w-0">
                        <span class="text-sm font-semibold text-primary" x-text="nextSessionInfo?.type === 'short_break' || nextSessionInfo?.type === 'long_break' ? '{{ __('Break ready!') }}' : '{{ __('Next pomodoro ready!') }}'"></span>
                        <div class="flex items-center gap-2 text-xs text-zinc-500">
                            <span x-text="(nextSessionInfo?.type === 'short_break' ? '{{ __('Short break') }}' : nextSessionInfo?.type === 'long_break' ? '{{ __('Long break') }}' : '{{ __('Pomodoro') }}') + (nextSessionInfo ? ' · ' + nextSessionDurationText : '')"></span>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="focus-modal-btn focus-modal-primary-action shrink-0"
                        @click="startNextSession(nextSessionInfo)"
                    >
                        <flux:icon name="play" class="size-3.5" />
                        <span x-text="nextSessionInfo?.type === 'short_break' || nextSessionInfo?.type === 'long_break' ? '{{ __('Start Break') }}' : '{{ __('Start Pomodoro') }}'"></span>
                    </button>
                    <flux:button
                        variant="ghost"
                        size="sm"
                        icon="x-mark"
                        class="focus-modal-btn focus-modal-btn--secondary shrink-0"
                        @click="dismissCompletedFocus()"
                    >
                        {{ __('Skip') }}
                    </flux:button>
                    <flux:button
                        x-show="kind === 'task'"
                        variant="ghost"
                        size="sm"
                        icon="check-circle"
                        class="focus-modal-btn focus-modal-btn--secondary shrink-0"
                        @click="markTaskDoneFromFocus()"
                    >
                        {{ __('Mark as Done') }}
                    </flux:button>
                </div>
                {{-- Auto-starting indicator --}}
                <div class="focus-modal-autostart flex shrink-0 items-center gap-2" x-show="sessionComplete && nextSessionInfo && nextSessionInfo.auto_start" x-cloak>
                    <span class="text-sm font-medium text-primary">{{ __('Starting next session...') }}</span>
                    <div class="h-4 w-4 animate-spin rounded-full border-2 border-primary border-t-transparent"></div>
                </div>
            </div>
        </div>

        {{-- Row: Task progress (ready state only, separate row for readability) --}}
        <div
            x-show="!isFocused && !isBreakFocused && shouldShowTaskProgress"
            x-cloak
            class="focus-modal-task-progress w-full"
        >
            <div class="space-y-1.5">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-xs font-medium text-zinc-600 dark:text-zinc-300">{{ __('Task progress') }}</span>
                    <span class="text-xs tabular-nums text-zinc-600 dark:text-zinc-300" x-text="taskFocusProgressPercentText"></span>
                </div>
                <div class="h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" :aria-valuenow="Math.round(taskFocusProgressPercentTotal)" aria-valuemin="0" aria-valuemax="100" aria-label="{{ __('Task progress') }}">
                    <div
                        class="block h-full min-w-0 rounded-full bg-blue-800 transition-[width,background-color] duration-300 ease-linear"
                        :style="'width: ' + Math.round(taskFocusProgressPercentTotal) + '%; min-width: ' + (Math.round(taskFocusProgressPercentTotal) > 0 ? '2px' : '0')"
                    ></div>
                </div>
                <span class="text-xs text-zinc-500" x-text="taskFocusRemainingText + ' {{ __('left') }}'"></span>
            </div>
        </div>

        {{-- Row: Active session — centered timer + Pause/Resume/Stop --}}
        <div
            class="focus-modal-timer-zone flex w-full flex-col items-center justify-center gap-4 py-2"
            x-show="(isFocused || isBreakFocused) && !sessionComplete"
            x-cloak
        >
            <span
                class="focus-modal-timer min-w-24 text-center text-4xl font-bold tabular-nums tracking-tight text-primary sm:text-5xl"
                x-text="focusCountdownText"
                aria-live="polite"
            ></span>
            <div class="focus-modal-timer-actions flex shrink-0 items-center gap-1">
                <flux:button
                    x-show="isFocused && !focusIsPaused"
                    x-cloak
                    variant="ghost"
                    size="sm"
                    icon="pause"
                    class="focus-modal-btn focus-modal-btn--secondary shrink-0"
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
                    class="focus-modal-btn focus-modal-primary-action shrink-0"
                    @click="resumeFocus()"
                >
                    {{ __('Resume') }}
                </flux:button>
                <flux:button
                    variant="ghost"
                    size="sm"
                    icon="x-mark"
                    class="focus-modal-btn focus-modal-btn--secondary shrink-0"
                    @click="stopFocus()"
                >
                    {{ __('Stop') }}
                </flux:button>
            </div>
        </div>

        {{-- Row 3: Pomodoro settings — always visible when Pomodoro selected and not focused --}}
        <div
            id="focus-pomodoro-settings-panel"
            x-show="!isFocused && !isBreakFocused && focusModeType === 'pomodoro' && showPomodoroSettings"
            x-cloak
            class="focus-modal-pomodoro-settings flex w-full flex-col gap-2 rounded-xl border border-zinc-200/70 bg-zinc-50/80 p-2.5 dark:border-zinc-700/70 dark:bg-zinc-900/50"
        >
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div class="flex min-w-0 flex-col gap-1">
                        <label class="text-xs font-medium text-zinc-600 dark:text-zinc-400" x-text="pomodoroWorkLabel"></label>
                        <flux:input
                            type="number"
                            min="{{ $pomodoroWorkMin ?? 1 }}"
                            max="{{ $pomodoroWorkMax ?? 120 }}"
                            x-model.number="pomodoroWorkMinutes"
                            class="w-full text-xs tabular-nums"
                            inputmode="numeric"
                            @blur="savePomodoroSettings()"
                            @keydown.enter.prevent="savePomodoroSettings()"
                        />
                    </div>
                    <div class="flex min-w-0 flex-col gap-1">
                        <label class="text-xs font-medium text-zinc-600 dark:text-zinc-400" x-text="pomodoroShortBreakLabel"></label>
                        <flux:input
                            type="number"
                            min="1"
                            max="{{ $pomodoroShortBreakMax ?? 60 }}"
                            x-model.number="pomodoroShortBreakMinutes"
                            class="w-full text-xs tabular-nums"
                            inputmode="numeric"
                            @blur="savePomodoroSettings()"
                            @keydown.enter.prevent="savePomodoroSettings()"
                        />
                    </div>
                    <div class="flex min-w-0 flex-col gap-1">
                        <label class="text-xs font-medium text-zinc-600 dark:text-zinc-400" x-text="pomodoroLongBreakLabel"></label>
                        <flux:input
                            type="number"
                            min="1"
                            max="{{ $pomodoroLongBreakMax ?? 60 }}"
                            x-model.number="pomodoroLongBreakMinutes"
                            class="w-full text-xs tabular-nums"
                            inputmode="numeric"
                            @blur="savePomodoroSettings()"
                            @keydown.enter.prevent="savePomodoroSettings()"
                        />
                    </div>
                    <div class="flex min-w-0 flex-col gap-1">
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
                            class="w-full text-xs tabular-nums"
                            inputmode="numeric"
                            @blur="savePomodoroSettings()"
                            @keydown.enter.prevent="savePomodoroSettings()"
                        />
                    </div>
                </div>

            <div class="flex flex-wrap items-center gap-3 border-t border-zinc-200/70 pt-2 dark:border-zinc-700/70">
                <label class="flex cursor-pointer items-center gap-1.5">
                    <flux:checkbox x-model="pomodoroAutoStartBreak" @change="savePomodoroSettings()" />
                    <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300 whitespace-nowrap" x-text="pomodoroAutoStartBreakLabel"></span>
                </label>
                <label class="flex cursor-pointer items-center gap-1.5">
                    <flux:checkbox x-model="pomodoroAutoStartPomodoro" @change="savePomodoroSettings()" />
                    <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300 whitespace-nowrap" x-text="pomodoroAutoStartPomodoroLabel"></span>
                </label>
                <label class="flex cursor-pointer items-center gap-1.5">
                    <flux:checkbox x-model="pomodoroSoundEnabled" @change="savePomodoroSettings()" />
                    <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300 whitespace-nowrap" x-text="pomodoroSoundLabel"></span>
                </label>
                <template x-if="pomodoroSoundEnabled">
                    <div class="flex min-w-0 items-center gap-1.5">
                        <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400 shrink-0" x-text="pomodoroVolumeLabel"></span>
                        <input
                            type="range"
                            min="0"
                            max="100"
                            x-model.number="pomodoroSoundVolume"
                            class="h-1.5 min-w-0 w-24 accent-primary"
                            aria-label="Volume"
                            @change="savePomodoroSettings()"
                        />
                        <span class="w-8 text-right text-[11px] tabular-nums text-zinc-600 dark:text-zinc-400 shrink-0" x-text="pomodoroSoundVolume + '%'"></span>
                    </div>
                </template>
            </div>
        </div>

        {{-- Progress bar: only when session is active (work or break) --}}
        <div
            x-show="isFocused || isBreakFocused"
            x-cloak
            class="focus-modal-elapsed h-2 w-full shrink-0 overflow-hidden rounded-full"
            role="progressbar"
            :aria-valuenow="Math.round(focusElapsedPercentValue)"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-label="{{ __('Time elapsed') }}"
        >
            <div
                class="block h-full min-w-0 rounded-full transition-[width,background-color] duration-300 ease-linear"
                :class="isBreakFocused ? 'bg-green-600' : 'bg-blue-800'"
                :style="'width: ' + (focusElapsedPercentValue ?? 0) + '%; min-width: ' + ((focusElapsedPercentValue ?? 0) > 0 ? '2px' : '0')"
            ></div>
        </div>
    </div>
</div>

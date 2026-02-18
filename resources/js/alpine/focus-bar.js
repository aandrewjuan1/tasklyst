/**
 * Alpine sub-component for the list-item-card focus bar.
 * Reads state and methods from the card via Alpine.store('listItemCards')[itemId].
 * Use in Blade: x-data="focusBar({ itemId: $itemId })"
 *
 * @param {{ itemId: string|number }} config
 * @returns {Object} Alpine component (delegates to card)
 */
export function focusBar(config) {
    const itemId = config?.itemId;
    return {
        get card() {
            return window.Alpine?.store?.('listItemCards')?.[itemId] ?? null;
        },
        get focusReady() { return this.card?.focusReady; },
        set focusReady(v) { if (this.card != null) this.card.focusReady = v; },
        get isFocused() { return this.card?.isFocused; },
        get isBreakFocused() { return this.card?.isBreakFocused; },
        get focusModeType() { return this.card?.focusModeType; },
        set focusModeType(v) { if (this.card != null) this.card.focusModeType = v; },
        get focusModeTypes() { return this.card?.focusModeTypes; },
        get pomodoroTooltipWhat() { return this.card?.pomodoroTooltipWhat; },
        get pomodoroTooltipHow() { return this.card?.pomodoroTooltipHow; },
        get pomodoroSummaryText() { return this.card?.pomodoroSummaryText; },
        get pomodoroSequenceText() { return this.card?.pomodoroSequenceText; },
        get formattedPomodoroWorkDuration() { return this.card?.formattedPomodoroWorkDuration; },
        get formattedFocusReadyDuration() { return this.card?.formattedFocusReadyDuration; },
        startFocusFromReady() { return this.card?.startFocusFromReady(); },
        get focusCountdownText() { return this.card?.focusCountdownText; },
        get focusProgressStyle() { return this.card?.focusProgressStyle; },
        get focusElapsedPercentValue() { return this.card?.focusElapsedPercentValue; },
        get sessionComplete() { return this.card?.sessionComplete; },
        get nextSessionInfo() { return this.card?.nextSessionInfo; },
        get nextSessionDurationText() { return this.card?.nextSessionDurationText; },
        pauseFocus() { return this.card?.pauseFocus(); },
        resumeFocus() { return this.card?.resumeFocus(); },
        stopFocus() { return this.card?.stopFocus(); },
        dismissCompletedFocus() { return this.card?.dismissCompletedFocus(); },
        markTaskDoneFromFocus() { return this.card?.markTaskDoneFromFocus(); },
        startNextSession(info) { return this.card?.startNextSession(info); },
        get focusRemainingSeconds() { return this.card?.focusRemainingSeconds; },
        getFocusPausedSecondsTotal() { return this.card?.getFocusPausedSecondsTotal(); },
        get focusIsPaused() { return this.card?.focusIsPaused; },
        get kind() { return this.card?.kind; },
        get isPomodoroSession() { return this.card?.isPomodoroSession; },
        get pomodoroSettingsOpen() { return this.card?.pomodoroSettingsOpen; },
        set pomodoroSettingsOpen(v) { if (this.card != null) this.card.pomodoroSettingsOpen = v; },
        get pomodoroSettingsLabel() { return this.card?.pomodoroSettingsLabel; },
        get pomodoroWorkMinutes() { return this.card?.pomodoroWorkMinutes; },
        set pomodoroWorkMinutes(v) { if (this.card != null) this.card.pomodoroWorkMinutes = v; },
        get pomodoroShortBreakMinutes() { return this.card?.pomodoroShortBreakMinutes; },
        set pomodoroShortBreakMinutes(v) { if (this.card != null) this.card.pomodoroShortBreakMinutes = v; },
        get pomodoroLongBreakMinutes() { return this.card?.pomodoroLongBreakMinutes; },
        set pomodoroLongBreakMinutes(v) { if (this.card != null) this.card.pomodoroLongBreakMinutes = v; },
        get pomodoroLongBreakAfter() { return this.card?.pomodoroLongBreakAfter; },
        set pomodoroLongBreakAfter(v) { if (this.card != null) this.card.pomodoroLongBreakAfter = v; },
        get pomodoroAutoStartBreak() { return this.card?.pomodoroAutoStartBreak; },
        set pomodoroAutoStartBreak(v) { if (this.card != null) this.card.pomodoroAutoStartBreak = v; },
        get pomodoroAutoStartPomodoro() { return this.card?.pomodoroAutoStartPomodoro; },
        set pomodoroAutoStartPomodoro(v) { if (this.card != null) this.card.pomodoroAutoStartPomodoro = v; },
        get pomodoroSoundEnabled() { return this.card?.pomodoroSoundEnabled; },
        set pomodoroSoundEnabled(v) { if (this.card != null) this.card.pomodoroSoundEnabled = v; },
        get pomodoroNotificationOnComplete() { return this.card?.pomodoroNotificationOnComplete; },
        set pomodoroNotificationOnComplete(v) { if (this.card != null) this.card.pomodoroNotificationOnComplete = v; },
        get pomodoroSoundVolume() { return this.card?.pomodoroSoundVolume; },
        set pomodoroSoundVolume(v) { if (this.card != null) this.card.pomodoroSoundVolume = v; },
        savePomodoroSettings() { return this.card?.savePomodoroSettings(); },
        get pomodoroWorkLabel() { return this.card?.pomodoroWorkLabel; },
        get pomodoroShortBreakLabel() { return this.card?.pomodoroShortBreakLabel; },
        get pomodoroLongBreakLabel() { return this.card?.pomodoroLongBreakLabel; },
        get pomodoroEveryLabel() { return this.card?.pomodoroEveryLabel; },
        get pomodoroAutoStartBreakLabel() { return this.card?.pomodoroAutoStartBreakLabel; },
        get pomodoroAutoStartPomodoroLabel() { return this.card?.pomodoroAutoStartPomodoroLabel; },
        get pomodoroSoundLabel() { return this.card?.pomodoroSoundLabel; },
        get pomodoroNotificationLabel() { return this.card?.pomodoroNotificationLabel; },
        get pomodoroVolumeLabel() { return this.card?.pomodoroVolumeLabel; },
    };
}

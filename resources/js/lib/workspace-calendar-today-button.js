/**
 * Keeps the sidebar "Jump to today" control in sync with Livewire using the DOM `disabled`
 * property (no Alpine :disabled), so the state tracks `$wire` after every morph.
 *
 * Rule: enable whenever the selected day is not "today", or the user is browsing another
 * month while the selection is still today (jump snaps the grid). Disable only when
 * selected === today and not browsing.
 */

function findWireWithSelectedDate(button) {
    let el = button.closest('[wire\\:id]');

    while (el) {
        const id = el.getAttribute('wire:id');
        const wire = window.Livewire?.find?.(id);
        if (wire != null && wire.selectedDate !== undefined) {
            return wire;
        }
        el = el.parentElement?.closest('[wire\\:id]');
    }

    return null;
}

export function syncWorkspaceCalendarTodayButton() {
    const btn = document.querySelector('[data-testid="calendar-jump-to-today"]');
    if (!btn || typeof window.Livewire?.find !== 'function') {
        return;
    }

    const todayYmd = btn.getAttribute('data-app-today');
    if (!todayYmd) {
        return;
    }

    const wire = findWireWithSelectedDate(btn);
    if (!wire) {
        return;
    }

    const selected = wire.selectedDate;
    const wy = wire.calendarViewYear;
    const wm = wire.calendarViewMonth;
    const browsing = wy != null && wm != null;
    const selectedIsToday = selected === todayYmd;
    const disable = selectedIsToday && !browsing;

    btn.disabled = disable;
    btn.setAttribute('aria-disabled', disable ? 'true' : 'false');
}

export function initWorkspaceCalendarTodayButtonSync() {
    if (typeof document === 'undefined' || typeof window.Livewire?.hook !== 'function') {
        return;
    }

    const run = () => {
        syncWorkspaceCalendarTodayButton();
    };

    window.Livewire.hook('morph.updated', run);
    document.addEventListener('livewire:initialized', run);
    document.addEventListener('livewire:navigated', run);
}

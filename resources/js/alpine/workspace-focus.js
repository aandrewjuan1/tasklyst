/**
 * Scroll + highlight list rows for ?task= / ?event= / ?project= deep links.
 * Focus query params are consumed after first successful focus so reload does
 * not repeatedly auto-scroll/highlight.
 */

const FOCUS_RETRY_MAX = 40;
const FOCUS_RETRY_MS = 50;

const WORKSPACE_ROW_FLASH_CLASS = 'workspace-row-flash';

/** Match {@see workspace-row-flash} animation duration in app.css + small buffer. */
const WORKSPACE_ROW_FLASH_MS = 2100;

/**
 * Remove one-time focus params after they are consumed.
 */
export function consumeWorkspaceFocusQueryParams() {
    const url = new URL(window.location.href);
    const hasFocusParam = ['task', 'event', 'project'].some((param) => url.searchParams.has(param));

    if (!hasFocusParam) {
        return;
    }

    url.searchParams.delete('task');
    url.searchParams.delete('event');
    url.searchParams.delete('project');

    const nextUrl = `${url.pathname}${url.search}${url.hash}`;
    window.history.replaceState(window.history.state, '', nextUrl);
}

function focusWorkspaceElement(el) {
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    el.scrollIntoView({
        behavior: reducedMotion ? 'auto' : 'smooth',
        block: 'center',
        inline: 'nearest',
    });

    el.classList.remove(WORKSPACE_ROW_FLASH_CLASS);
    void el.offsetWidth;
    el.classList.add(WORKSPACE_ROW_FLASH_CLASS);
    window.setTimeout(() => {
        el.classList.remove(WORKSPACE_ROW_FLASH_CLASS);
    }, WORKSPACE_ROW_FLASH_MS);
}

function runWorkspaceFocusBySelectorId(selectorId, shouldConsumeQuery = false) {
    let el = document.getElementById(selectorId);
    if (el) {
        focusWorkspaceElement(el);
        if (shouldConsumeQuery) {
            consumeWorkspaceFocusQueryParams();
        }

        return;
    }

    let attempts = 0;
    const timer = window.setInterval(() => {
        attempts++;
        el = document.getElementById(selectorId);
        if (el) {
            window.clearInterval(timer);
            focusWorkspaceElement(el);
            if (shouldConsumeQuery) {
                consumeWorkspaceFocusQueryParams();
            }

            return;
        }
        if (attempts >= FOCUS_RETRY_MAX) {
            window.clearInterval(timer);
        }
    }, FOCUS_RETRY_MS);
}

/**
 * When the target list row is already in the DOM (e.g. workspace calendar click),
 * scroll + highlight immediately without waiting for Livewire pagination expansion.
 *
 * @returns {boolean} true if the row was found and focused
 */
export function workspaceCalendarTryInstantFocus(kind, id) {
    const el = document.getElementById(`workspace-item-${kind}-${id}`);
    if (!el) {
        return false;
    }

    focusWorkspaceElement(el);

    return true;
}

export function runWorkspaceFocusFromUrl() {
    const params = new URL(window.location.href).searchParams;

    let kind = null;
    let rawId = null;
    if (params.has('task')) {
        kind = 'task';
        rawId = params.get('task');
    } else if (params.has('event')) {
        kind = 'event';
        rawId = params.get('event');
    } else if (params.has('project')) {
        kind = 'project';
        rawId = params.get('project');
    }

    if (!kind || rawId === null || rawId === '') {
        return;
    }

    const id = parseInt(String(rawId), 10);
    if (!Number.isFinite(id) || id <= 0) {
        return;
    }

    const selectorId = `workspace-item-${kind}-${id}`;
    runWorkspaceFocusBySelectorId(selectorId, true);
}

export function runWorkspaceFocusToTarget(kind, rawId) {
    if (!['task', 'event', 'project'].includes(kind)) {
        return;
    }

    const id = parseInt(String(rawId), 10);
    if (!Number.isFinite(id) || id <= 0) {
        return;
    }

    runWorkspaceFocusBySelectorId(`workspace-item-${kind}-${id}`, false);
}

export function initWorkspaceDeepLinkFocus() {
    const schedule = () => {
        requestAnimationFrame(() => {
            setTimeout(() => runWorkspaceFocusFromUrl(), 0);
        });
    };

    document.addEventListener('livewire:navigated', schedule);
    if (document.readyState === 'complete') {
        schedule();
    } else {
        window.addEventListener('load', schedule, { once: true });
    }
}

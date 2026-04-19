import { listItemCard } from './alpine/list-item-card.js';
import { workspaceCalendar } from './alpine/workspace-calendar.js';
import * as listRelevance from './lib/list-relevance.js';
import { kanbanBoard } from './alpine/kanban-board.js';
import { dashboardAnalyticsCharts } from './alpine/dashboard-analytics-charts.js';
import { consumeWorkspaceFocusQueryParams, initWorkspaceDeepLinkFocus, runWorkspaceFocusFromUrl, runWorkspaceFocusToTarget, workspaceCalendarTryInstantFocus } from './alpine/workspace-focus.js';
import { initWorkspaceCalendarTodayButtonSync } from './lib/workspace-calendar-today-button.js';
import { makeSchoolClassTime } from './alpine/school-class-time.js';

// ECharts is lazy-loaded from dashboard-analytics-charts.js (dynamic import) to keep the main bundle smaller.

// listItemCard registers itself in Alpine.store('listItemCards')[itemId] for focus-session escape handler etc.
document.addEventListener('livewire:init', () => {
    window.__tasklystListRelevance = listRelevance;
    window.__tasklystLoggingOut = false;
    window.Alpine.data('listItemCard', listItemCard);
    window.Alpine.data('workspaceCalendar', workspaceCalendar);
    window.Alpine.data('kanbanBoard', kanbanBoard);
    window.Alpine.data('dashboardAnalyticsCharts', dashboardAnalyticsCharts);
    window.Alpine.data('schoolClassTimeStart', () => makeSchoolClassTime('start'));
    window.Alpine.data('schoolClassTimeEnd', () => makeSchoolClassTime('end'));
    window.runWorkspaceFocusFromUrl = runWorkspaceFocusFromUrl;
    window.runWorkspaceFocusToTarget = runWorkspaceFocusToTarget;
    window.workspaceCalendarTryInstantFocus = workspaceCalendarTryInstantFocus;
    window.workspaceConsumeFocusQueryParams = consumeWorkspaceFocusQueryParams;
    initWorkspaceDeepLinkFocus();
    initWorkspaceCalendarTodayButtonSync();

    if (typeof window.Livewire?.interceptRequest === 'function') {
        window.Livewire.interceptRequest(({ onError }) => {
            onError(({ response, preventDefault }) => {
                if (!window.__tasklystLoggingOut) {
                    return;
                }

                if (response?.status !== 419) {
                    return;
                }

                preventDefault();
                window.location.href = '/login';
            });
        });
    }
});

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';

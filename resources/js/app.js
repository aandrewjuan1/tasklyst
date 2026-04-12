import { listItemCard } from './alpine/list-item-card.js';
import * as listRelevance from './lib/list-relevance.js';
import { kanbanBoard } from './alpine/kanban-board.js';
import { dashboardAnalyticsCharts } from './alpine/dashboard-analytics-charts.js';
import { initWorkspaceDeepLinkFocus, runWorkspaceFocusFromUrl, workspaceCalendarTryInstantFocus } from './alpine/workspace-focus.js';

// ECharts is lazy-loaded from dashboard-analytics-charts.js (dynamic import) to keep the main bundle smaller.

// listItemCard registers itself in Alpine.store('listItemCards')[itemId] for focus-session escape handler etc.
document.addEventListener('livewire:init', () => {
    window.__tasklystListRelevance = listRelevance;
    window.Alpine.data('listItemCard', listItemCard);
    window.Alpine.data('kanbanBoard', kanbanBoard);
    window.Alpine.data('dashboardAnalyticsCharts', dashboardAnalyticsCharts);
    window.runWorkspaceFocusFromUrl = runWorkspaceFocusFromUrl;
    window.workspaceCalendarTryInstantFocus = workspaceCalendarTryInstantFocus;
    initWorkspaceDeepLinkFocus();
});

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';

const FEED_HEALTH_SHARED = {
    lastLoadedAt: 0,
    feedHealth: [],
};

let sharedFeedHealthLoadPromise = null;

/**
 * Brightspace calendar feeds popover (Alpine + Livewire $wire).
 *
 * @param {object} initial
 * @param {number} initial.importPastMonths
 * @param {number} initial.importPastMonthsSaved
 * @param {number[]} initial.importPastChoices
 * @param {Record<string, string>} initial.importPastMonthLabels
 * @param {Record<string, string>} initial.strings
 */
export function calendarFeedsPopover(initial) {
    const strings = initial.strings ?? {};

    return {
        open: false,
        placementVertical: 'bottom',
        placementHorizontal: 'end',
        panelHeightEst: 320,
        panelWidthEst: 320,
        panelPlacementClassesValue: 'absolute top-full right-0 mt-1',

        feedUrl: '',
        feedName: '',
        connecting: false,
        inlineError: '',

        feedHealth: [],
        loadingFeedHealth: false,
        lastFeedHealthLoadedAt: 0,
        feedHealthRefreshWindowMs: 30000,
        syncingIds: new Set(),
        disconnectingIds: new Set(),
        editingFeedId: null,
        editingFeedName: '',
        editingFeedNameSnapshot: '',
        savingFeedName: false,
        savedFeedNameViaEnter: false,
        justCanceledFeedName: false,

        importPastMonths: Number(initial.importPastMonths) || 3,
        importPastMonthsSaved: Number(initial.importPastMonthsSaved) || Number(initial.importPastMonths) || 3,
        importPastMonthsSaving: false,
        importPastChoices: Array.isArray(initial.importPastChoices) ? initial.importPastChoices : [1, 3, 6],
        importPastMonthLabels: initial.importPastMonthLabels && typeof initial.importPastMonthLabels === 'object'
            ? initial.importPastMonthLabels
            : {},

        init() {
            this.$nextTick(() => {
                const load = () => void this.ensureFeedHealthLoaded(false);

                if (typeof window.requestIdleCallback === 'function') {
                    window.requestIdleCallback(load, { timeout: 2500 });
                } else {
                    window.setTimeout(load, 0);
                }
            });
        },

        importPastSavedLabel() {
            const key = String(this.importPastMonthsSaved);
            const map = this.importPastMonthLabels || {};

            return map[key] ?? key;
        },

        statusClass(status) {
            if (status === 'fresh') {
                return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200';
            }
            if (status === 'stale') {
                return 'bg-amber-100 text-amber-900 dark:bg-amber-950/50 dark:text-amber-200';
            }
            if (status === 'critical') {
                return 'bg-red-100 text-red-800 dark:bg-red-950/50 dark:text-red-200';
            }
            if (status === 'sync_off') {
                return 'bg-zinc-200 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200';
            }

            return 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200';
        },

        async ensureFeedHealthLoaded(force = false) {
            const wire = this.$wire;
            const nowMs = Date.now();

            if (force) {
                FEED_HEALTH_SHARED.lastLoadedAt = 0;
                FEED_HEALTH_SHARED.feedHealth = [];
            }

            const sharedFresh = FEED_HEALTH_SHARED.lastLoadedAt > 0
                && (nowMs - FEED_HEALTH_SHARED.lastLoadedAt) < this.feedHealthRefreshWindowMs;

            if (!force && sharedFresh) {
                this.feedHealth = [...FEED_HEALTH_SHARED.feedHealth];
                this.lastFeedHealthLoadedAt = FEED_HEALTH_SHARED.lastLoadedAt;

                return;
            }

            const localFresh = this.lastFeedHealthLoadedAt > 0
                && (nowMs - this.lastFeedHealthLoadedAt) < this.feedHealthRefreshWindowMs;

            if (!force && localFresh) {
                return;
            }

            if (!force && this.feedHealth && this.feedHealth.length > 0) {
                return;
            }

            if (sharedFeedHealthLoadPromise) {
                this.loadingFeedHealth = true;
                try {
                    await sharedFeedHealthLoadPromise;
                    this.feedHealth = [...FEED_HEALTH_SHARED.feedHealth];
                    this.lastFeedHealthLoadedAt = FEED_HEALTH_SHARED.lastLoadedAt;
                } catch {
                    this.feedHealth = [];
                } finally {
                    this.loadingFeedHealth = false;
                }

                return;
            }

            if (this.loadingFeedHealth) {
                return;
            }

            this.loadingFeedHealth = true;

            sharedFeedHealthLoadPromise = (async () => {
                const result = await wire.$call('loadCalendarFeedHealth');
                const arr = Array.isArray(result) ? result : [];
                FEED_HEALTH_SHARED.feedHealth = arr;
                FEED_HEALTH_SHARED.lastLoadedAt = Date.now();

                return arr;
            })();

            try {
                await sharedFeedHealthLoadPromise;
                this.feedHealth = [...FEED_HEALTH_SHARED.feedHealth];
                this.lastFeedHealthLoadedAt = FEED_HEALTH_SHARED.lastLoadedAt;
            } catch {
                this.feedHealth = [];
                FEED_HEALTH_SHARED.feedHealth = [];
            } finally {
                this.loadingFeedHealth = false;
                sharedFeedHealthLoadPromise = null;
            }
        },

        toggle() {
            if (this.open) {
                return this.close(this.$refs.button);
            }

            const vh = window.innerHeight;
            const vw = window.innerWidth;

            if (vw <= 480) {
                this.placementVertical = 'bottom';
                this.placementHorizontal = 'center';
                this.panelPlacementClassesValue = 'fixed inset-x-3 bottom-4 max-h-[min(70vh,22rem)]';
                this.open = true;
                this.$dispatch('dropdown-opened');
                void this.ensureFeedHealthLoaded(false);
                this.$nextTick(() => this.$refs.urlInput && this.$refs.urlInput.focus());

                return;
            }

            const rect = this.$refs.button.getBoundingClientRect();
            const contentLeft = vw < 768 ? 16 : 320;
            const effectivePanelWidth = Math.min(this.panelWidthEst, vw - 32);
            const spaceBelow = vh - rect.bottom;
            const spaceAbove = rect.top;

            this.placementVertical = (spaceBelow >= this.panelHeightEst || spaceBelow >= spaceAbove) ? 'bottom' : 'top';

            const endFits = rect.right <= vw && rect.right - effectivePanelWidth >= contentLeft;
            const startFits = rect.left >= contentLeft && rect.left + effectivePanelWidth <= vw;

            if (rect.left < contentLeft) {
                this.placementHorizontal = 'start';
            } else if (endFits) {
                this.placementHorizontal = 'end';
            } else if (startFits) {
                this.placementHorizontal = 'start';
            } else {
                this.placementHorizontal = rect.right > vw ? 'start' : 'end';
            }

            const v = this.placementVertical;
            const h = this.placementHorizontal;
            if (v === 'top' && h === 'end') {
                this.panelPlacementClassesValue = 'absolute bottom-full right-0 mb-1';
            } else if (v === 'top' && h === 'start') {
                this.panelPlacementClassesValue = 'absolute bottom-full left-0 mb-1';
            } else if (v === 'bottom' && h === 'end') {
                this.panelPlacementClassesValue = 'absolute top-full right-0 mt-1';
            } else if (v === 'bottom' && h === 'start') {
                this.panelPlacementClassesValue = 'absolute top-full left-0 mt-1';
            } else {
                this.panelPlacementClassesValue = 'absolute top-full right-0 mt-1';
            }

            this.open = true;
            this.$dispatch('dropdown-opened');
            void this.ensureFeedHealthLoaded(false);
            this.$nextTick(() => this.$refs.urlInput && this.$refs.urlInput.focus());
        },

        close(focusAfter) {
            if (!this.open) {
                return;
            }

            this.open = false;
            setTimeout(() => this.$dispatch('dropdown-closed'), 50);
            focusAfter && focusAfter.focus && focusAfter.focus();
        },

        async connectFeed() {
            if (this.connecting) {
                return;
            }

            const url = (this.feedUrl || '').trim();
            const name = (this.feedName || '').trim();
            const brightspacePattern = /^https:\/\/eac\.brightspace\.com\/d2l\/le\/calendar\/feed\/user\/feed\.ics(\?.+)?$/;

            this.inlineError = '';

            if (!url) {
                this.inlineError = strings.pleaseEnterBrightspaceUrl ?? '';
                this.$refs.urlInput && this.$refs.urlInput.focus();

                return;
            }

            if (!brightspacePattern.test(url)) {
                this.inlineError = strings.useBrightspaceSubscribeUrl ?? '';
                this.$refs.urlInput && this.$refs.urlInput.focus();

                return;
            }

            this.connecting = true;

            this.$wire.$dispatch('toast', {
                type: 'info',
                message: strings.connectingCalendar ?? '',
                skipDedupe: true,
            });

            try {
                await this.$wire.$call('connectCalendarFeed', {
                    feedUrl: url,
                    name: name || null,
                });

                this.feedUrl = '';
                this.feedName = '';
                this.inlineError = '';

                await this.ensureFeedHealthLoaded(true);
                this.$dispatch('calendar-feed-updated');
            } catch (error) {
                this.inlineError = error?.message || strings.couldNotConnectFeed || '';
            } finally {
                this.connecting = false;
            }
        },

        async syncFeed(id) {
            if (!id) {
                return;
            }
            this.syncingIds = this.syncingIds || new Set();
            if (this.syncingIds.has(id)) {
                return;
            }

            this.syncingIds.add(id);
            const previousFeedHealth = Array.isArray(this.feedHealth)
                ? this.feedHealth.map((feed) => ({ ...feed }))
                : [];

            try {
                await this.$wire.$call('syncCalendarFeed', Number(id));
                await this.ensureFeedHealthLoaded(true);
                this.$dispatch('calendar-feed-updated');
            } catch {
                this.feedHealth = previousFeedHealth;
            } finally {
                this.syncingIds.delete(id);
            }
        },

        async disconnectFeed(id) {
            if (!id) {
                return;
            }
            this.disconnectingIds = this.disconnectingIds || new Set();
            if (this.disconnectingIds.has(id)) {
                return;
            }

            this.disconnectingIds.add(id);
            const previousFeedHealth = Array.isArray(this.feedHealth)
                ? this.feedHealth.map((feed) => ({ ...feed }))
                : [];

            this.feedHealth = (this.feedHealth || []).filter((feed) => Number(feed.id) !== Number(id));

            try {
                await this.$wire.$call('disconnectCalendarFeed', Number(id));
                await this.ensureFeedHealthLoaded(true);
                this.$dispatch('calendar-feed-updated');
            } catch {
                this.feedHealth = previousFeedHealth;
            } finally {
                this.disconnectingIds.delete(id);
            }
        },

        startEditingFeedName(feed) {
            if (!feed || this.savingFeedName) {
                return;
            }

            this.editingFeedId = Number(feed.id);
            this.editingFeedNameSnapshot = String(feed.name || '');
            this.editingFeedName = this.editingFeedNameSnapshot;
            this.savedFeedNameViaEnter = false;
            this.justCanceledFeedName = false;

            this.$nextTick(() => {
                const input = this.$refs?.feedNameInput;
                if (input && input.focus) {
                    input.focus();
                    const len = input.value?.length ?? 0;
                    if (input.setSelectionRange) {
                        input.setSelectionRange(len, len);
                    }
                }
            });
        },

        cancelEditingFeedName() {
            this.justCanceledFeedName = true;
            this.savedFeedNameViaEnter = false;
            this.editingFeedName = this.editingFeedNameSnapshot;
            this.editingFeedId = null;

            setTimeout(() => {
                this.justCanceledFeedName = false;
            }, 100);
        },

        async saveEditingFeedName(feed) {
            if (!feed || this.savingFeedName || this.justCanceledFeedName) {
                return;
            }

            const trimmedName = String(this.editingFeedName || '').trim();
            const previousName = String(this.editingFeedNameSnapshot || '').trim();

            if (trimmedName === '') {
                this.cancelEditingFeedName();

                return;
            }

            if (trimmedName === previousName) {
                this.editingFeedId = null;

                return;
            }

            const feedId = Number(feed.id);
            const previousFeedHealth = Array.isArray(this.feedHealth)
                ? this.feedHealth.map((row) => ({ ...row }))
                : [];

            this.savingFeedName = true;
            try {
                this.feedHealth = (this.feedHealth || []).map((row) => (
                    Number(row.id) === feedId ? { ...row, name: trimmedName } : row
                ));

                const ok = await this.$wire.$call('updateCalendarFeedName', feedId, trimmedName);
                if (!ok) {
                    this.feedHealth = previousFeedHealth;
                } else {
                    this.editingFeedId = null;
                    this.$dispatch('calendar-feed-updated');
                }
            } catch {
                this.feedHealth = previousFeedHealth;
            } finally {
                this.savingFeedName = false;
                if (this.savedFeedNameViaEnter) {
                    setTimeout(() => {
                        this.savedFeedNameViaEnter = false;
                    }, 100);
                }
            }
        },

        handleFeedNameEnter(feed) {
            this.savedFeedNameViaEnter = true;
            this.saveEditingFeedName(feed);
        },

        handleFeedNameBlur(feed) {
            if (!this.savedFeedNameViaEnter && !this.justCanceledFeedName) {
                this.saveEditingFeedName(feed);
            }
        },

        importPastChoiceAllowed(target) {
            const t = Number(target);
            const list = Array.isArray(this.importPastChoices) ? this.importPastChoices : [1, 3, 6];

            return Number.isFinite(t) && list.includes(t);
        },

        async pickImportPastMonths(value) {
            const next = Number(value);
            if (!this.importPastChoiceAllowed(next)) {
                return;
            }
            this.importPastMonths = next;
            await this.saveImportPastMonths();
        },

        async saveImportPastMonths() {
            const target = Number(this.importPastMonths);
            if (!this.importPastChoiceAllowed(target)) {
                this.importPastMonths = this.importPastMonthsSaved;

                return;
            }
            if (target === this.importPastMonthsSaved) {
                return;
            }
            if (this.importPastMonthsSaving) {
                return;
            }

            this.importPastMonthsSaving = true;
            try {
                const ok = await this.$wire.$call('updateCalendarImportPastMonths', target);
                if (ok) {
                    this.importPastMonthsSaved = target;
                } else {
                    this.importPastMonths = this.importPastMonthsSaved;
                }
            } catch {
                this.importPastMonths = this.importPastMonthsSaved;
            } finally {
                this.importPastMonthsSaving = false;
            }
        },
    };
}

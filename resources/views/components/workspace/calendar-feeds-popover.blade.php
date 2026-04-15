<div
    wire:ignore
    x-data="{
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

        init() {
            this.ensureFeedHealthLoaded();
        },

        statusClass(status) {
            if (status === 'fresh') return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200';
            if (status === 'stale') return 'bg-amber-100 text-amber-900 dark:bg-amber-950/50 dark:text-amber-200';
            if (status === 'critical') return 'bg-red-100 text-red-800 dark:bg-red-950/50 dark:text-red-200';
            if (status === 'sync_off') return 'bg-zinc-200 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200';
            return 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200';
        },

        async ensureFeedHealthLoaded(force = false) {
            const nowMs = Date.now();
            const isFresh = this.lastFeedHealthLoadedAt > 0
                && (nowMs - this.lastFeedHealthLoadedAt) < this.feedHealthRefreshWindowMs;

            if (!force && isFresh) {
                return;
            }
            if (!force && this.feedHealth && this.feedHealth.length > 0) {
                return;
            }
            if (this.loadingFeedHealth) {
                return;
            }

            this.loadingFeedHealth = true;
            try {
                const result = await $wire.$call('loadCalendarFeedHealth');
                this.feedHealth = Array.isArray(result) ? result : [];
                this.lastFeedHealthLoadedAt = Date.now();
            } catch (error) {
                this.feedHealth = [];
            } finally {
                this.loadingFeedHealth = false;
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
            this.$nextTick(() => this.$refs.urlInput && this.$refs.urlInput.focus());
        },

        close(focusAfter) {
            if (!this.open) return;

            this.open = false;
            setTimeout(() => this.$dispatch('dropdown-closed'), 50);
            focusAfter && focusAfter.focus && focusAfter.focus();
        },

        async connectFeed() {
            if (this.connecting) return;

            const url = (this.feedUrl || '').trim();
            const name = (this.feedName || '').trim();
            const brightspacePattern = /^https:\/\/eac\.brightspace\.com\/d2l\/le\/calendar\/feed\/user\/feed\.ics(\?.+)?$/;

            this.inlineError = '';

            if (!url) {
                this.inlineError = @js(__('Please enter your Brightspace calendar URL.'));
                this.$refs.urlInput && this.$refs.urlInput.focus();
                return;
            }

            if (!brightspacePattern.test(url)) {
                this.inlineError = @js(__('Please use a Brightspace calendar link that starts with https://eac.brightspace.com/d2l/le/calendar/feed/user/feed.ics'));
                this.$refs.urlInput && this.$refs.urlInput.focus();
                return;
            }

            this.connecting = true;

            $wire.$dispatch('toast', {
                type: 'info',
                message: @js(__('Connecting your calendar…')),
                skipDedupe: true,
            });

            try {
                await $wire.$call('connectCalendarFeed', {
                    feedUrl: url,
                    name: name || null,
                });

                this.feedUrl = '';
                this.feedName = '';
                this.inlineError = '';

                await this.ensureFeedHealthLoaded(true);
                this.$dispatch('calendar-feed-updated');
            } catch (error) {
                this.inlineError = error?.message || @js(__('Couldn’t connect the calendar feed. Try again.'));
            } finally {
                this.connecting = false;
            }
        },

        async syncFeed(id) {
            if (!id) return;
            this.syncingIds = this.syncingIds || new Set();
            if (this.syncingIds.has(id)) return;

            this.syncingIds.add(id);
            const previousFeedHealth = Array.isArray(this.feedHealth)
                ? this.feedHealth.map((feed) => ({ ...feed }))
                : [];

            $wire.$dispatch('toast', {
                type: 'info',
                message: @js(__('Syncing calendar…')),
                skipDedupe: true,
            });

            try {
                await $wire.$call('syncCalendarFeed', Number(id));
                await this.ensureFeedHealthLoaded(true);
                this.$dispatch('calendar-feed-updated');
            } catch (error) {
                this.feedHealth = previousFeedHealth;
            } finally {
                this.syncingIds.delete(id);
            }
        },

        async disconnectFeed(id) {
            if (!id) return;
            this.disconnectingIds = this.disconnectingIds || new Set();
            if (this.disconnectingIds.has(id)) return;

            this.disconnectingIds.add(id);
            const previousFeedHealth = Array.isArray(this.feedHealth)
                ? this.feedHealth.map((feed) => ({ ...feed }))
                : [];

            // Optimistic remove for instant UI feedback.
            this.feedHealth = (this.feedHealth || []).filter((feed) => Number(feed.id) !== Number(id));

            try {
                await $wire.$call('disconnectCalendarFeed', Number(id));
                await this.ensureFeedHealthLoaded(true);
                this.$dispatch('calendar-feed-updated');
            } catch (error) {
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
                // Optimistic update for immediate visual feedback.
                this.feedHealth = (this.feedHealth || []).map((row) => (
                    Number(row.id) === feedId ? { ...row, name: trimmedName } : row
                ));

                const ok = await $wire.$call('updateCalendarFeedName', feedId, trimmedName);
                if (!ok) {
                    this.feedHealth = previousFeedHealth;
                } else {
                    this.editingFeedId = null;
                    this.$dispatch('calendar-feed-updated');
                }
            } catch (error) {
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
    }"
    @keydown.escape.prevent.stop="close($refs.button)"
    @focusin.window="($refs.panel && !$refs.panel.contains($event.target)) && close($refs.button)"
    @calendar-feed-connected.window="ensureFeedHealthLoaded(true)"
    @calendar-feed-updated.window="ensureFeedHealthLoaded(true)"
    class="relative w-full"
    :class="open ? 'z-9999' : 'z-10'"
>
    <div class="overflow-visible rounded-xl border border-brand-blue/30 bg-brand-light-lavender/90 shadow-lg backdrop-blur-xs dark:border-brand-blue/25 dark:bg-brand-light-lavender/10">
        <div class="border-b border-brand-blue/20 px-4 py-4 dark:border-brand-blue/20">
            <div class="flex items-center gap-3">
                <img
                    src="{{ asset('images/brightspace-icon.png') }}"
                    alt=""
                    width="44"
                    height="44"
                    decoding="async"
                    class="size-11 shrink-0 rounded-xl bg-white/90 object-contain p-1 shadow-sm ring-1 ring-black/5 dark:bg-white/10 dark:ring-white/10"
                />
                <div class="min-w-0 flex-1">
                    <span class="block text-xs font-semibold uppercase leading-none tracking-wide text-muted-foreground">
                        {{ __('BRIGHTSPACE CALENDAR FEED') }}
                    </span>
                </div>
            </div>
        </div>

        <template x-if="loadingFeedHealth">
            <div class="px-4 py-4 text-xs text-muted-foreground">{{ __('Loading feed health…') }}</div>
        </template>

        <template x-if="!loadingFeedHealth && (!feedHealth || feedHealth.length === 0)">
            <div class="px-4 py-4">
                <p class="text-sm leading-relaxed text-muted-foreground">{{ __('No calendar feeds connected yet.') }}</p>
            </div>
        </template>

        <template x-if="!loadingFeedHealth && feedHealth && feedHealth.length > 0">
            <ul class="max-h-80 divide-y divide-border/40 overflow-y-auto dark:divide-white/10">
                <template x-for="feed in feedHealth" :key="feed.id">
                    <li class="space-y-2 px-4 py-3.5" data-testid="dashboard-row-calendar-feed-health">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <template x-if="editingFeedId !== Number(feed.id)">
                                    <div class="flex items-center gap-1.5">
                                        <p class="truncate text-sm font-semibold leading-snug text-foreground" x-text="feed.name"></p>
                                        <button
                                            type="button"
                                            class="inline-flex h-5 w-5 items-center justify-center rounded text-muted-foreground transition hover:bg-muted/60 hover:text-foreground"
                                            @click="startEditingFeedName(feed)"
                                            :disabled="savingFeedName || syncingIds?.has(feed.id) || disconnectingIds?.has(feed.id)"
                                            aria-label="{{ __('Edit feed name') }}"
                                        >
                                            <flux:icon name="pencil-square" class="size-3.5" />
                                        </button>
                                    </div>
                                </template>
                                <template x-if="editingFeedId === Number(feed.id)">
                                    <div class="flex items-center gap-1.5">
                                        <input
                                            x-ref="feedNameInput"
                                            type="text"
                                            x-model="editingFeedName"
                                            class="w-full rounded-md border border-border/70 bg-background px-2 py-1 text-xs text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/40"
                                            @keydown.enter.prevent="handleFeedNameEnter(feed)"
                                            @keydown.escape.prevent="cancelEditingFeedName()"
                                            @blur="handleFeedNameBlur(feed)"
                                        />
                                        <button
                                            type="button"
                                            class="inline-flex h-5 w-5 items-center justify-center rounded text-emerald-600 transition hover:bg-emerald-500/10"
                                            :disabled="savingFeedName"
                                            @click="saveEditingFeedName(feed)"
                                            aria-label="{{ __('Save feed name') }}"
                                        >
                                            <flux:icon name="check" class="size-3.5" />
                                        </button>
                                        <button
                                            type="button"
                                            class="inline-flex h-5 w-5 items-center justify-center rounded text-muted-foreground transition hover:bg-muted/60 hover:text-foreground"
                                            :disabled="savingFeedName"
                                            @click="cancelEditingFeedName()"
                                            aria-label="{{ __('Cancel feed name editing') }}"
                                        >
                                            <flux:icon name="x-mark" class="size-3.5" />
                                        </button>
                                    </div>
                                </template>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <span
                                    class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold leading-none"
                                    :class="statusClass(feed.status)"
                                    role="status"
                                >
                                    <span x-text="syncingIds?.has(feed.id) ? '{{ __('Syncing…') }}' : feed.status_label"></span>
                                </span>
                                <div class="flex items-center gap-1.5">
                                    <flux:tooltip content="{{ __('Sync again') }}">
                                        <flux:button
                                            type="button"
                                            size="xs"
                                            variant="outline"
                                            icon="arrow-path"
                                            x-bind:disabled="syncingIds?.has(feed.id)"
                                            @click="syncFeed(feed.id)"
                                        />
                                    </flux:tooltip>
                                    <flux:tooltip content="{{ __('Disconnect this calendar') }}">
                                        <flux:button
                                            type="button"
                                            size="xs"
                                            variant="ghost"
                                            icon="link-slash"
                                            class="text-red-600 hover:bg-red-500/10 hover:text-red-600 dark:text-red-400"
                                            x-bind:disabled="disconnectingIds?.has(feed.id)"
                                            @click="disconnectFeed(feed.id)"
                                        />
                                    </flux:tooltip>
                                </div>
                            </div>
                        </div>
                        <div class="space-y-1 text-[11px] leading-relaxed text-muted-foreground">
                            <p>
                                <span>{{ __('Last sync: ') }}</span><span x-text="feed.last_synced_human"></span>
                            </p>
                            <p>
                                <span>{{ __('Updated 24h: ') }}</span><span x-text="feed.updated_last_24h"></span>
                                <span> · </span>
                                <span>{{ __('Total imported: ') }}</span><span x-text="feed.total_imported"></span>
                            </p>
                            <template x-if="feed.latest_import_activity_human">
                                <p :title="feed.latest_import_activity_title">
                                    <span>{{ __('Latest import activity: ') }}</span><span x-text="feed.latest_import_activity_human"></span>
                                </p>
                            </template>
                        </div>
                    </li>
                </template>
            </ul>
        </template>

        <div class="border-t border-brand-blue/20 px-4 py-3 dark:border-brand-blue/20">
            <div class="relative z-120 inline-block">
                <button
                    x-ref="button"
                    type="button"
                    :disabled="open"
                    @click="toggle()"
                    aria-haspopup="true"
                    :aria-expanded="open"
                    class="inline-flex items-center justify-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold text-brand-blue transition hover:bg-brand-blue/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/50 focus-visible:ring-offset-1 disabled:pointer-events-none disabled:opacity-70"
                >
                    <span>Sync Brightspace Calendar</span>
                    <flux:icon name="link" class="size-3.5" />
                </button>

                <div
                    x-ref="panel"
                    x-show="open"
                    x-transition:enter="transition ease-out duration-100"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="transition ease-in duration-75"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    x-cloak
                    @click.outside="close($refs.button)"
                    @click.stop
                    :class="panelPlacementClassesValue"
                    class="z-9999 flex w-fit min-w-[260px] max-w-[min(360px,calc(100vw-2rem))] flex-col rounded-xl border border-brand-blue/25 bg-brand-light-lavender/95 text-foreground shadow-lg shadow-brand-navy-blue/10 backdrop-blur-xs dark:border-brand-blue/20 dark:bg-brand-light-lavender/10 dark:shadow-black/35"
                >
                    <div class="flex items-center justify-between gap-2 border-b border-brand-blue/20 px-3 py-2.5 dark:border-brand-blue/20">
                        <div class="flex min-w-0 items-center gap-2">
                            <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                <flux:icon name="calendar-days" class="size-3" />
                            </div>
                            <div class="flex min-w-0 items-center gap-1">
                                <p class="truncate text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                    {{ __('Connect Brightspace calendar') }}
                                </p>
                                <flux:tooltip toggleable position="top">
                                    <flux:button
                                        type="button"
                                        size="xs"
                                        variant="ghost"
                                        icon="information-circle"
                                        class="h-4 w-4 p-0 text-muted-foreground/80 hover:text-foreground"
                                        aria-label="{{ __('How to get Brightspace calendar link') }}"
                                    />
                                    <flux:tooltip.content class="max-w-[18rem] space-y-2">
                                        <p class="text-xs font-semibold leading-snug">{{ __('How to Get Brightspace Calendar Link') }}</p>
                                        <ol class="list-decimal space-y-1 pl-4 text-xs leading-snug text-muted-foreground">
                                            <li>{{ __('Go to Brightspace and log in') }}</li>
                                            <li>{{ __('Open Calendar from navbar') }}</li>
                                            <li>{{ __('Click Settings or Calendar Feeds') }}</li>
                                            <li>{{ __('Turn ON Enable Calendar Feeds') }}</li>
                                            <li>{{ __('Click Subscribe and copy the ICS subscription URL') }}</li>
                                        </ol>
                                    </flux:tooltip.content>
                                </flux:tooltip>
                            </div>
                        </div>

                        <button
                            type="button"
                            class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-muted-foreground hover:bg-muted/60 hover:text-foreground"
                            aria-label="{{ __('Close Brightspace calendar popover') }}"
                            @click="close($refs.button)"
                        >
                            <flux:icon name="x-mark" class="size-3" />
                        </button>
                    </div>

                    <div class="flex flex-col gap-3 px-3 py-3 text-[11px]">
                        <div class="space-y-2">
                            <div class="space-y-1">
                                <label class="block text-[11px] font-medium text-muted-foreground">{{ __('Feed URL') }}</label>
                                <div class="grid grid-cols-[minmax(0,4fr)_auto] items-center gap-2">
                                    <flux:input
                                        x-ref="urlInput"
                                        x-model="feedUrl"
                                        type="url"
                                        name="brightspace_feed_url"
                                        autocomplete="off"
                                        placeholder="https://eac.brightspace.com/d2l/le/calendar/feed/user/feed.ics?token=…"
                                        class="w-full"
                                        @keydown.enter.prevent="connectFeed()"
                                    />
                                    <flux:tooltip content="{{ __('Connect or sync') }}">
                                        <flux:button
                                            type="button"
                                            size="xs"
                                            variant="primary"
                                            icon="plus"
                                            class="shrink-0"
                                            aria-label="{{ __('Connect or sync') }}"
                                            x-bind:disabled="connecting || !feedUrl"
                                            @click="connectFeed()"
                                        />
                                    </flux:tooltip>
                                </div>
                                <p class="text-[10px] text-muted-foreground/80">
                                    {{ __('Use the Brightspace “Subscribe” URL for your All Courses calendar.') }}
                                </p>
                            </div>

                            <template x-if="inlineError">
                                <div class="flex items-center gap-1.5 rounded-md bg-red-500/5 px-2 py-1 text-[10px] text-red-600 dark:text-red-400">
                                    <flux:icon name="exclamation-triangle" class="size-3" />
                                    <p x-text="inlineError"></p>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

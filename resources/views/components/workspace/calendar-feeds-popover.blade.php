@props([
    'feeds' => [],
])

@php
    $feeds = $feeds instanceof \Illuminate\Support\Collection ? $feeds->all() : (is_array($feeds) ? $feeds : []);
@endphp

<div
    wire:ignore
    x-data="{
        open: false,
        placementVertical: 'bottom',
        placementHorizontal: 'end',
        panelHeightEst: 320,
        panelWidthEst: 320,
        panelPlacementClassesValue: 'absolute top-full right-0 mt-1',

        // Form state
        feedUrl: '',
        feedName: '',
        connecting: false,
        inlineError: '',

        // Feeds state
        feeds: @js($feeds),
        loadingFeeds: false,
        syncingIds: new Set(),
        disconnectingIds: new Set(),

        toggle() {
            if (this.open) {
                return this.close(this.$refs.button);
            }

            const vh = window.innerHeight;
            const vw = window.innerWidth;

            // Mobile: bottom sheet
            if (vw <= 480) {
                this.placementVertical = 'bottom';
                this.placementHorizontal = 'center';
                this.panelPlacementClassesValue = 'fixed inset-x-3 bottom-4 max-h-[min(70vh,22rem)]';
                this.open = true;
                this.$dispatch('dropdown-opened');
                this.ensureFeedsLoaded();
                this.$nextTick(() => this.$refs.urlInput && this.$refs.urlInput.focus());

                return;
            }

            const rect = this.$refs.button.getBoundingClientRect();
            const contentLeft = vw < 768 ? 16 : 320;
            const effectivePanelWidth = Math.min(this.panelWidthEst, vw - 32);

            const spaceBelow = vh - rect.bottom;
            const spaceAbove = rect.top;

            if (spaceBelow >= this.panelHeightEst || spaceBelow >= spaceAbove) {
                this.placementVertical = 'bottom';
            } else {
                this.placementVertical = 'top';
            }

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
            this.ensureFeedsLoaded();
            this.$nextTick(() => this.$refs.urlInput && this.$refs.urlInput.focus());
        },

        close(focusAfter) {
            if (!this.open) return;

            this.open = false;
            setTimeout(() => this.$dispatch('dropdown-closed'), 50);
            focusAfter && focusAfter.focus && focusAfter.focus();
        },

        async ensureFeedsLoaded(force = false) {
            if (!force && this.feeds && this.feeds.length > 0) {
                return;
            }

            if (this.loadingFeeds) {
                return;
            }

            this.loadingFeeds = true;

            try {
                const result = await $wire.$call('loadCalendarFeeds');
                this.feeds = Array.isArray(result) ? result : [];
            } catch (error) {
                this.feeds = [];
            } finally {
                this.loadingFeeds = false;
            }
        },

        async connectFeed() {
            if (this.connecting) return;

            const url = (this.feedUrl || '').trim();
            const name = (this.feedName || '').trim();

            this.inlineError = '';

            if (!url) {
                this.inlineError = @js(__('Please enter your Brightspace calendar URL.'));
                this.$refs.urlInput && this.$refs.urlInput.focus();

                return;
            }

            this.connecting = true;

            try {
                await $wire.$call('connectCalendarFeed', {
                    feedUrl: url,
                    name: name || null,
                });

                this.feedUrl = '';
                this.feedName = '';
                this.inlineError = '';

                await this.ensureFeedsLoaded(true);
            } catch (error) {
                // Trait already dispatches a toast on failure; keep inline error generic.
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

            try {
                await $wire.$call('syncCalendarFeed', Number(id));
                await this.ensureFeedsLoaded(true);
            } catch (error) {
                // Errors are surfaced via toasts in the Livewire trait.
            } finally {
                this.syncingIds.delete(id);
            }
        },

        async disconnectFeed(id) {
            if (!id) return;
            this.disconnectingIds = this.disconnectingIds || new Set();
            if (this.disconnectingIds.has(id)) return;

            this.disconnectingIds.add(id);

            try {
                await $wire.$call('disconnectCalendarFeed', Number(id));
                this.feeds = (this.feeds || []).filter((feed) => Number(feed.id) !== Number(id));
            } catch (error) {
                // Errors are surfaced via toasts in the Livewire trait.
            } finally {
                this.disconnectingIds.delete(id);
            }
        },
    }"
    @keydown.escape.prevent.stop="close($refs.button)"
    @focusin.window="($refs.panel && !$refs.panel.contains($event.target)) && close($refs.button)"
    @calendar-feed-connected.window="ensureFeedsLoaded(true)"
    class="relative inline-block"
    {{ $attributes }}
>
    <flux:tooltip content="{{ __('Sync with Brightspace calendar') }}">
        <button
            x-ref="button"
            type="button"
            @click="toggle()"
            aria-haspopup="true"
            :aria-expanded="open"
            class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 text-[11px] font-medium text-muted-foreground transition-[box-shadow,transform] duration-150 ease-out hover:bg-muted/70 hover:text-foreground"
        >
            <flux:icon name="arrow-path" class="size-3.5" />
            <span>{{ __('Sync with Brightspace calendar') }}</span>
        </button>
    </flux:tooltip>

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
        class="z-50 w-fit min-w-[260px] max-w-[min(360px,calc(100vw-2rem))] flex flex-col rounded-lg border border-border bg-white text-foreground shadow-lg dark:bg-zinc-900"
    >
        <div class="flex items-center justify-between gap-2 border-b border-border/60 px-3 py-2.5">
            <div class="flex items-center gap-2 min-w-0">
                <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                    <flux:icon name="calendar-days" class="size-3" />
                </div>
                <div class="min-w-0">
                    <p class="truncate text-xs font-semibold tracking-wide text-muted-foreground">
                        {{ __('Connect Brightspace calendar') }}
                    </p>
                    <p class="text-[11px] text-muted-foreground/80">
                        {{ __('Paste your Brightspace calendar subscribe URL to sync assignments and exams as tasks.') }}
                    </p>
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
                    <label class="block text-[11px] font-medium text-muted-foreground">
                        {{ __('Feed URL') }}
                    </label>
                    <flux:input
                        x-ref="urlInput"
                        x-model="feedUrl"
                        type="url"
                        name="brightspace_feed_url"
                        autocomplete="off"
                        placeholder="https://eac.brightspace.com/d2l/le/calendar/feed/user/feed.ics?token=…"
                        class="w-full"
                    />
                    <p class="text-[10px] text-muted-foreground/80">
                        {{ __('Use the Brightspace “Subscribe” URL for your All Courses calendar.') }}
                    </p>
                </div>

                <div class="space-y-1">
                    <label class="block text-[11px] font-medium text-muted-foreground">
                        {{ __('Name (optional)') }}
                    </label>
                    <flux:input
                        x-model="feedName"
                        type="text"
                        name="brightspace_feed_name"
                        autocomplete="off"
                        placeholder="{{ __('e.g. Brightspace – All Courses') }}"
                        class="w-full"
                    />
                </div>

                <template x-if="inlineError">
                    <div class="flex items-center gap-1.5 rounded-md bg-red-500/5 px-2 py-1 text-[10px] text-red-600 dark:text-red-400">
                        <flux:icon name="exclamation-triangle" class="size-3" />
                        <p x-text="inlineError"></p>
                    </div>
                </template>

                <div class="flex flex-wrap items-center gap-2 pt-1">
                    <flux:button
                        type="button"
                        size="xs"
                        variant="primary"
                        icon="plus"
                        class="shrink-0"
                        x-bind:disabled="connecting || !feedUrl"
                        @click="connectFeed()"
                    >
                        <span x-text="connecting ? '{{ __('Connecting…') }}' : '{{ __('Connect') }}'"></span>
                    </flux:button>

                    <button
                        type="button"
                        class="text-[11px] font-medium text-muted-foreground hover:text-foreground underline-offset-2 hover:underline"
                    >
                        {{ __('How do I get this link?') }}
                    </button>
                </div>
            </div>

            <div class="h-px w-full bg-border/70"></div>

            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                        {{ __('Connected calendars') }}
                    </p>
                </div>

                <template x-if="loadingFeeds">
                    <div class="flex flex-col items-center justify-center gap-2 rounded-md border border-dashed border-border/60 bg-muted/30 px-3 py-4 text-center">
                        <flux:icon name="arrow-path" class="size-4 animate-spin text-muted-foreground" />
                        <p class="text-[11px] text-muted-foreground">
                            {{ __('Loading calendars…') }}
                        </p>
                    </div>
                </template>

                <template x-if="!loadingFeeds && (!feeds || feeds.length === 0)">
                    <div class="rounded-md border border-dashed border-border/60 bg-muted/30 px-3 py-3 text-center">
                        <p class="text-[11px] text-muted-foreground">
                            {{ __('No Brightspace calendars connected yet.') }}
                        </p>
                        <p class="mt-1 text-[10px] text-muted-foreground/80">
                            {{ __('Paste a feed URL above to start syncing tasks from Brightspace.') }}
                        </p>
                    </div>
                </template>

                <template x-if="!loadingFeeds && feeds && feeds.length > 0">
                    <ul class="max-h-64 space-y-2 overflow-y-auto">
                        <template x-for="feed in feeds" :key="feed.id">
                            <li class="flex items-start justify-between gap-2 rounded-md border border-border/60 bg-muted/40 px-2.5 py-2">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-[11px] font-medium text-foreground" x-text="feed.name || '{{ __('Brightspace calendar') }}'"></p>
                                    <p class="mt-0.5 text-[10px] text-muted-foreground/80">
                                        <span>{{ __('Last synced') }} </span>
                                        <span x-text="feed.last_synced_at || '{{ __('Never synced') }}'"></span>
                                    </p>
                                </div>
                                <div class="flex shrink-0 flex-col items-end gap-1">
                                    <div class="flex items-center gap-1.5">
                                        <flux:button
                                            type="button"
                                            size="xs"
                                            variant="outline"
                                            icon="arrow-path"
                                            x-bind:disabled="syncingIds?.has(feed.id)"
                                            @click="syncFeed(feed.id)"
                                        >
                                            <span x-text="syncingIds?.has(feed.id) ? '{{ __('Syncing…') }}' : '{{ __('Sync now') }}'"></span>
                                        </flux:button>
                                        <flux:button
                                            type="button"
                                            size="xs"
                                            variant="ghost"
                                            icon="trash"
                                            class="text-red-600 hover:text-red-600 hover:bg-red-500/10 dark:text-red-400"
                                            x-bind:disabled="disconnectingIds?.has(feed.id)"
                                            @click="disconnectFeed(feed.id)"
                                        >
                                            <span x-text="disconnectingIds?.has(feed.id) ? '{{ __('Removing…') }}' : '{{ __('Disconnect') }}'"></span>
                                        </flux:button>
                                    </div>
                                </div>
                            </li>
                        </template>
                    </ul>
                </template>
            </div>
        </div>
    </div>

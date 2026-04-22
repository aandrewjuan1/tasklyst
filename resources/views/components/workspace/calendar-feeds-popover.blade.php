@php
    if (! isset($importPastChoices)) {
        /** @var list<int|string> $rawChoices */
        $rawChoices = config('calendar_feeds.allowed_import_past_months', [1, 3, 6]);
        $importPastChoices = array_values(array_map(static fn (mixed $v): int => (int) $v, $rawChoices));
    }
    if (! isset($importPastMonths)) {
        $user = auth()->user();
        $importPastMonths = $user instanceof \App\Models\User
            ? $user->resolvedCalendarImportPastMonths()
            : (int) config('calendar_feeds.default_import_past_months');
    }
    if (! isset($importPastMonthLabels)) {
        $importPastMonthLabels = [];
        foreach ($importPastChoices as $m) {
            $importPastMonthLabels[(string) $m] = $m === 1
                ? __('1 month')
                : __(':count months', ['count' => $m]);
        }
    }
    if (! isset($alpineBootstrap)) {
        $alpineBootstrap = [
            'importPastMonths' => (int) $importPastMonths,
            'importPastMonthsSaved' => (int) $importPastMonths,
            'importPastChoices' => $importPastChoices,
            'importPastMonthLabels' => $importPastMonthLabels,
            'strings' => [
                'pleaseEnterBrightspaceUrl' => __('Please enter your Brightspace calendar URL.'),
                'useBrightspaceSubscribeUrl' => __('Please use a Brightspace calendar link that starts with https://eac.brightspace.com/d2l/le/calendar/feed/user/feed.ics'),
                'connectingCalendar' => __('Connecting your calendar…'),
                'couldNotConnectFeed' => __('Couldn’t connect the calendar feed. Try again.'),
            ],
        ];
    }
@endphp
<div
    wire:ignore
    x-data="calendarFeedsPopover(@js($alpineBootstrap))"
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
                                <span>{{ __('Import lookback: ') }}</span><span x-text="importPastSavedLabel()"></span>
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
                        <div class="space-y-2 border-b border-brand-blue/15 pb-3 dark:border-brand-blue/15">
                            <span class="block text-[11px] font-medium text-muted-foreground" id="calendar-import-past-months-label">
                                {{ __('How far back to import') }}
                            </span>
                            <div
                                class="flex gap-1 rounded-md border border-border/60 bg-muted/25 p-0.5 dark:border-white/10 dark:bg-white/5"
                                role="group"
                                aria-labelledby="calendar-import-past-months-label"
                            >
                                @foreach ($importPastChoices as $m)
                                    <button
                                        type="button"
                                        class="min-w-0 flex-1 rounded px-1.5 py-1 text-[10px] font-semibold leading-tight transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/45 disabled:opacity-50"
                                        :class="Number(importPastMonths) === {{ (int) $m }}
                                            ? 'bg-brand-blue text-white shadow-sm dark:bg-brand-blue'
                                            : 'text-muted-foreground hover:bg-background/90 hover:text-foreground'"
                                        :disabled="connecting"
                                        @click="pickImportPastMonths({{ (int) $m }})"
                                    >
                                        @if ($m === 1)
                                            {{ __('1 mo') }}
                                        @elseif ($m === 3)
                                            {{ __('3 mo') }}
                                        @elseif ($m === 6)
                                            {{ __('6 mo') }}
                                        @else
                                            {{ trans_choice(':count mo|:count mos', $m, ['count' => $m]) }}
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                            <p class="text-[10px] leading-relaxed text-muted-foreground/80">
                                {{ __('Events that ended before this window are not imported.') }}
                            </p>
                        </div>
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

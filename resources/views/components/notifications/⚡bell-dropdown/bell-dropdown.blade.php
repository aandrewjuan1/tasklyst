@php
    $initialUnreadLabel = $unreadCount > 0
        ? trans_choice(':count unread', $unreadCount, ['count' => $unreadCount])
        : '';
    $unreadWordForAlpine = preg_replace('/^\d+\s+/u', '', trans_choice(':count unread', 3, ['count' => 3])) ?: 'unread';
@endphp

{{-- Outer root: Livewire wire:id. Inner wire:ignore + x-data. Use livewire() not bare $wire: $wire is not in JS scope inside x-data object methods (collaborators use $wire inside a *parent* Livewire page where Alpine injects it). --}}
<div>
    <div
        wire:ignore
        class="relative inline-flex"
        x-data="{
            notifications: @js($notifications),
            unreadCount: @js($unreadCount),
            unreadWord: @js($unreadWordForAlpine),
            initialUnreadLabel: @js($initialUnreadLabel),
            open: false,
            unreadLabel: '',
            processingById: {},

            livewire() {
                const el = this.$el;
                const fromClosest = el?.closest('[wire\\:id]')?.$wire ?? null;
                if (fromClosest) {
                    return fromClosest;
                }

                const parent = el?.parentElement;

                return parent?.$wire ?? parent?.closest('[wire\\:id]')?.$wire ?? null;
            },

            init() {
                this.unreadLabel =
                    this.initialUnreadLabel !== ''
                        ? this.initialUnreadLabel
                        : this.buildUnreadLabel(this.unreadCount);
            },

            isUnread(n) {
                if (!n) {
                    return false;
                }

                return n.read_at == null || n.read_at === '';
            },

            buildUnreadLabel(count) {
                if (count <= 0) {
                    return '';
                }

                return `${count} ${this.unreadWord}`;
            },

            cloneState() {
                return {
                    notifications: this.notifications.map((n) => ({ ...n })),
                    unreadCount: this.unreadCount,
                    unreadLabel: this.unreadLabel,
                };
            },

            restoreState(snapshot) {
                this.notifications = snapshot.notifications.map((n) => ({ ...n }));
                this.unreadCount = snapshot.unreadCount;
                this.unreadLabel = snapshot.unreadLabel;
            },

            applyPayload(payload) {
                if (!payload || typeof payload !== 'object') {
                    return;
                }

                if (Array.isArray(payload.notifications)) {
                    this.notifications = payload.notifications.map((n) => ({ ...n }));
                }

                if (typeof payload.unread_count === 'number') {
                    this.unreadCount = payload.unread_count;
                }

                if (typeof payload.unread_label === 'string') {
                    this.unreadLabel = payload.unread_label;
                } else {
                    this.unreadLabel = this.buildUnreadLabel(this.unreadCount);
                }
            },

            async syncFromServer() {
                const lw = this.livewire();
                if (!lw) {
                    return;
                }

                try {
                    const payload = await lw.$call('pullStateForClient');
                    this.applyPayload(payload);
                } catch {
                    // Keep current optimistic state
                }
            },

            toggleOpen() {
                this.open = !this.open;
                if (this.open) {
                    this.syncFromServer();
                }
            },

            setProcessing(id, value) {
                const key = String(id);
                if (value) {
                    this.processingById = { ...this.processingById, [key]: true };
                } else {
                    const next = { ...this.processingById };
                    delete next[key];
                    this.processingById = next;
                }
            },

            optimisticMarkRead(id) {
                const idStr = String(id);
                const target = this.notifications.find((n) => String(n.id) === idStr);
                if (!target || !this.isUnread(target)) {
                    return;
                }

                const readAt = new Date().toISOString();
                this.notifications = this.notifications.map((n) =>
                    String(n.id) === idStr ? { ...n, read_at: readAt } : { ...n },
                );
                this.unreadCount = Math.max(0, this.unreadCount - 1);
                this.unreadLabel = this.buildUnreadLabel(this.unreadCount);
            },

            optimisticMarkUnread(id) {
                const idStr = String(id);
                const target = this.notifications.find((n) => String(n.id) === idStr);
                if (!target || this.isUnread(target)) {
                    return;
                }

                this.notifications = this.notifications.map((n) =>
                    String(n.id) === idStr ? { ...n, read_at: null } : { ...n },
                );
                this.unreadCount = this.unreadCount + 1;
                this.unreadLabel = this.buildUnreadLabel(this.unreadCount);
            },

            async markAsRead(id) {
                const snapshot = this.cloneState();
                this.optimisticMarkRead(id);
                this.setProcessing(id, true);

                const lw = this.livewire();
                if (!lw) {
                    this.restoreState(snapshot);
                    this.setProcessing(id, false);

                    return;
                }

                try {
                    const payload = await lw.$call('markAsRead', String(id));
                    this.applyPayload(payload);
                } catch {
                    this.restoreState(snapshot);
                } finally {
                    this.setProcessing(id, false);
                }
            },

            async markAsUnread(id) {
                const snapshot = this.cloneState();
                this.optimisticMarkUnread(id);
                this.setProcessing(id, true);

                const lw = this.livewire();
                if (!lw) {
                    this.restoreState(snapshot);
                    this.setProcessing(id, false);

                    return;
                }

                try {
                    const payload = await lw.$call('markAsUnread', String(id));
                    this.applyPayload(payload);
                } catch {
                    this.restoreState(snapshot);
                } finally {
                    this.setProcessing(id, false);
                }
            },

            async openNotification(notification) {
                const id = notification.id;
                const snapshot = this.cloneState();

                if (this.isUnread(notification)) {
                    this.optimisticMarkRead(id);
                }

                this.setProcessing(id, true);

                const lw = this.livewire();
                if (!lw) {
                    this.restoreState(snapshot);
                    this.setProcessing(id, false);

                    return;
                }

                try {
                    await lw.$call('openNotification', String(id));
                } catch {
                    this.restoreState(snapshot);
                } finally {
                    this.setProcessing(id, false);
                }
            },
        }"
        @notification-bell-sync.window="syncFromServer()"
        @click.outside="open = false"
        @keydown.escape.window="open = false"
    >
        <button
            type="button"
            class="relative inline-flex size-10 items-center justify-center rounded-lg text-zinc-600 transition hover:bg-zinc-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400/60 dark:text-zinc-300 dark:hover:bg-zinc-700/60"
            data-test="notifications-bell-button"
            x-bind:aria-expanded="open"
            aria-haspopup="true"
            aria-label="{{ __('Notifications') }}"
            @click="toggleOpen()"
        >
            <svg class="size-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
            </svg>
            <span
                x-show="unreadCount > 0"
                x-cloak
                class="absolute -right-0.5 -top-0.5 inline-flex min-h-5 min-w-5 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold text-white"
                data-test="notifications-unread-badge"
                x-text="unreadCount > 99 ? '99+' : String(unreadCount)"
            ></span>
        </button>

        <div
            x-show="open"
            x-cloak
            x-transition:enter="transition ease-out duration-100"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="absolute right-0 top-full z-50 mt-2 w-96 max-w-[calc(100vw-2rem)] origin-top-right rounded-xl border border-zinc-200 bg-white shadow-lg ring-1 ring-black/5 dark:border-zinc-600 dark:bg-zinc-800 dark:ring-white/10"
            role="region"
            aria-label="{{ __('Notifications') }}"
            @click.stop
        >
            <div class="flex items-center justify-between border-b border-zinc-100 px-3 py-2 dark:border-zinc-600/80">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{{ __('Notifications') }}</h2>
                <p
                    x-show="unreadCount > 0"
                    x-cloak
                    class="text-xs text-zinc-500 dark:text-zinc-400"
                    x-text="unreadLabel"
                ></p>
            </div>

            <div class="max-h-[min(24rem,70vh)] overflow-y-auto">
                <template x-for="n in notifications" :key="String(n.id)">
                    <div
                        class="flex items-start gap-2 border-b border-zinc-100 px-3 py-2.5 last:border-b-0 dark:border-zinc-600/60"
                    >
                        <button
                            type="button"
                            class="flex min-w-0 flex-1 flex-col gap-1 rounded-md px-1 py-0.5 text-left text-zinc-900 transition hover:bg-zinc-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400/50 dark:text-zinc-50 dark:hover:bg-zinc-700/40"
                            :disabled="processingById[String(n.id)]"
                            @click="openNotification(n)"
                        >
                            <div class="flex min-w-0 items-center gap-2">
                                <span
                                    x-show="isUnread(n)"
                                    x-cloak
                                    class="inline-block size-2 shrink-0 rounded-full bg-blue-500"
                                    aria-hidden="true"
                                ></span>
                                <span class="min-w-0 truncate text-sm font-semibold" x-text="n.title"></span>
                            </div>
                            <span
                                x-show="n.message && n.message !== ''"
                                x-cloak
                                class="line-clamp-2 text-xs leading-snug text-zinc-600 dark:text-zinc-300"
                                x-text="n.message"
                            ></span>
                            <span class="text-[11px] text-zinc-500 dark:text-zinc-400" x-text="n.created_at_human"></span>
                        </button>

                        <button
                            type="button"
                            x-show="isUnread(n)"
                            x-cloak
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-zinc-200 bg-white px-2.5 py-1.5 text-xs font-medium text-zinc-800 shadow-sm transition hover:bg-zinc-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400/50 disabled:opacity-60 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100 dark:hover:bg-zinc-600"
                            :disabled="processingById[String(n.id)]"
                            @click.stop="markAsRead(n.id)"
                        >
                            <svg class="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                            </svg>
                            <span class="whitespace-nowrap">{{ __('Mark read') }}</span>
                        </button>

                        <button
                            type="button"
                            x-show="!isUnread(n)"
                            x-cloak
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-zinc-200 bg-white px-2.5 py-1.5 text-xs font-medium text-zinc-800 shadow-sm transition hover:bg-zinc-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400/50 disabled:opacity-60 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100 dark:hover:bg-zinc-600"
                            :disabled="processingById[String(n.id)]"
                            @click.stop="markAsUnread(n.id)"
                        >
                            <svg class="size-4 shrink-0 text-sky-600 dark:text-sky-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                            </svg>
                            <span class="whitespace-nowrap">{{ __('Mark unread') }}</span>
                        </button>
                    </div>
                </template>

                <p
                    x-show="notifications.length === 0"
                    x-cloak
                    class="px-4 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400"
                >
                    {{ __('No notifications yet.') }}
                </p>
            </div>
        </div>
    </div>
</div>

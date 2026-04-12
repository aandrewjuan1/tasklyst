<div
    wire:ignore
    x-data="{
        open: false,
        items: [],
        lastDeletedAt: null,
        hasMore: false,
        loading: false,
        loadingMore: false,
        showSpinner: false,
        _loadingSpinnerTimeout: null,
        loadErrorToast: @js(__('Could not load trash. Please try again.')),
        loadMoreErrorToast: @js(__('Could not load more. Please try again.')),
        restoringId: null,
        forceDeletingId: null,
        itemToForceDelete: null,
        deletingAll: false,
        selectionMode: false,
        selectedIds: [],
        restoringSelected: false,
        forceDeletingSelected: false,
        pendingForceDeletePayload: null,
        useViewportSheet: false,
        panelDockStyle: '',
        _dockHandler: null,
        _dockRaf: null,
        _sidebarResizeObserver: null,

        itemKey(item) {
            return item.kind + '-' + item.id;
        },
        addTrashedItem(detail) {
            if (!detail || detail.kind == null || detail.id == null) return;
            const key = detail.kind + '-' + detail.id;
            if (this.items.some((i) => this.itemKey(i) === key)) return;
            this.items = [
                {
                    kind: detail.kind,
                    id: detail.id,
                    title: detail.title ?? '',
                    deleted_at: detail.deleted_at ?? new Date().toISOString(),
                    deleted_at_display: detail.deleted_at_display ?? 'Just now',
                },
                ...this.items,
            ];
        },
        removeTrashedItemRollback(detail) {
            if (!detail || detail.kind == null || detail.id == null) return;
            const key = detail.kind + '-' + detail.id;
            this.items = this.items.filter((i) => this.itemKey(i) !== key);
        },
        isSelected(item) {
            return this.selectedIds.includes(this.itemKey(item));
        },
        isSelectedByKey(kind, id) {
            return this.selectedIds.includes(kind + '-' + id);
        },
        toggleSelect(item) {
            const key = this.itemKey(item);
            if (this.selectedIds.includes(key)) {
                this.selectedIds = this.selectedIds.filter((k) => k !== key);
            } else {
                this.selectedIds = [...this.selectedIds, key];
            }
        },
        toggleSelectByKey(kind, id) {
            const key = kind + '-' + id;
            if (this.selectedIds.includes(key)) {
                this.selectedIds = this.selectedIds.filter((k) => k !== key);
            } else {
                this.selectedIds = [...this.selectedIds, key];
            }
        },
        selectAll() {
            this.selectionMode = true;
            this.selectedIds = this.items.map((i) => this.itemKey(i));
        },
        cancelSelection() {
            this.selectionMode = false;
            this.selectedIds = [];
        },
        get hasSelection() {
            return this.selectedIds.length > 0;
        },
        getSelectedPayload() {
            const idSet = new Set(this.selectedIds);
            return this.items.filter((i) => idSet.has(this.itemKey(i))).map((i) => ({ kind: i.kind, id: i.id }));
        },

        async restoreSelected() {
            const payload = this.getSelectedPayload();
            if (payload.length === 0 || this.restoringSelected) return;
            const keysToRemove = new Set(payload.map((p) => p.kind + '-' + p.id));
            this.restoringSelected = true;
            try {
                const result = await $wire.$call('restoreTrashItems', payload);
                if (result && result.restored > 0) {
                    this.items = this.items.filter((i) => !keysToRemove.has(this.itemKey(i)));
                }
                this.selectedIds = [];
                this.selectionMode = false;
            } finally {
                this.restoringSelected = false;
            }
        },
        confirmDeleteSelected() {
            const payload = this.getSelectedPayload();
            if (payload.length === 0) return;
            this.pendingForceDeletePayload = payload;
            $flux.modal('delete-selected').show();
        },
        async forceDeleteSelected() {
            if (!this.pendingForceDeletePayload?.length || this.forceDeletingSelected) return;
            this.forceDeletingSelected = true;
            const payload = this.pendingForceDeletePayload;
            this.pendingForceDeletePayload = null;
            try {
                await $wire.$call('forceDeleteTrashItems', payload);
                const keysToRemove = new Set(payload.map((p) => p.kind + '-' + p.id));
                this.items = this.items.filter((i) => !keysToRemove.has(this.itemKey(i)));
                this.selectedIds = [];
                this.selectionMode = false;
                $flux.modal('delete-selected').close();
            } finally {
                this.forceDeletingSelected = false;
            }
        },

        refreshViewportMode() {
            this.useViewportSheet = window.innerWidth <= 480;
            if (this.open) {
                queueMicrotask(() => this.measurePopoverDock());
            }
        },

        get panelSurfaceClass() {
            const shell =
                'flex min-h-0 flex-col overflow-visible rounded-md border border-border bg-white text-foreground shadow-lg ring-1 ring-black/5 dark:bg-zinc-900 dark:ring-white/10 z-[2147483647]';
            if (this.useViewportSheet) {
                return shell + ' fixed inset-x-3 bottom-4 max-h-[min(70vh,24rem)] min-w-0 max-w-md';
            }

            return shell + ' fixed min-w-0';
        },

        /**
         * Viewport-fixed coordinates from the dock anchor (compact control) or trigger (getBoundingClientRect).
         * Clears the fixed Flux sidebar column so the panel is never drawn under its opaque surface
         * (expanded sidebar is wider than the trigger; collapsed is not — both need the same rule).
         */
        measurePopoverDock() {
            const el = this.$refs.dockAnchor ?? this.$refs.trigger;
            if (!el || this.useViewportSheet) {
                this.panelDockStyle = '';
                return;
            }

            const r = el.getBoundingClientRect();
            const vh = window.innerHeight;
            const vw = window.innerWidth;
            const margin = 8;
            const gap = 2;
            const maxW = 320;

            const sidebar = document.querySelector('[data-flux-sidebar]');
            const sb = sidebar ? sidebar.getBoundingClientRect() : null;

            let left;
            let width;

            if (sb && sb.left > vw * 0.5) {
                const mainRight = sb.left - gap;
                width = Math.min(maxW, Math.max(120, mainRight - margin - margin));
                const preferLeft = r.left - gap - width;
                left = Math.max(margin, Math.min(preferLeft, mainRight - width));
            } else {
                left = Math.max(r.right + gap, sb ? sb.right + gap : r.right + gap);
                width = Math.min(maxW, Math.max(0, vw - left - margin));
            }

            const top = Math.max(margin, r.top);
            const maxHeight = Math.min(360, Math.max(120, vh - top - margin));

            this.panelDockStyle = [
                'position:fixed',
                'top:' + top + 'px',
                'left:' + left + 'px',
                'width:' + width + 'px',
                'max-height:' + maxHeight + 'px',
                'box-sizing:border-box',
            ].join(';');
        },

        init() {
            this._dockHandler = () => {
                if (!this.open) {
                    return;
                }
                if (this.useViewportSheet) {
                    return;
                }
                if (this._dockRaf != null) {
                    return;
                }
                this._dockRaf = requestAnimationFrame(() => {
                    this._dockRaf = null;
                    this.measurePopoverDock();
                });
            };
            window.addEventListener('scroll', this._dockHandler, true);
            window.addEventListener('resize', this._dockHandler);

            const sidebarEl = document.querySelector('[data-flux-sidebar]');
            if (sidebarEl && typeof ResizeObserver !== 'undefined') {
                this._sidebarResizeObserver = new ResizeObserver(() => this._dockHandler());
                this._sidebarResizeObserver.observe(sidebarEl);
            }
        },

        destroy() {
            if (this._dockRaf != null) {
                cancelAnimationFrame(this._dockRaf);
                this._dockRaf = null;
            }
            if (this._sidebarResizeObserver) {
                this._sidebarResizeObserver.disconnect();
                this._sidebarResizeObserver = null;
            }
            if (this._dockHandler) {
                window.removeEventListener('scroll', this._dockHandler, true);
                window.removeEventListener('resize', this._dockHandler);
            }
        },

        async openPanel() {
            if (this.open) {
                return;
            }

            this.refreshViewportMode();
            this.open = true;
            this.$dispatch('dropdown-opened');

            await this.$nextTick();
            this.measurePopoverDock();
            requestAnimationFrame(() => this.measurePopoverDock());

            if (this.items.length === 0 && !this.loading) {
                await this.loadFirst();
                await this.$nextTick();
                this.measurePopoverDock();
            }
        },

        async loadFirst() {
            if (this.loading) return;

            this.loading = true;
            this.showSpinner = false;
            if (this._loadingSpinnerTimeout) clearTimeout(this._loadingSpinnerTimeout);
            this._loadingSpinnerTimeout = setTimeout(() => {
                if (this.loading) this.showSpinner = true;
            }, 200);

            try {
                const response = await $wire.$call('loadTrashItems', null, 10);
                this.items = response?.items ?? [];
                this.hasMore = Boolean(response?.hasMore);
                this.lastDeletedAt = response?.lastDeletedAt ?? null;
            } catch (e) {
                this.items = [];
                this.hasMore = false;
                $wire.$dispatch('toast', { type: 'error', message: this.loadErrorToast });
            } finally {
                if (this._loadingSpinnerTimeout) clearTimeout(this._loadingSpinnerTimeout);
                this._loadingSpinnerTimeout = null;
                this.loading = false;
                this.showSpinner = false;
            }
        },

        async loadMore() {
            if (this.loadingMore || !this.hasMore || this.lastDeletedAt == null) {
                return;
            }

            this.loadingMore = true;

            try {
                const response = await $wire.$call('loadTrashItems', this.lastDeletedAt, 10);
                const newItems = response?.items ?? [];
                if (newItems.length) {
                    this.items.push(...newItems);
                }
                this.hasMore = Boolean(response?.hasMore);
                this.lastDeletedAt = response?.lastDeletedAt ?? null;
            } catch (e) {
                this.hasMore = false;
                $wire.$dispatch('toast', { type: 'error', message: this.loadMoreErrorToast });
            } finally {
                this.loadingMore = false;
            }
        },

        close(focusAfter) {
            if (!this.open) return;

            this.open = false;
            this.cancelSelection();
            setTimeout(() => this.$dispatch('dropdown-closed'), 50);
            focusAfter && focusAfter.focus();
        },

        kindLabels: {
            task: @js(__('Task')),
            project: @js(__('Project')),
            event: @js(__('Event')),
        },

        kindLabel(kind) {
            return this.kindLabels[kind] || kind;
        },

        async restore(item) {
            if (this.restoringId != null) return;

            this.restoringId = item.kind + '-' + item.id;

            try {
                const ok = await $wire.$call('restoreTrashItem', item.kind, item.id);
                if (ok) {
                    this.items = this.items.filter((i) => !(i.kind === item.kind && i.id === item.id));
                    if (item.kind === 'task' && item.id != null) {
                        window.dispatchEvent(
                            new CustomEvent('workspace-item-visibility-updated', {
                                detail: { kind: 'task', itemId: item.id, visible: true },
                                bubbles: true,
                            }),
                        );
                    }
                }
            } finally {
                this.restoringId = null;
            }
        },

        async forceDelete(item) {
            if (this.forceDeletingId != null) return;

            this.forceDeletingId = item.kind + '-' + item.id;

            try {
                const ok = await $wire.$call('forceDeleteTrashItem', item.kind, item.id);
                if (ok) {
                    this.items = this.items.filter((i) => !(i.kind === item.kind && i.id === item.id));
                }
            } finally {
                this.forceDeletingId = null;
            }
        },

        async deleteAll() {
            if (this.deletingAll) return;
            this.deletingAll = true;
            try {
                await $wire.$call('forceDeleteAllTrashItems');
                this.items = [];
                this.hasMore = false;
                this.lastDeletedAt = null;
                $flux.modal('delete-all').close();
            } finally {
                this.deletingAll = false;
            }
        },

        async confirmForceDeleteItem() {
            if (this.itemToForceDelete) {
                await this.forceDelete(this.itemToForceDelete);
            }
            this.itemToForceDelete = null;
            $flux.modal('delete-item').close();
        },
    }"
    @keydown.escape.prevent.stop="close($refs.dockAnchor || $refs.trigger)"
    @focusin.window="($refs.panel && !$refs.panel.contains($event.target)) && close($refs.dockAnchor || $refs.trigger)"
    @workspace-item-trashed.window="addTrashedItem($event.detail)"
    @workspace-item-trashed-rollback.window="removeTrashedItemRollback($event.detail)"
    @resize.window="refreshViewportMode()"
    class="relative z-20 w-full overflow-visible"
>
    @isset($trigger)
        <div x-ref="trigger" @click="openPanel()" class="cursor-pointer">
            {{ $trigger }}
        </div>
    @else
        <div
            x-ref="trigger"
            class="flex w-full justify-start in-data-flux-sidebar-collapsed-desktop:justify-center"
        >
            <button
                x-ref="dockAnchor"
                type="button"
                @click="openPanel()"
                class="cursor-pointer inline-flex h-7 min-h-7 max-w-full items-center justify-start gap-2 rounded-md px-2.5 text-xs font-bold leading-none text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 in-data-flux-sidebar-collapsed-desktop:justify-center in-data-flux-sidebar-collapsed-desktop:px-2"
                aria-haspopup="true"
                :aria-expanded="open"
                aria-label="{{ __('Open trash bin') }}"
                title="{{ __('Trash') }}"
            >
                <flux:icon name="trash" class="size-4 shrink-0 text-current" />
                <span class="in-data-flux-sidebar-collapsed-desktop:hidden">{{ __('Trash') }}</span>
            </button>
        </div>
    @endisset

    <template x-teleport="#workspace-trash-portal">
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
            @click.outside="(e) => !$refs.trigger?.contains(e.target) && close($refs.dockAnchor || $refs.trigger)"
            @click.stop
            :class="panelSurfaceClass"
            x-bind:style="useViewportSheet ? null : panelDockStyle || null"
            role="dialog"
            aria-modal="true"
            aria-label="{{ __('Trash bin for items') }}"
        >
        <div class="flex items-center justify-between gap-2 border-b border-border/60 px-3 py-2.5">
            <div class="flex items-center gap-2 min-w-0">
                <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                    <flux:icon name="archive-box" class="size-3" />
                </div>
                <span class="text-xs font-semibold tracking-wide text-muted-foreground truncate">
                    {{ __('Trash bin for items') }}
                </span>
            </div>

            <button
                type="button"
                class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-muted-foreground hover:bg-muted/60 hover:text-foreground"
                @click="close($refs.dockAnchor || $refs.trigger)"
                aria-label="{{ __('Close trash bin') }}"
            >
                <flux:icon name="x-mark" class="size-3" />
            </button>
        </div>

        <div class="min-h-[8rem] flex-1 space-y-2 overflow-y-auto px-3 py-2.5 text-[11px]">
            <template x-if="loading && items.length === 0 && showSpinner">
                <div class="flex flex-col items-center justify-center gap-2 py-8 text-muted-foreground">
                    <flux:icon name="arrow-path" class="size-6 animate-spin" />
                    <span>{{ __('Loading...') }}</span>
                </div>
            </template>

            <template x-if="!loading && items.length === 0">
                <p class="py-6 text-center text-muted-foreground/80">
                    {{ __('Trash is empty.') }}
                </p>
            </template>

            <template x-if="items.length > 0">
                <div class="space-y-1.5">
                    <template x-for="(item, index) in items" :key="item.kind + '-' + item.id">
                        <div
                            class="flex items-start justify-between gap-2 rounded-md bg-muted/60 px-2 py-1.5"
                            :class="selectionMode ? 'cursor-pointer' : ''"
                            @click="selectionMode && toggleSelectByKey(item.kind, item.id)"
                        >
                            <div class="min-w-0 flex-1 flex items-start gap-2">
                                <template x-if="selectionMode">
                                    <div class="flex shrink-0 pt-0.5">
                                        <input
                                            type="checkbox"
                                            class="h-3.5 w-3.5 cursor-pointer rounded border-border text-primary focus:ring-primary/20"
                                            :checked="isSelectedByKey(item.kind, item.id)"
                                            @click.stop="toggleSelectByKey(item.kind, item.id)"
                                            aria-label="{{ __('Select item') }}"
                                        />
                                    </div>
                                </template>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-1.5">
                                        <span
                                            class="shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground/90"
                                            x-text="kindLabel(item.kind)"
                                        ></span>
                                        <span class="truncate text-[11px] font-medium text-foreground/90" x-text="item.title"></span>
                                    </div>
                                    <span class="text-[10px] text-muted-foreground/80" x-text="item.deleted_at_display"></span>
                                </div>
                            </div>
                            <template x-if="!selectionMode">
                                <div class="flex shrink-0 items-center gap-0.5">
                                    <flux:tooltip :content="__('Restore')">
                                        <button
                                            type="button"
                                            class="inline-flex h-6 w-6 items-center justify-center rounded-full text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-50"
                                            :disabled="restoringId === (item.kind + '-' + item.id)"
                                            @click="restore(item)"
                                            aria-label="{{ __('Restore') }}"
                                        >
                                            <flux:icon name="arrow-uturn-left" class="size-3.5" />
                                        </button>
                                    </flux:tooltip>
                                    <flux:tooltip :content="__('Permanently delete')">
                                        <button
                                            type="button"
                                            class="inline-flex h-6 w-6 items-center justify-center rounded-full text-muted-foreground hover:bg-red-500/10 hover:text-red-600 dark:hover:text-red-400 disabled:opacity-50"
                                            :disabled="forceDeletingId === (item.kind + '-' + item.id)"
                                            @click="itemToForceDelete = item; $flux.modal('delete-item').show()"
                                            aria-label="{{ __('Permanently delete') }}"
                                        >
                                            <flux:icon name="trash" class="size-3.5" />
                                        </button>
                                    </flux:tooltip>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </template>
        </div>

        <div class="border-t border-border/60 px-3 py-1.5 flex flex-col gap-1" x-show="items.length > 0" x-cloak>
            <template x-if="selectionMode">
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="rounded-full px-2.5 py-1 text-[11px] font-medium text-muted-foreground hover:bg-muted/60 hover:text-foreground"
                        @click="cancelSelection()"
                    >
                        {{ __('Cancel') }}
                    </button>
                    <flux:tooltip :content="__('Restore selected')">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-medium text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-50"
                            :disabled="!hasSelection || restoringSelected"
                            @click="restoreSelected()"
                            aria-label="{{ __('Restore selected') }}"
                        >
                            <flux:icon name="arrow-uturn-left" class="size-3.5" />
                            <span>{{ __('Restore') }}</span>
                        </button>
                    </flux:tooltip>
                    <flux:tooltip :content="__('Permanently delete selected')">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-medium text-red-600 hover:bg-red-500/10 dark:text-red-400 disabled:opacity-50"
                            :disabled="!hasSelection || forceDeletingSelected"
                            @click="confirmDeleteSelected()"
                            aria-label="{{ __('Permanently delete selected') }}"
                        >
                            <flux:icon name="trash" class="size-3.5" />
                            <span>{{ __('Delete') }}</span>
                        </button>
                    </flux:tooltip>
                </div>
            </template>
            <template x-if="!selectionMode">
                <div class="flex flex-col gap-1">
                    <button
                        x-show="hasMore"
                        type="button"
                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium text-primary hover:text-primary/80 disabled:opacity-70"
                        :class="{ 'animate-pulse': loadingMore }"
                        :disabled="loadingMore"
                        @click="loadMore()"
                    >
                        <flux:icon name="chevron-down" class="size-3" />
                        <span x-text="loadingMore ? '{{ __('Loading...') }}' : '{{ __('Load more') }}'"></span>
                    </button>
                    <div class="flex items-center justify-between gap-2">
                        <flux:tooltip :content="__('Select items')">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-full px-2 py-1 text-[11px] font-medium text-muted-foreground hover:bg-muted/60 hover:text-foreground"
                                @click="selectAll()"
                                aria-label="{{ __('Select items') }}"
                            >
                                <flux:icon name="squares-2x2" class="size-3.5" />
                                <span>{{ __('Select items') }}</span>
                            </button>
                        </flux:tooltip>
                        <flux:tooltip :content="__('Empty the trash')">
                            <button
                                type="button"
                                class="inline-flex items-center justify-center gap-1 rounded-full px-2 py-1 text-[11px] font-medium text-red-600 hover:bg-red-500/10 dark:text-red-400 disabled:opacity-70"
                                :disabled="deletingAll"
                                @click="$flux.modal('delete-all').show()"
                            >
                                <flux:icon name="trash" class="size-3" />
                                <span x-text="deletingAll ? '{{ __('Emptying...') }}' : '{{ __('Empty trash') }}'"></span>
                            </button>
                        </flux:tooltip>
                    </div>
                </div>
            </template>
        </div>
        </div>
    </template>

    <flux:modal name="delete-selected" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete selected items?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Permanently delete the selected items? This cannot be undone.') }}
                </flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button
                    type="button"
                    variant="danger"
                    @click="forceDeleteSelected()"
                >
                    <span x-text="forceDeletingSelected ? '{{ __('Deleting...') }}' : '{{ __('Delete selected') }}'"></span>
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="delete-all" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Empty trash?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Permanently delete all items in trash? This cannot be undone.') }}
                </flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button
                    type="button"
                    variant="danger"
                    @click="deleteAll()"
                >
                    <span x-text="deletingAll ? '{{ __('Emptying...') }}' : '{{ __('Empty trash') }}'"></span>
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="delete-item" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete item?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('You\'re about to delete this item. This action cannot be reversed.') }}
                </flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button
                    type="button"
                    variant="danger"
                    @click="confirmForceDeleteItem()"
                >
                    {{ __('Delete item') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>

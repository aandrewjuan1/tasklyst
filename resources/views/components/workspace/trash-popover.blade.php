@props([
    'position' => 'bottom',
    'align' => 'end',
])

<div
    wire:ignore
    x-data="{
        open: false,
        placementVertical: @js($position),
        placementHorizontal: @js($align),
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
        panelPlacementClassesValue: 'absolute top-full right-0 mt-1',

        itemKey(item) {
            return item.kind + '-' + item.id;
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

        async openPanel() {
            if (this.open) {
                return;
            }

            const button = this.$refs.trigger;
            if (button) {
                const vh = window.innerHeight;
                const vw = window.innerWidth;
                const PANEL_HEIGHT_EST = 360;
                const PANEL_WIDTH_EST = 320;
                const rect = button.getBoundingClientRect();
                const contentLeft = vw < 768 ? 16 : 320;
                const effectivePanelWidth = Math.min(PANEL_WIDTH_EST, vw - 32);

                const spaceBelow = vh - rect.bottom;
                const spaceAbove = rect.top;

                if (spaceBelow >= PANEL_HEIGHT_EST || spaceBelow >= spaceAbove) {
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
                if (vw <= 480) {
                    this.panelPlacementClassesValue = 'fixed inset-x-3 bottom-4 max-h-[min(70vh,24rem)]';
                } else if (v === 'top' && h === 'end') {
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
            }

            this.open = true;
            this.$dispatch('dropdown-opened');

            if (this.items.length === 0 && !this.loading) {
                await this.loadFirst();
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
    @keydown.escape.prevent.stop="close($refs.trigger)"
    @focusin.window="($refs.panel && !$refs.panel.contains($event.target)) && close($refs.trigger)"
    class="relative"
>
    @isset($trigger)
        <div x-ref="trigger" @click="openPanel()" class="cursor-pointer">
            {{ $trigger }}
        </div>
    @else
        <button
            x-ref="trigger"
            type="button"
            @click="openPanel()"
            class="cursor-pointer inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium text-muted-foreground hover:bg-muted/60 hover:text-foreground"
            aria-haspopup="true"
            :aria-expanded="open"
            aria-label="{{ __('Open trash bin') }}"
        >
            <flux:icon name="archive-box" class="size-3.5" />
            <span>{{ __('Trash') }}</span>
        </button>
    @endisset

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
        @click.outside="close($refs.trigger)"
        @click.stop
        :class="panelPlacementClassesValue"
        class="z-50 flex min-w-72 max-w-md flex-col overflow-hidden rounded-md border border-border bg-white text-foreground shadow-md dark:bg-zinc-900 contain-[paint]"
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
                @click="close($refs.trigger)"
                aria-label="{{ __('Close trash bin') }}"
            >
                <flux:icon name="x-mark" class="size-3" />
            </button>
        </div>

        <div class="max-h-80 min-h-32 space-y-2 overflow-y-auto px-3 py-2.5 text-[11px]">
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
</div>

@props([
    'taskId' => 0,
    'currentProjectId' => null,
    'currentProjectName' => null,
    'position' => 'bottom',
    'align' => 'end',
])

<div
    wire:ignore
    x-data="{
        taskId: @js($taskId),
        currentProjectId: @js($currentProjectId),
        currentProjectName: @js($currentProjectName),
        open: false,
        placementVertical: @js($position),
        placementHorizontal: @js($align),
        items: [],
        hasMore: false,
        loading: false,
        showSpinner: false,
        _loadingSpinnerTimeout: null,
        loadErrorToast: @js(__('Could not load projects. Please try again.')),
        updateErrorToast: @js(__('Could not update task. Please try again.')),
        updateSuccessToast: @js(__('Task updated.')),
        panelPlacementClassesValue: 'absolute top-full right-0 mt-1',

        async openPanel() {
            if (this.open) {
                this.close(this.$refs.trigger);
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
                await this.loadProjects();
            }
        },

        async loadProjects() {
            if (this.loading) return;

            this.loading = true;
            this.showSpinner = false;
            if (this._loadingSpinnerTimeout) clearTimeout(this._loadingSpinnerTimeout);
            this._loadingSpinnerTimeout = setTimeout(() => {
                if (this.loading) this.showSpinner = true;
            }, 200);

            try {
                const response = await $wire.$parent.$call('loadProjectsForParentSelection', null, 50);
                this.items = response?.items ?? [];
                this.hasMore = Boolean(response?.hasMore);
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

        close(focusAfter) {
            if (!this.open) return;

            this.open = false;
            setTimeout(() => this.$dispatch('dropdown-closed'), 50);
            focusAfter && focusAfter.focus();
        },

        removeTrashedProject(projectId) {
            if (projectId == null) return;
            const id = Number(projectId);
            if (!Number.isFinite(id)) return;
            this.items = this.items.filter((item) => Number(item.id) !== id);
            if (Number(id) === Number(this.currentProjectId)) {
                this.currentProjectId = null;
                this.currentProjectName = null;
            }
        },

        onProjectNameUpdated(detail) {
            if (!detail?.projectId || detail.name == null) return;
            const id = Number(detail.projectId);
            if (Number(id) === Number(this.currentProjectId)) this.currentProjectName = detail.name;
            const item = this.items?.find((i) => Number(i.id) === id);
            if (item) item.name = detail.name;
        },

        async selectProject(item) {
            const projectId = item === null ? null : item.id;
            const projectName = item === null ? null : item.name;

            if (projectId != null && Number(projectId) === Number(this.currentProjectId)) {
                this.close(this.$refs.trigger);
                return;
            }

            // PHASE 1: Snapshot for rollback
            const snapshot = {
                projectId: this.currentProjectId,
                projectName: this.currentProjectName ?? null,
            };

            try {
                // PHASE 2: Optimistic update – dispatch so the card pill and parent's subtasks update (only project keys so we don't clear the event pill)
                window.dispatchEvent(new CustomEvent('workspace-task-parent-set', {
                    detail: {
                        taskId: this.taskId,
                        projectId,
                        projectName,
                        previousProjectId: snapshot.projectId ?? undefined,
                    },
                    bubbles: true,
                }));
                if (projectId == null && snapshot.projectId != null) {
                    window.dispatchEvent(new CustomEvent('workspace-subtask-unbound', {
                        detail: {
                            taskId: this.taskId,
                            unboundProjectId: snapshot.projectId,
                            unboundEventId: null,
                        },
                        bubbles: true,
                    }));
                }

                // PHASE 3: Call server asynchronously
                const promise = $wire.$parent.$call('updateTaskProperty', this.taskId, 'projectId', projectId, true);

                // PHASE 4: Handle response
                await promise;
                $wire.$dispatch('toast', { type: 'success', message: this.updateSuccessToast });
                this.close(this.$refs.trigger);
            } catch (e) {
                // PHASE 5: Rollback on error (so subtasks can remove from the target we had added to)
                window.dispatchEvent(new CustomEvent('workspace-task-parent-set', {
                    detail: {
                        taskId: this.taskId,
                        projectId: snapshot.projectId,
                        projectName: snapshot.projectName,
                        previousProjectId: projectId ?? undefined,
                    },
                    bubbles: true,
                }));
                $wire.$dispatch('toast', { type: 'error', message: this.updateErrorToast });
            }
        },
    }"
    @keydown.escape.prevent.stop="close($refs.trigger)"
    @click.outside="close($refs.trigger)"
    @workspace-project-trashed.window="removeTrashedProject($event.detail.projectId)"
    @workspace-project-name-updated.window="onProjectNameUpdated($event.detail)"
    class="relative"
>
    <div x-ref="trigger" @click="openPanel()" class="cursor-pointer">
        {{ $slot }}
    </div>

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
        @click.stop
        :class="panelPlacementClassesValue"
        class="z-50 flex min-w-72 max-w-md flex-col overflow-hidden rounded-md border border-border bg-white text-foreground shadow-md dark:bg-zinc-900 contain-[paint]"
        role="dialog"
        aria-modal="true"
        aria-label="{{ __('Select project') }}"
    >
        <div class="flex items-center justify-between gap-2 border-b border-border bg-muted/30 px-3 py-2.5">
            <span class="text-xs font-semibold tracking-wide text-foreground/90 truncate">
                {{ __('Select project') }}
            </span>
            <button
                type="button"
                class="inline-flex h-6 w-6 shrink-0 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                @click="close($refs.trigger)"
                aria-label="{{ __('Close') }}"
            >
                <flux:icon name="x-mark" class="size-3" />
            </button>
        </div>

        <div class="max-h-80 min-h-32 overflow-y-auto py-1 text-[11px]">
            <template x-if="loading && items.length === 0 && showSpinner">
                <div class="flex flex-col items-center justify-center gap-2 py-8 text-muted-foreground">
                    <flux:icon name="arrow-path" class="size-6 animate-spin" />
                    <span>{{ __('Loading...') }}</span>
                </div>
            </template>

            <template x-if="!loading || items.length > 0">
                <div class="divide-y divide-border/60">
                    <button
                        type="button"
                        class="flex w-full cursor-pointer items-center gap-2 px-3 py-2.5 text-left transition-colors hover:bg-muted/70 focus-visible:bg-muted/50 focus-visible:outline-none"
                        @click="selectProject(null)"
                    >
                        <flux:icon name="minus-circle" class="size-4 shrink-0 text-muted-foreground" />
                        <span class="truncate text-muted-foreground">{{ __('None') }}</span>
                        <span class="truncate text-muted-foreground/80">— {{ __('No project') }}</span>
                    </button>

                    <template x-for="(item, index) in items" :key="item.id">
                        <button
                            type="button"
                            class="flex w-full cursor-pointer items-center gap-2.5 px-3 py-2.5 text-left transition-colors hover:bg-muted/70 focus-visible:bg-muted/50 focus-visible:outline-none"
                            @click="selectProject(item)"
                        >
                            <flux:icon name="folder" class="size-4 shrink-0 text-blue-500/80 dark:text-blue-400/80" />
                            <span class="min-w-0 flex-1 truncate font-medium text-foreground" x-text="item.name"></span>
                        </button>
                    </template>
                </div>
            </template>
        </div>
    </div>
</div>

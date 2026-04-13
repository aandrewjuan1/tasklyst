@props([
    'viewMode' => 'list',
])

<div
    class="inline-flex shrink-0 transition-opacity duration-150 ease-out"
    wire:loading.class="pointer-events-none"
    wire:target="viewMode"
    x-data="{
        mode: @js($viewMode),
        hydrated: false,
        _modeListener: null,
        init() {
            this.syncMode(this.$wire.viewMode ?? this.mode);
            this.$watch('$wire.viewMode', (value) => this.syncMode(value));
            this._modeListener = (event) => {
                const value = event?.detail?.mode ?? null;
                if (typeof value === 'string' && value !== '') {
                    this.syncMode(value);
                }
            };
            window.addEventListener('workspace-view-mode-changed', this._modeListener);
            requestAnimationFrame(() => {
                this.hydrated = true;
                this.applyButtonClasses();
            });
        },
        destroy() {
            if (this._modeListener) {
                window.removeEventListener('workspace-view-mode-changed', this._modeListener);
            }
        },
        syncMode(value) {
            this.mode = value ?? 'list';
            if (window.Alpine?.store) {
                let store = Alpine.store('workspaceView');
                if (!store || typeof store !== 'object') {
                    Alpine.store('workspaceView', { mode: this.mode });
                } else {
                    store.mode = this.mode;
                }
            }
            if (this.hydrated) {
                this.applyButtonClasses();
            }
        },
        isActive(value) {
            return this.mode === value;
        },
        applyButtonClasses() {
            const listButton = this.$refs.listButton;
            const kanbanButton = this.$refs.kanbanButton;
            if (!listButton || !kanbanButton) {
                return;
            }

            const activeClasses = ['bg-brand-blue', 'text-white', 'shadow-sm', 'hover:bg-brand-blue'];
            const inactiveClasses = [
                'bg-muted/70',
                'text-muted-foreground',
                'hover:bg-muted',
                'hover:text-foreground',
                'dark:bg-zinc-900/70',
                'dark:text-zinc-300',
                'dark:hover:bg-zinc-800/90',
                'dark:hover:text-zinc-100',
            ];
            const allToggleClasses = [...activeClasses, ...inactiveClasses];

            for (const className of allToggleClasses) {
                listButton.classList.remove(className);
                kanbanButton.classList.remove(className);
            }

            if (this.mode === 'kanban') {
                kanbanButton.classList.add(...activeClasses);
                listButton.classList.add(...inactiveClasses);
            } else {
                listButton.classList.add(...activeClasses);
                kanbanButton.classList.add(...inactiveClasses);
            }
        },
        setView(value) {
            if (this.mode === value) {
                return;
            }

            this.syncMode(value);
            window.dispatchEvent(new CustomEvent('workspace-view-mode-changed', { detail: { mode: value } }));
            this.$wire.set('viewMode', value);
            const url = new URL(window.location.href);
            url.searchParams.set('view', value);
            history.replaceState(null, '', url.pathname + url.search);
        },
    }"
>
    <div
        wire:ignore
        class="inline-flex h-10 items-stretch gap-0.5 rounded-xl border border-border/60 bg-muted/55 p-1 shadow-sm ring-1 ring-brand-purple/10 dark:border-zinc-600/70 dark:bg-zinc-900/65 dark:ring-zinc-700/40"
        role="tablist"
        aria-label="{{ __('Workspace view') }}"
    >
        <button
            type="button"
            role="tab"
            aria-controls="workspace-list-panel"
            id="workspace-view-list"
            aria-selected="{{ $viewMode === 'list' ? 'true' : 'false' }}"
            :aria-selected="isActive('list')"
            x-ref="listButton"
            class="inline-flex h-full min-w-13 items-center justify-center rounded-lg px-3 text-sm font-semibold transition-colors duration-150 ease-out active:bg-transparent active:text-current focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/50 {{ $viewMode === 'list'
                ? 'bg-brand-blue text-white shadow-sm hover:bg-brand-blue'
                : 'bg-muted/70 text-muted-foreground hover:bg-muted hover:text-foreground dark:bg-zinc-900/70 dark:text-zinc-300 dark:hover:bg-zinc-800/90 dark:hover:text-zinc-100' }}"
            style="-webkit-tap-highlight-color: transparent;"
            @click="setView('list')"
        >
            {{ __('List') }}
        </button>
        <button
            type="button"
            role="tab"
            aria-controls="workspace-kanban-panel"
            id="workspace-view-kanban"
            aria-selected="{{ $viewMode === 'kanban' ? 'true' : 'false' }}"
            :aria-selected="isActive('kanban')"
            x-ref="kanbanButton"
            class="inline-flex h-full min-w-13 items-center justify-center rounded-lg px-3 text-sm font-semibold transition-colors duration-150 ease-out active:bg-transparent active:text-current focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue/50 {{ $viewMode === 'kanban'
                ? 'bg-brand-blue text-white shadow-sm hover:bg-brand-blue'
                : 'bg-muted/70 text-muted-foreground hover:bg-muted hover:text-foreground dark:bg-zinc-900/70 dark:text-zinc-300 dark:hover:bg-zinc-800/90 dark:hover:text-zinc-100' }}"
            style="-webkit-tap-highlight-color: transparent;"
            @click="setView('kanban')"
        >
            {{ __('Kanban') }}
        </button>
    </div>
</div>

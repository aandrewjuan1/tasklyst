<div
    x-data="{
        show: false,
        type: 'success',
        icon: null,
        message: '',
        timeout: null,
        init() {
            Livewire.on('toast', (event) => {
                const data = Array.isArray(event) ? event[0] : event;
                this.type = data?.type || 'success';
                this.icon = data?.icon || null;
                this.message = data?.message || '';
                this.showToast();
            });
        },
        showToast() {
            if (this.timeout) {
                clearTimeout(this.timeout);
            }
            this.show = true;
            this.timeout = setTimeout(() => {
                this.show = false;
            }, 3000);
        },
        close() {
            if (this.timeout) {
                clearTimeout(this.timeout);
            }
            this.show = false;
        }
    }"
    x-show="show"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-y-4 scale-95"
    x-transition:enter-end="opacity-100 translate-y-0 scale-100"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100 translate-y-0 scale-100"
    x-transition:leave-end="opacity-0 translate-y-4 scale-95"
    style="display: none;"
    class="fixed bottom-4 right-4 z-50 max-w-sm w-full"
>
    <div
        :class="{
            'bg-green-50 dark:bg-green-950/40 border-green-200 dark:border-green-800/60 text-green-800 dark:text-green-200': type === 'success',
            'bg-red-50 dark:bg-red-950/40 border-red-200 dark:border-red-800/60 text-red-800 dark:text-red-200': type === 'error',
            'bg-blue-50 dark:bg-blue-950/40 border-blue-200 dark:border-blue-800/60 text-blue-800 dark:text-blue-200': type === 'info'
        }"
        class="rounded-lg border px-4 py-3 shadow-lg shadow-black/10 dark:shadow-black/30 flex items-center gap-3"
    >
        <span
            :class="{
                'text-green-600 dark:text-green-400': type === 'success',
                'text-red-600 dark:text-red-400': type === 'error',
                'text-blue-600 dark:text-blue-400': type === 'info'
            }"
            class="shrink-0 flex items-center justify-center rounded-full bg-white/60 dark:bg-black/20 p-1.5"
            aria-hidden="true"
        >
            {{-- Flux icons are rendered server-side, so we pre-render the set we use and toggle via Alpine. --}}
            <span x-show="icon" x-cloak>
                <flux:icon name="plus-circle" class="size-5" x-show="icon === 'plus-circle'" />
                <flux:icon name="pencil-square" class="size-5" x-show="icon === 'pencil-square'" />
                <flux:icon name="trash" class="size-5" x-show="icon === 'trash'" />
                <flux:icon name="exclamation-triangle" class="size-5" x-show="icon === 'exclamation-triangle'" />
                <flux:icon name="bolt" class="size-5" x-show="icon === 'bolt'" />
                <flux:icon name="squares-2x2" class="size-5" x-show="icon === 'squares-2x2'" />
                <flux:icon name="clock" class="size-5" x-show="icon === 'clock'" />
                <flux:icon name="tag" class="size-5" x-show="icon === 'tag'" />
                <flux:icon name="arrow-path" class="size-5" x-show="icon === 'arrow-path'" />
                <flux:icon name="check-circle" class="size-5" x-show="icon === 'check-circle'" />
                <flux:icon name="information-circle" class="size-5" x-show="icon === 'information-circle'" />
            </span>
            <span x-show="!icon">
                <flux:icon name="check-circle" class="size-5" x-show="type === 'success'" />
                <flux:icon name="exclamation-circle" class="size-5" x-show="type === 'error'" />
                <flux:icon name="information-circle" class="size-5" x-show="type === 'info'" />
            </span>
        </span>
        <p class="text-sm font-medium leading-snug flex-1 min-w-0" x-text="message"></p>
        <button
            @click="close()"
            class="shrink-0 flex items-center justify-center rounded-md p-1 text-current opacity-60 hover:opacity-100 hover:bg-black/5 dark:hover:bg-white/10 transition-all focus:outline-none focus:ring-2 focus:ring-current focus:ring-offset-2 focus:ring-offset-transparent"
            aria-label="Close"
        >
            <flux:icon name="x-mark" class="size-4" />
        </button>
    </div>
</div>

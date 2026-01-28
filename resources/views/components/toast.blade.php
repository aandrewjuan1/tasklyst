<div
    x-data="{
        show: false,
        type: 'success',
        message: '',
        timeout: null,
        init() {
            Livewire.on('toast', (event) => {
                const data = Array.isArray(event) ? event[0] : event;
                this.type = data?.type || 'success';
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
    x-transition:enter-start="opacity-0 translate-y-2"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 translate-y-2"
    style="display: none;"
    class="fixed top-4 right-4 z-50 max-w-sm w-full"
>
    <div
        :class="{
            'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 text-green-800 dark:text-green-200': type === 'success',
            'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-800 dark:text-red-200': type === 'error'
        }"
        class="rounded-lg border px-4 py-3 shadow-lg flex items-center justify-between gap-4"
    >
        <p class="text-sm font-medium flex-1" x-text="message"></p>
        <button
            @click="close()"
            class="flex-shrink-0 text-current opacity-70 hover:opacity-100 transition-opacity"
            aria-label="Close"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </button>
    </div>
</div>

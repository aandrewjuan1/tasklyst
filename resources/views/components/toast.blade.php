<div
    x-data="{
        show: false,
        type: 'info',
        icon: '',
        message: '',
        queue: [],
        hideTimer: null,
        removeTimer: null,
        unsub: null,
        nextToastId: 0,
        hideDelayMs: 2600,
        leaveDurationMs: 180,
        dedupeWindowMs: 700,
        lastToastFingerprint: '',
        lastToastAt: 0,
        fingerprintFor(toast) {
            return [toast.type, toast.icon || '', toast.message].join('|');
        },
        shouldDedupe(toast) {
            const now = Date.now();
            const fingerprint = this.fingerprintFor(toast);

            if (toast.skipDedupe === true) {
                this.lastToastFingerprint = fingerprint;
                this.lastToastAt = now;

                return false;
            }

            const isDuplicate =
                fingerprint === this.lastToastFingerprint &&
                now - this.lastToastAt <= this.dedupeWindowMs;

            this.lastToastFingerprint = fingerprint;
            this.lastToastAt = now;

            return isDuplicate;
        },
        enqueue(event) {
            const raw = Array.isArray(event) ? event[0] : event;
            if (!raw || typeof raw !== 'object') {
                return;
            }

            const normalizedType = ['success', 'error', 'warning', 'info'].includes(String(raw.type))
                ? String(raw.type)
                : 'info';

            const next = {
                id: ++this.nextToastId,
                type: normalizedType,
                icon: typeof raw.icon === 'string' ? raw.icon : '',
                message: typeof raw.message === 'string' ? raw.message : '',
                skipDedupe: raw.skipDedupe === true,
            };

            if (next.message.trim() === '') {
                return;
            }

            if (this.shouldDedupe(next)) {
                return;
            }

            if (this.show) {
                this.queue.push(next);
                return;
            }

            this.renderToast(next);
        },
        renderToast(toast) {
            this.clearTimers();
            this.type = toast.type;
            this.icon = toast.icon;
            this.message = toast.message;
            this.show = true;

            this.hideTimer = setTimeout(() => {
                this.close();
            }, this.hideDelayMs);
        },
        clearTimers() {
            if (this.hideTimer) {
                clearTimeout(this.hideTimer);
            }
            if (this.removeTimer) {
                clearTimeout(this.removeTimer);
            }
            this.hideTimer = null;
            this.removeTimer = null;
        },
        flushQueue() {
            if (this.show || this.queue.length === 0) {
                return;
            }

            const next = this.queue.shift();
            if (!next) {
                return;
            }

            requestAnimationFrame(() => this.renderToast(next));
        },
        init() {
            this.unsub = Livewire.on('toast', (event) => {
                this.enqueue(event);
            });
        },
        destroy() {
            this.clearTimers();
            if (typeof this.unsub === 'function') {
                this.unsub();
            }
        },
        close() {
            this.clearTimers();
            this.show = false;
            this.removeTimer = setTimeout(() => {
                this.flushQueue();
            }, this.leaveDurationMs + 16);
        }
    }"
    x-show="show"
    x-transition:enter="transform-gpu transition ease-out duration-180"
    x-transition:enter-start="opacity-0 translate-y-2"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transform-gpu transition ease-in duration-180"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 translate-y-2"
    style="display: none;"
    class="pointer-events-none fixed bottom-4 right-4 z-50 w-full max-w-sm px-2 sm:px-0"
>
    <div
        :class="{
            'bg-green-50 dark:bg-green-950/40 border-green-200 dark:border-green-800/60 text-green-800 dark:text-green-200': type === 'success',
            'bg-red-50 dark:bg-red-950/40 border-red-200 dark:border-red-800/60 text-red-800 dark:text-red-200': type === 'error',
            'bg-amber-50 dark:bg-amber-950/40 border-amber-200 dark:border-amber-800/60 text-amber-800 dark:text-amber-200': type === 'warning',
            'bg-blue-50 dark:bg-blue-950/40 border-blue-200 dark:border-blue-800/60 text-blue-800 dark:text-blue-200': type === 'info'
        }"
        class="pointer-events-auto flex items-center gap-3 rounded-lg border px-4 py-3 shadow-lg shadow-black/10 will-change-transform dark:shadow-black/30"
    >
        <span
            :class="{
                'text-green-600 dark:text-green-400': type === 'success',
                'text-red-600 dark:text-red-400': type === 'error',
                'text-amber-600 dark:text-amber-400': type === 'warning',
                'text-blue-600 dark:text-blue-400': type === 'info'
            }"
            class="shrink-0 flex items-center justify-center rounded-full bg-white/60 dark:bg-black/20 p-1.5"
            aria-hidden="true"
        >
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
                <flux:icon name="sun" class="size-5" x-show="icon === 'sun'" />
            </span>
            <span x-show="!icon">
                <flux:icon name="check-circle" class="size-5" x-show="type === 'success'" />
                <flux:icon name="exclamation-circle" class="size-5" x-show="type === 'error'" />
                <flux:icon name="exclamation-triangle" class="size-5" x-show="type === 'warning'" />
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

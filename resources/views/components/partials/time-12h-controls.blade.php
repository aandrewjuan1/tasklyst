{{-- Hour / minute / AM-PM cluster — matches date-picker datetime time row. Parent x-data must provide hour, minute, ampm, updateTime(), disabled. --}}
<div class="flex items-center gap-2">
    <input
        type="number"
        min="1"
        max="12"
        x-model="hour"
        @change="updateTime()"
        placeholder="12"
        x-bind:disabled="disabled"
        class="h-8 w-12 rounded-lg border border-zinc-200 bg-zinc-50 px-1 text-center text-xs text-zinc-900 shadow-sm outline-none ring-0 focus:border-brand-blue focus:bg-white focus:ring-1 focus:ring-brand-blue disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50 dark:focus:border-brand-light-blue dark:focus:ring-brand-light-blue"
    />
    <span class="pb-1 text-sm text-zinc-400 dark:text-zinc-500">:</span>
    <input
        type="number"
        min="0"
        max="59"
        x-model="minute"
        @change="updateTime()"
        placeholder="00"
        x-bind:disabled="disabled"
        class="h-8 w-12 rounded-lg border border-zinc-200 bg-zinc-50 px-1 text-center text-xs text-zinc-900 shadow-sm outline-none ring-0 focus:border-brand-blue focus:bg-white focus:ring-1 focus:ring-brand-blue disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50 dark:focus:border-brand-light-blue dark:focus:ring-brand-light-blue"
    />
    <div class="inline-flex overflow-hidden rounded-full border border-zinc-200 bg-zinc-50 text-[11px] shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <button
            type="button"
            class="px-2 py-1 transition-colors"
            x-bind:class="ampm === 'AM'
                ? 'bg-brand-blue text-white dark:bg-brand-blue'
                : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800'"
            x-bind:disabled="disabled"
            @click.prevent.stop="ampm = 'AM'; updateTime()"
        >
            AM
        </button>
        <button
            type="button"
            class="px-2 py-1 transition-colors"
            x-bind:class="ampm === 'PM'
                ? 'bg-brand-blue text-white dark:bg-brand-blue'
                : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800'"
            x-bind:disabled="disabled"
            @click.prevent.stop="ampm = 'PM'; updateTime()"
        >
            PM
        </button>
    </div>
</div>

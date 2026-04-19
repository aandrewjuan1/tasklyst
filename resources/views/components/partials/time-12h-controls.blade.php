{{-- Hour / minute / AM-PM cluster — matches date-picker datetime time row. Parent x-data must provide hour, minute, ampm, updateTime(), disabled. Pass compact=true for denser controls (e.g. class hours popover). --}}
@php
    $compact = $compact ?? false;
@endphp
<div @class(['flex items-center', 'gap-1' => $compact, 'gap-2' => ! $compact])>
    <input
        type="number"
        min="1"
        max="12"
        x-model="hour"
        @change="updateTime()"
        placeholder="12"
        x-bind:disabled="disabled"
        @class([
            'border border-zinc-200 bg-zinc-50 text-center text-zinc-900 shadow-sm outline-none ring-0 focus:border-brand-blue focus:bg-white focus:ring-1 focus:ring-brand-blue disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50 dark:focus:border-brand-light-blue dark:focus:ring-brand-light-blue',
            'h-8 w-12 rounded-lg px-1 text-xs' => ! $compact,
            'h-6 w-9 shrink-0 rounded-md px-0.5 text-[11px] leading-none' => $compact,
        ])
    />
    <span @class(['text-zinc-400 dark:text-zinc-500', 'pb-1 text-sm' => ! $compact, 'text-xs leading-none' => $compact])>:</span>
    <input
        type="number"
        min="0"
        max="59"
        x-model="minute"
        @change="updateTime()"
        placeholder="00"
        x-bind:disabled="disabled"
        @class([
            'border border-zinc-200 bg-zinc-50 text-center text-zinc-900 shadow-sm outline-none ring-0 focus:border-brand-blue focus:bg-white focus:ring-1 focus:ring-brand-blue disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50 dark:focus:border-brand-light-blue dark:focus:ring-brand-light-blue',
            'h-8 w-12 rounded-lg px-1 text-xs' => ! $compact,
            'h-6 w-9 shrink-0 rounded-md px-0.5 text-[11px] leading-none' => $compact,
        ])
    />
    <div
        @class([
            'inline-flex shrink-0 overflow-hidden rounded-full border border-zinc-200 bg-zinc-50 shadow-sm dark:border-zinc-700 dark:bg-zinc-900',
            'text-[11px]' => ! $compact,
            'text-[10px] leading-none' => $compact,
        ])
    >
        <button
            type="button"
            @class(['transition-colors', 'px-2 py-1' => ! $compact, 'px-1.5 py-0.5' => $compact])
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
            @class(['transition-colors', 'px-2 py-1' => ! $compact, 'px-1.5 py-0.5' => $compact])
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

@props([
    'buttonClass' => '',
])

<div
    class="relative"
    x-data="{
        open: false,
        toggle() {
            this.open = !this.open;
        },
        close() {
            this.open = false;
        },
    }"
    @click.outside="close()"
    @keydown.escape.window="close()"
>
    <!-- Button -->
    <button
        type="button"
        @click.stop="toggle()"
        class="inline-flex items-center justify-center gap-2 rounded-lg border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-900 shadow-sm transition-colors hover:bg-zinc-50 focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-50 dark:hover:bg-zinc-700 {{ $buttonClass }}"
    >
        {{ $slot }}
        <svg
            class="h-4 w-4 transition-transform"
            :class="{ 'rotate-180': open }"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
        >
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
        </svg>
    </button>

    <!-- Dropdown Menu -->
    @isset($menu)
        <div
            x-show="open"
            x-transition:enter="transition ease-out duration-100"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            @click.stop
            class="absolute z-50 mt-2 min-w-[200px] rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800"
            x-cloak
        >
            {{ $menu }}
        </div>
    @endisset
</div>

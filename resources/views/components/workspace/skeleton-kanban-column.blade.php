<div
    class="flex w-full flex-col overflow-visible rounded-xl border border-zinc-200/80 bg-linear-to-b from-brand-light-lavender/35 via-white to-background/95 shadow-[0_10px_28px_-12px_rgb(33_52_72/0.08)] ring-1 ring-zinc-200/35 dark:border-zinc-700/70 dark:from-zinc-900/45 dark:via-zinc-900/30 dark:to-zinc-950 dark:shadow-[0_12px_32px_-14px_rgb(0_0_0/0.35)] dark:ring-zinc-700/40"
>
    <div
        class="flex items-center justify-between gap-3 border-b border-brand-blue/10 bg-brand-light-lavender/50 px-3 py-2.5 sm:px-4 sm:py-3 dark:border-zinc-600/50 dark:bg-zinc-800/45"
    >
        <flux:skeleton class="h-5 w-36 rounded-md" animate="shimmer" />
        <flux:skeleton class="h-7 w-9 shrink-0 rounded-full" animate="shimmer" />
    </div>
    <div
        class="flex min-h-[140px] flex-1 flex-col gap-2.5 overflow-visible bg-white/35 p-2.5 sm:min-h-[160px] sm:gap-3 sm:p-3 dark:bg-zinc-950/25"
    >
        {{ $slot }}
    </div>
</div>

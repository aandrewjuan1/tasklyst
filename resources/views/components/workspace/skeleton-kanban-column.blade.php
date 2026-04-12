<div class="flex w-full flex-col rounded-xl border border-border/60 bg-muted/30 shadow-sm">
    <div class="flex items-center justify-between gap-2 border-b border-border/60 px-3 py-2">
        <flux:skeleton class="h-5 w-36 rounded-md" animate="shimmer" />
        <flux:skeleton class="h-5 w-10 shrink-0 rounded-full" animate="shimmer" />
    </div>
    <div
        class="flex min-h-[140px] flex-1 flex-col gap-2.5 overflow-visible p-2.5 sm:min-h-[160px] sm:gap-3 sm:p-3"
    >
        {{ $slot }}
    </div>
</div>

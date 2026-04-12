{{-- compact: kanban card (tighter padding, fewer pills). Default: list row. --}}
@props([
    'compact' => false,
])

<flux:skeleton.group
    animate="shimmer"
    @class([
        'lic-surface-zinc flex flex-col gap-2 rounded-xl',
        'px-2.5 py-1.5' => $compact,
        'px-3 py-2' => ! $compact,
    ])
>
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0 flex-1">
            @if ($compact)
                <flux:skeleton.line class="w-4/5" />
            @else
                <flux:skeleton.line class="w-4/5" size="lg" />
            @endif
        </div>
        <div class="flex shrink-0 items-center gap-2">
            @if ($compact)
                <flux:skeleton class="h-5 w-12 rounded-full" />
            @else
                <flux:skeleton class="h-6 w-14 rounded-full" />
                <flux:skeleton class="size-8 shrink-0 rounded" />
            @endif
        </div>
    </div>
    <div @class([
        'flex flex-wrap items-center pt-0.5',
        'gap-1.5' => $compact,
        'gap-2' => ! $compact,
    ])>
        @if ($compact)
            <flux:skeleton class="h-4 w-12 rounded-full" />
            <flux:skeleton class="h-4 w-10 rounded-full" />
        @else
            <flux:skeleton class="h-5 w-16 rounded-full" />
            <flux:skeleton class="h-5 w-20 rounded-full" />
            <flux:skeleton class="h-5 w-14 rounded-full" />
        @endif
    </div>
</flux:skeleton.group>

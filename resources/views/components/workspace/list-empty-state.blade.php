@props([
    'emptyDateLabel' => '',
    'hasActiveSearch' => false,
    'searchQueryDisplay' => null,
    'hasActiveFilters' => false,
    'activeFilterParts' => [],
])

<div
    data-workspace-list-empty
    class="relative mt-6 overflow-hidden rounded-xl border border-dashed border-brand-blue/18 bg-linear-to-r from-brand-light-lavender/25 via-muted/30 to-transparent shadow-sm ring-1 ring-brand-purple/8 dark:border-zinc-600/40 dark:from-zinc-900/35 dark:via-zinc-900/25 dark:to-transparent dark:ring-zinc-700/30"
    role="status"
>
    <div
        class="pointer-events-none absolute inset-0 overflow-hidden rounded-xl"
        aria-hidden="true"
    >
        <div class="absolute inset-0 bg-linear-to-br from-brand-blue/[0.04] via-transparent to-brand-purple/[0.03] dark:from-brand-blue/[0.03]"></div>
        <div class="absolute -right-6 -top-6 size-24 rounded-full bg-brand-blue/8 blur-2xl dark:bg-brand-blue/[0.06]"></div>
    </div>
    <div class="relative flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:gap-3.5">
        <div
            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand-light-lavender/55 text-brand-navy-blue/75 ring-1 ring-brand-blue/15 dark:bg-zinc-800/80 dark:text-brand-light-blue/85 dark:ring-brand-blue/20"
            aria-hidden="true"
        >
            <flux:icon name="calendar-days" class="size-4 opacity-90" />
        </div>
        <div class="min-w-0 flex-1 space-y-1.5 text-left">
            <p class="text-sm font-medium leading-snug text-foreground">
                {{ __('No tasks, projects, or events for :date', ['date' => $emptyDateLabel]) }}
            </p>
            @if ($hasActiveSearch && $searchQueryDisplay)
                <p class="text-xs leading-relaxed text-muted-foreground">
                    {{ __('No results for “:query”. Try a different search or clear the search.', ['query' => $searchQueryDisplay]) }}
                </p>
            @endif
            @if ($hasActiveFilters && $activeFilterParts !== [])
                <div
                    class="rounded-lg border border-border/40 bg-white/50 px-2.5 py-1.5 text-xs leading-relaxed text-muted-foreground ring-1 ring-border/25 dark:bg-zinc-900/40 dark:ring-zinc-700/35"
                >
                    <span class="font-medium text-foreground/85">{{ __('Active filters') }}:</span>
                    {{ implode(', ', $activeFilterParts) }}
                </div>
                <p class="text-xs leading-relaxed text-muted-foreground">
                    {{ __('Try adjusting filters or add a new task, project, or event for this day') }}
                </p>
            @elseif (! $hasActiveSearch)
                <p class="text-xs leading-relaxed text-muted-foreground">
                    {{ __('Add a task, project, or event for this day to get started') }}
                </p>
            @endif
        </div>
    </div>
</div>

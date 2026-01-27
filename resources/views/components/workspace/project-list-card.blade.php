@props([
    'project',
])

<div
    {{ $attributes->merge([
        'class' => 'flex flex-col gap-2 rounded-xl border border-border/60 bg-background/60 px-3 py-2 shadow-sm backdrop-blur',
    ]) }}
>
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <p class="truncate text-sm font-medium">
                {{ $project->name }}
            </p>
            @if($project->description)
                <p class="mt-0.5 line-clamp-2 text-xs text-foreground/70">
                    {{ $project->description }}
                </p>
            @endif
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-1.5 text-[11px]">
        @if($project->start_datetime || $project->end_datetime)
            <span class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-muted-foreground">
                <flux:icon name="calendar-days" class="size-3" />
                <span>
                    @if($project->start_datetime)
                        {{ $project->start_datetime->toDateString() }}
                    @endif
                    @if($project->end_datetime)
                        – {{ $project->end_datetime->toDateString() }}
                    @endif
                </span>
            </span>
        @endif

        <span class="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2 py-0.5 text-amber-500">
            <flux:icon name="list-bullet" class="size-3" />
            <span>
                {{ trans_choice(':count task|:count tasks', $project->tasks->count(), ['count' => $project->tasks->count()]) }}
            </span>
        </span>
    </div>
</div>


@props([
    'task',
])

<div
    {{ $attributes->merge([
        'class' => 'flex flex-col gap-2 rounded-xl border border-border/60 bg-background/60 px-3 py-2 shadow-sm backdrop-blur',
    ]) }}
>
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <p class="truncate text-sm font-medium">
                {{ $task->title }}
            </p>
            @if($task->description)
                <p class="mt-0.5 line-clamp-2 text-xs text-foreground/70">
                    {{ $task->description }}
                </p>
            @endif
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-1.5 text-[11px]">
        @if($task->status)
            <span
                class="inline-flex items-center gap-1 rounded-full bg-{{ $task->status->color() }}/10 px-2 py-0.5 text-{{ $task->status->color() }}"
            >
                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                <span class="capitalize">{{ str_replace('_', ' ', $task->status->value) }}</span>
            </span>
        @endif

        @if($task->priority)
            <span
                class="inline-flex items-center gap-1 rounded-full bg-{{ $task->priority->color() }}/10 px-2 py-0.5 text-{{ $task->priority->color() }}"
            >
                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                <span class="capitalize">{{ $task->priority->value }}</span>
            </span>
        @endif

        @if($task->complexity)
            <span
                class="inline-flex items-center gap-1 rounded-full bg-{{ $task->complexity->color() }}/10 px-2 py-0.5 text-{{ $task->complexity->color() }}"
            >
                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                <span class="capitalize">{{ $task->complexity->value }}</span>
            </span>
        @endif

        @if($task->start_datetime || $task->end_datetime)
            <span class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-muted-foreground">
                <flux:icon name="clock" class="size-3" />
                <span>
                    @if($task->start_datetime)
                        {{ $task->start_datetime->format('H:i') }}
                    @endif
                    @if($task->end_datetime)
                        – {{ $task->end_datetime->format('H:i') }}
                    @endif
                </span>
            </span>
        @endif

        @if($task->project)
            <span class="inline-flex items-center gap-1 rounded-full bg-accent/10 px-2 py-0.5 text-accent-foreground/90">
                <flux:icon name="folder" class="size-3" />
                <span class="truncate max-w-[120px]">{{ $task->project->name }}</span>
            </span>
        @endif

        @if($task->event)
            <span class="inline-flex items-center gap-1 rounded-full bg-purple-500/10 px-2 py-0.5 text-purple-500">
                <flux:icon name="calendar" class="size-3" />
                <span class="truncate max-w-[120px]">{{ $task->event->title }}</span>
            </span>
        @endif
    </div>
</div>


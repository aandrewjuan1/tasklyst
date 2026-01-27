@props([
    'event',
])

<div
    {{ $attributes->merge([
        'class' => 'flex flex-col gap-2 rounded-xl border border-border/60 bg-background/60 px-3 py-2 shadow-sm backdrop-blur',
    ]) }}
>
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <p class="truncate text-sm font-medium">
                {{ $event->title }}
            </p>
            @if($event->description)
                <p class="mt-0.5 line-clamp-2 text-xs text-foreground/70">
                    {{ $event->description }}
                </p>
            @endif
        </div>
        <span class="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
            {{ __('Event') }}
        </span>
    </div>

    <div class="flex flex-wrap items-center gap-1.5 text-[11px]">
        @if($event->timezone)
            <span class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-muted-foreground">
                <flux:icon name="globe-alt" class="size-3" />
                <span class="truncate max-w-[120px]">
                    {{ $event->timezone }}
                </span>
            </span>
        @endif

        @if($event->status)
            <span
                class="inline-flex items-center gap-1 rounded-full bg-{{ $event->status->color() }}/10 px-2 py-0.5 text-{{ $event->status->color() }}"
            >
                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                <span class="capitalize">{{ $event->status->value }}</span>
            </span>
        @endif

        @if($event->all_day)
            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 text-emerald-500">
                <flux:icon name="sun" class="size-3" />
                <span>
                    {{ __('All day') }}
                    @if($event->start_datetime || $event->end_datetime)
                        ·
                        @if($event->start_datetime)
                            {{ $event->start_datetime->translatedFormat('M j, Y') }}
                        @endif
                        @if($event->end_datetime)
                            – {{ $event->end_datetime->translatedFormat('M j, Y') }}
                        @endif
                    @endif
                </span>
            </span>
        @elseif($event->start_datetime || $event->end_datetime)
            <span class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-muted-foreground">
                <flux:icon name="clock" class="size-3" />
                <span>
                    @if($event->start_datetime)
                        {{ $event->start_datetime->translatedFormat('M j, Y · g:i A') }}
                    @endif
                    @if($event->end_datetime)
                        – {{ $event->end_datetime->translatedFormat('M j, Y · g:i A') }}
                    @endif
                </span>
            </span>
        @endif

        @if($event->location)
            <span class="inline-flex items-center gap-1 rounded-full bg-sky-500/10 px-2 py-0.5 text-sky-500">
                <flux:icon name="map-pin" class="size-3" />
                <span class="truncate max-w-[120px]">{{ $event->location }}</span>
            </span>
        @endif

        @if($event->color)
            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs" style="color: {{ $event->color }};">
                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                <span>{{ __('Color') }}</span>
            </span>
        @endif

        @if($event->collaborators->isNotEmpty())
            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 text-emerald-500">
                <flux:icon name="users" class="size-3" />
                <span>
                    {{ trans_choice(':count collaborator|:count collaborators', $event->collaborators->count(), ['count' => $event->collaborators->count()]) }}
                </span>
            </span>
        @endif
    </div>
</div>


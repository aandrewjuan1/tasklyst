@props([
    'kind',
    'item',
])

@php
    $kind = strtolower((string) $kind);

    $title = match ($kind) {
        'project' => $item->name,
        'event' => $item->title,
        'task' => $item->title,
        default => '',
    };

    $description = match ($kind) {
        'project' => $item->description,
        'event' => $item->description,
        'task' => $item->description,
        default => null,
    };

    $type = match ($kind) {
        'project' => __('Project'),
        'event' => __('Event'),
        'task' => __('Task'),
        default => null,
    };

    $deleteMethod = match ($kind) {
        'project' => 'deleteProject',
        'event' => 'deleteEvent',
        'task' => 'deleteTask',
        default => null,
    };
@endphp

<div
    {{ $attributes->merge([
        'class' => 'flex flex-col gap-2 rounded-xl border border-border/60 bg-background/60 px-3 py-2 shadow-sm backdrop-blur',
    ]) }}
    x-data="{
        deleting: false,
        deleteMethod: @js($deleteMethod),
        itemId: @js($item->id),
        async deleteItem() {
            if (this.deleting || !this.deleteMethod || this.itemId == null) return;
            this.deleting = true;
            try {
                const ok = await $wire.$parent.$call(this.deleteMethod, this.itemId);
                if (!ok) this.deleting = false;
            } catch (e) {
                this.deleting = false;
            }
        }
    }"
    x-show="!deleting"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
>
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <p class="truncate text-base font-semibold leading-tight">
                {{ $title }}
            </p>

            @if($description)
                <p class="mt-0.5 line-clamp-2 text-xs text-foreground/70">
                    {{ $description }}
                </p>
            @endif
        </div>

        @if($type)
            <div class="flex items-center gap-2">
                @if($type)
                    <span class="inline-flex items-center rounded-full border border-border/60 bg-muted px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                        {{ $type }}
                    </span>
                @endif

                @if($deleteMethod)
                    <flux:dropdown>
                        <flux:button size="xs" icon="ellipsis-horizontal" />

                        <flux:menu>
                            <flux:menu.separator />

                            <flux:menu.item
                                variant="danger"
                                icon="trash"
                                @click.throttle.250ms="deleteItem()"
                            >
                                Delete
                            </flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </div>
        @endif
    </div>

    <div class="flex flex-wrap items-center gap-2 pt-0.5 text-xs">
    @if($kind === 'project')
        @if($item->user)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-accent/10 px-2.5 py-0.5 font-medium text-accent-foreground/90 dark:border-white/10">
                <flux:icon name="user" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Owner') }}:
                    </span>
                    <span class="truncate max-w-[140px] uppercase">
                        {{ $item->user->name }}
                    </span>
                </span>
            </span>
        @endif

        @if($item->start_datetime)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                <flux:icon name="calendar-days" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Start') }}:
                    </span>
                    <span class="uppercase">
                        {{ $item->start_datetime->toDateString() }}
                    </span>
                </span>
            </span>
        @endif

        @if($item->end_datetime)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                <flux:icon name="calendar-days" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Due') }}:
                    </span>
                    <span class="uppercase">
                        {{ $item->end_datetime->toDateString() }}
                    </span>
                </span>
            </span>
        @endif

        <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-amber-500/10 px-2.5 py-0.5 font-medium text-amber-500 dark:border-white/10">
            <flux:icon name="list-bullet" class="size-3" />
            <span class="inline-flex items-baseline gap-1">
                <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                    {{ __('Tasks') }}:
                </span>
                <span>
                    {{ $item->tasks->count() }}
                </span>
            </span>
        </span>

        <x-workspace.collaborators-badge :count="$item->collaborators->count()" />

    @elseif($kind === 'event')
        @if($item->timezone)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                <flux:icon name="globe-alt" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Time zone') }}:
                    </span>
                    <span class="truncate max-w-[120px] uppercase">
                        {{ $item->timezone }}
                    </span>
                </span>
            </span>
        @endif

        @if($item->status)
            <span
                class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-{{ $item->status->color() }}/10 px-2.5 py-0.5 font-semibold text-{{ $item->status->color() }} dark:border-white/10"
            >
                <flux:icon name="check-circle" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Status') }}:
                    </span>
                    <span class="uppercase">{{ $item->status->value }}</span>
                </span>
            </span>
        @endif

        @if($item->all_day)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-emerald-500/10 px-2.5 py-0.5 font-medium text-emerald-500 dark:border-white/10">
                <flux:icon name="sun" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Time') }}:
                    </span>
                    <span class="uppercase">
                        {{ __('All day') }}
                    </span>
                </span>
            </span>
        @elseif($item->start_datetime || $item->end_datetime)
            @if($item->start_datetime)
                <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                    <flux:icon name="clock" class="size-3" />
                    <span class="inline-flex items-baseline gap-1">
                        <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                            {{ __('Start') }}:
                        </span>
                        <span class="uppercase">
                            {{ $item->start_datetime->translatedFormat('M j, Y · g:i A') }}
                        </span>
                    </span>
                </span>
            @endif

            @if($item->end_datetime)
                <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                    <flux:icon name="clock" class="size-3" />
                    <span class="inline-flex items-baseline gap-1">
                        <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                            {{ __('End') }}:
                        </span>
                        <span class="uppercase">
                            {{ $item->end_datetime->translatedFormat('M j, Y · g:i A') }}
                        </span>
                    </span>
                </span>
            @endif
        @endif

        @if($item->location)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-sky-500/10 px-2.5 py-0.5 font-medium text-sky-500 dark:border-white/10">
                <flux:icon name="map-pin" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Location') }}:
                    </span>
                    <span class="truncate max-w-[120px] uppercase">{{ $item->location }}</span>
                </span>
            </span>
        @endif

        @if($item->color)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 px-2.5 py-0.5 text-xs font-medium dark:border-white/10" style="color: {{ $item->color }};">
                <flux:icon name="paint-brush" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Color') }}:
                    </span>
                    <span class="truncate max-w-[120px] uppercase">
                        {{ $item->color }}
                    </span>
                </span>
            </span>
        @endif

        <x-workspace.collaborators-badge :count="$item->collaborators->count()" />
    @elseif($kind === 'task')
        @if($item->status)
            <span
                class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-{{ $item->status->color() }}/10 px-2.5 py-0.5 font-semibold text-{{ $item->status->color() }} dark:border-white/10"
            >
                <flux:icon name="check-circle" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Status') }}:
                    </span>
                    <span class="uppercase">{{ str_replace('_', ' ', $item->status->value) }}</span>
                </span>
            </span>
        @endif

        @if($item->priority)
            <span
                class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-{{ $item->priority->color() }}/10 px-2.5 py-0.5 font-semibold text-{{ $item->priority->color() }} dark:border-white/10"
            >
                <flux:icon name="bolt" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Priority') }}:
                    </span>
                    <span class="uppercase">{{ $item->priority->value }}</span>
                </span>
            </span>
        @endif

        @if($item->complexity)
            <span
                class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-{{ $item->complexity->color() }}/10 px-2.5 py-0.5 font-semibold text-{{ $item->complexity->color() }} dark:border-white/10"
            >
                <flux:icon name="squares-2x2" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Complexity') }}:
                    </span>
                    <span class="uppercase">{{ $item->complexity->value }}</span>
                </span>
            </span>
        @endif

        @if(! is_null($item->duration))
            @php
                $durationMinutes = $item->duration;
                $durationHours = (int) ceil($durationMinutes / 60);
                $durationRemainderMinutes = $durationMinutes % 60;
            @endphp
            <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                <flux:icon name="clock" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Duration') }}:
                    </span>
                    <span class="uppercase">
                        @if($durationMinutes < 59)
                            {{ $durationMinutes }} {{ __('min') }}
                        @else
                            {{ $durationHours }}
                            {{ \Illuminate\Support\Str::plural(__('hour'), $durationHours) }}
                            @if($durationRemainderMinutes)
                                {{ $durationRemainderMinutes }} {{ __('min') }}
                            @endif
                        @endif
                    </span>
                </span>
            </span>
        @endif

        @if($item->tags->isNotEmpty())
            <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-sky-500/10 px-2.5 py-0.5 font-medium text-sky-500 dark:border-white/10">
                <flux:icon name="tag" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Tags') }}:
                    </span>
                    <span class="truncate max-w-[140px] uppercase">
                        {{ $item->tags->sortBy('name')->pluck('name')->join(', ') }}
                    </span>
                </span>
            </span>
        @endif

        @if($item->start_datetime)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                <flux:icon name="clock" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Start') }}:
                    </span>
                    <span class="uppercase">
                        {{ $item->start_datetime->translatedFormat('M j, Y · g:i A') }}
                    </span>
                </span>
            </span>
        @endif

        @if($item->end_datetime)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-0.5 font-medium text-muted-foreground">
                <flux:icon name="clock" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Due') }}:
                    </span>
                    <span class="uppercase">
                        {{ $item->end_datetime->translatedFormat('M j, Y · g:i A') }}
                    </span>
                </span>
            </span>
        @endif

        @if($item->project)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-accent/10 px-2.5 py-0.5 font-medium text-accent-foreground/90 dark:border-white/10">
                <flux:icon name="folder" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Project') }}:
                    </span>
                    <span class="truncate max-w-[120px] uppercase">{{ $item->project->name }}</span>
                </span>
            </span>
        @endif

        @if($item->event)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-purple-500/10 px-2.5 py-0.5 font-medium text-purple-500 dark:border-white/10">
                <flux:icon name="calendar" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Event') }}:
                    </span>
                    <span class="truncate max-w-[120px] uppercase">{{ $item->event->title }}</span>
                </span>
            </span>
        @endif

        <x-workspace.collaborators-badge :count="$item->collaborators->count()" />

        @if($item->completed_at)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-emerald-500/10 px-2.5 py-0.5 font-medium text-emerald-700 dark:border-white/10">
                <flux:icon name="check-circle" class="size-3" />
                <span class="inline-flex items-baseline gap-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                        {{ __('Completed') }}:
                    </span>
                    <span class="opacity-80">
                        {{ $item->completed_at->format('Y-m-d') }}
                    </span>
                </span>
            </span>
        @endif
    @endif
    </div>
</div>

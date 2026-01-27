<section class="space-y-8">
    <div class="flex items-center justify-between">
        <div class="space-y-1">
            <flux:heading size="lg">
                {{ __('Workspace') }}
            </flux:heading>
            <flux:subheading>
                {{ __('Your tasks, projects, and events') }}
            </flux:subheading>
        </div>

        <div class="flex items-center gap-2">
            <flux:button
                variant="ghost"
                size="xs"
                icon="chevron-left"
                wire:click="goToPreviousDay"
                :loading="false"
            />

            <div class="flex flex-col items-center">
                @if(!\Illuminate\Support\Carbon::parse($this->selectedDate)->isToday())
                    <button
                        type="button"
                        wire:click="goToToday"
                        class="text-xs uppercase tracking-wide text-muted-foreground underline-offset-2 hover:underline"
                    >
                        {{ __('Today') }}
                    </button>
                @endif
                <span class="text-sm font-medium">
                    {{ \Illuminate\Support\Carbon::parse($this->selectedDate)->translatedFormat('D, M j, Y') }}
                </span>
            </div>

            <flux:button
                variant="ghost"
                size="xs"
                icon="chevron-right"
                wire:click="goToNextDay"
                :loading="false"
            />
        </div>
    </div>

    <div class="grid gap-6 md:grid-cols-3">
        {{-- Tasks for selected date --}}
        <div class="space-y-3">
            <flux:heading size="sm" class="flex items-center gap-2">
                <flux:icon name="check-circle" class="size-4" />
                <span>{{ __('Tasks') }}</span>
            </flux:heading>

            @if($this->tasks->isEmpty())
                <flux:text muted>{{ __('No tasks for this day yet.') }}</flux:text>
            @else
                <div class="space-y-2">
                    @foreach($this->tasks as $task)
                        <div
                            wire:key="task-{{ $task->id }}"
                            class="flex flex-col gap-2 rounded-xl border border-border/60 bg-background/60 px-3 py-2 shadow-sm backdrop-blur"
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
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Projects for selected date --}}
        <div class="space-y-3">
            <flux:heading size="sm" class="flex items-center gap-2">
                <flux:icon name="folder-open" class="size-4" />
                <span>{{ __('Projects') }}</span>
            </flux:heading>

            @if($this->projects->isEmpty())
                <flux:text muted>{{ __('No projects highlighted for this day.') }}</flux:text>
            @else
                <div class="space-y-2">
                    @foreach($this->projects as $project)
                        <div
                            wire:key="project-{{ $project->id }}"
                            class="flex flex-col gap-2 rounded-xl border border-border/60 bg-background/60 px-3 py-2 shadow-sm backdrop-blur"
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
                                    <span>{{ trans_choice(':count task|:count tasks', $project->tasks->count(), ['count' => $project->tasks->count()]) }}</span>
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Today\'s Events --}}
        <div class="space-y-3">
            <flux:heading size="sm" class="flex items-center gap-2">
                <flux:icon name="calendar-days" class="size-4" />
                <span>{{ __('Today\'s Events') }}</span>
            </flux:heading>

            @if($this->events->isEmpty())
                <flux:text muted>{{ __('No events scheduled for today.') }}</flux:text>
            @else
                <div class="space-y-2">
                    @foreach($this->events as $event)
                        <div
                            wire:key="event-{{ $event->id }}"
                            class="flex flex-col gap-2 rounded-xl border border-border/60 bg-background/60 px-3 py-2 shadow-sm backdrop-blur"
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
                            </div>

                            <div class="flex flex-wrap items-center gap-1.5 text-[11px]">
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
                                        <span>{{ __('All day') }}</span>
                                    </span>
                                @elseif($event->start_datetime || $event->end_datetime)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-muted-foreground">
                                        <flux:icon name="clock" class="size-3" />
                                        <span>
                                            @if($event->start_datetime)
                                                {{ $event->start_datetime->format('H:i') }}
                                            @endif
                                            @if($event->end_datetime)
                                                – {{ $event->end_datetime->format('H:i') }}
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
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</section>

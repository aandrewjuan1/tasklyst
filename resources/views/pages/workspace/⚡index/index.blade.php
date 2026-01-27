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
                        <x-workspace.task-list-card
                            :task="$task"
                            wire:key="task-{{ $task->id }}"
                        />
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
                        <x-workspace.project-list-card
                            :project="$project"
                            wire:key="project-{{ $project->id }}"
                        />
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
                        <x-workspace.event-list-card
                            :event="$event"
                            wire:key="event-{{ $event->id }}"
                        />
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</section>

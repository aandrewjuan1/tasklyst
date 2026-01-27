<section class="space-y-8">
    <div class="flex items-center justify-between">
        <div class="space-y-1">
            <flux:heading size="lg">
                {{ __('Workspace') }}
            </flux:heading>
            <flux:subheading>
                {{ __('Your tasks, projects, and events') }}
            </flux:subheading>

            <div class="flex items-center gap-2 mt-4">
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
    </div>

    <div class="space-y-6">
        <div class="space-y-3">
            <div class="space-y-2">
                @foreach($this->projects as $project)
                    <x-workspace.project-list-card
                        :project="$project"
                        wire:key="project-{{ $project->id }}"
                    />
                @endforeach
            </div>
            <div class="space-y-2">
                @foreach($this->events as $event)
                    <x-workspace.event-list-card
                        :event="$event"
                        wire:key="event-{{ $event->id }}"
                    />
                @endforeach
            </div>
            <div class="space-y-2">
                @foreach($this->tasks as $task)
                    <x-workspace.task-list-card
                        :task="$task"
                        wire:key="task-{{ $task->id }}"
                    />
                @endforeach
            </div>
    </div>
</section>

@placeholder
    <flux:skeleton.group animate="shimmer" class="flex items-center gap-4">
        <flux:skeleton class="size-10 rounded-full" />
        <div class="flex-1">
            <flux:skeleton.line />
            <flux:skeleton.line class="w-1/2" />
        </div>
    </flux:skeleton.group>
@endplaceholder

<div class="space-y-6">
    <div class="space-y-3">
        <div class="space-y-2">
            @foreach ($projects as $project)
                <x-workspace.project-list-card
                    :$project
                    wire:key="project-{{ $project->id }}"
                />
            @endforeach
        </div>

        <div class="space-y-2">
            @foreach ($events as $event)
                <x-workspace.event-list-card
                    :$event
                    wire:key="event-{{ $event->id }}"
                />
            @endforeach
        </div>

        <div class="space-y-2">
            @foreach ($tasks as $task)
                <x-workspace.task-list-card
                    :$task
                    wire:key="task-{{ $task->id }}"
                />
            @endforeach
        </div>
    </div>
</div>

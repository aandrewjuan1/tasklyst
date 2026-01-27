<section class="space-y-8">
    <div class="flex items-center justify-between">
        <div class="space-y-1">
            <flux:heading size="lg">
                {{ __('Workspace') }}
            </flux:heading>
            <flux:subheading>
                {{ __('Your tasks, projects, and events') }}
            </flux:subheading>

            <x-workspace.date-switcher :selected-date="$this->selectedDate" />
        </div>
    </div>

    <livewire:pages::workspace.list
        :key="'workspace-list-'.$this->selectedDate"
        :projects="$this->projects"
        :events="$this->events"
        :tasks="$this->tasks"
    />
</section>

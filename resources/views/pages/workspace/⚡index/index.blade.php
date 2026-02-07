<section class="space-y-6" x-data x-on:list-refresh-requested.window="$wire.incrementListRefresh()">
    <div class="flex items-center justify-between">
        <div class="space-y-2">
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
        :key="'workspace-list-'.$this->selectedDate.'-'.$this->listRefresh"
        :selected-date="$this->selectedDate"
        :projects="$this->projects"
        :events="$this->events"
        :tasks="$this->tasks"
        :overdue="$this->overdue"
        :tags="$this->tags"
    />
</section>

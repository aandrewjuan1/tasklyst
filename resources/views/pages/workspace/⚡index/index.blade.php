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

        <div x-data>
            <flux:button
                type="button"
                variant="primary"
                size="sm"
                x-on:click="$dispatch('open-create-modal')"
            >
                {{ __('Open Create') }}
            </flux:button>
        </div>
    </div>

    <livewire:pages::workspace.list
        :key="'workspace-list-'.$this->selectedDate"
        :projects="$this->projects"
        :events="$this->events"
        :tasks="$this->tasks"
    />

    <livewire:pages::workspace.create defer/>
</section>

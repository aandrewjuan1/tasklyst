<?php

use Livewire\Component;
use Illuminate\Support\Collection;

new class extends Component
{
    public ?string $selectedDate = null;

    public Collection $projects;

    public Collection $events;

    public Collection $tasks;

    public Collection $overdue;

    public Collection $tags;
};

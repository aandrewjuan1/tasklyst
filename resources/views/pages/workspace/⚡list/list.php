<?php

use Livewire\Component;
use Illuminate\Support\Collection;

new class extends Component
{
    public Collection $projects;

    public Collection $events;

    public Collection $tasks;

    public Collection $tags;
};

<?php

use Illuminate\Support\Collection;
use Livewire\Component;

new class extends Component
{
    public ?string $selectedDate = null;

    public Collection $projects;

    public Collection $events;

    public Collection $tasks;

    public Collection $overdue;

    public Collection $tags;

    /**
     * @var array<string, mixed>
     */
    public array $filters = [];
};

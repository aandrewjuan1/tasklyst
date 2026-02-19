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

    public bool $hasMoreItems = false;

    /**
     * Current in-progress focus session from parent (Index). Used for overlay and which card is focused.
     *
     * @var array{id: int, started_at: string, duration_seconds: int, type: string, task_id: int|null, sequence_number: int, payload?: array}|null
     */
    public ?array $activeFocusSession = null;

    /**
     * Pomodoro settings from parent (Index). Passed through to list-item-cards for Alpine state.
     *
     * @var array<string, mixed>|null
     */
    public ?array $pomodoroSettings = null;
};

<?php

use Illuminate\Support\Collection;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * Nested workspace kanban. Eloquent collections are not #[Reactive] (avoids reactive hash issues);
 * the parent Index remounts this component via wire:key when filters/date/context change.
 */
new class extends Component
{
    #[Reactive]
    public ?string $selectedDate = null;

    public Collection $projects;

    public Collection $events;

    public Collection $tasks;

    public Collection $overdue;

    public Collection $tags;

    public Collection $teachers;

    /**
     * @var Collection<int, array{kind: string, item: mixed, isOverdue: bool}>
     */
    public Collection $completedEntries;

    /**
     * @var array<string, mixed>
     */
    #[Reactive]
    public array $filters = [];

    /**
     * Current in-progress focus session from parent (Index).
     *
     * @var array{id: int, started_at: string, duration_seconds: int, type: string, task_id: int|null, sequence_number: int, payload?: array}|null
     */
    #[Reactive]
    public ?array $activeFocusSession = null;

    /**
     * Pomodoro settings from parent (Index).
     *
     * @var array<string, mixed>|null
     */
    #[Reactive]
    public ?array $pomodoroSettings = null;

    /**
     * @var array<int, array{key: string, label: string, items: array<int, array<string, mixed>>}>
     */
    #[Reactive]
    public array $scheduledFocusPlanGroups = [];

    #[Reactive]
    public int $scheduledFocusPlanTotalCount = 0;
};

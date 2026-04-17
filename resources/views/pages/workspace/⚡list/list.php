<?php

use Illuminate\Support\Collection;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * Nested workspace list. Eloquent collections are not #[Reactive] (avoids CannotMutateReactivePropException);
 * the parent Index remounts this component via wire:key when filters/date/context change.
 */
new class extends Component
{
    #[Reactive]
    public ?string $selectedDate = null;

    /**
     * Full ordered list rows (overdue strip + day items), built by the parent Index via WorkspaceListAggregator.
     *
     * @var Collection<int, array{kind: string, item: mixed, isOverdue: bool}>
     */
    public Collection $listEntries;

    /**
     * @var Collection<int, array{kind: string, item: mixed, isOverdue: bool}>
     */
    public Collection $completedEntries;

    public Collection $projects;

    public Collection $tags;

    #[Reactive]
    public int $itemsPage = 1;

    #[Reactive]
    public int $itemsPerPage = 10;

    /**
     * @var array<string, mixed>
     */
    #[Reactive]
    public array $filters = [];

    #[Reactive]
    public bool $hasMoreItems = false;

    /**
     * Current in-progress focus session from parent (Index). Used for overlay and which card is focused.
     *
     * @var array{id: int, started_at: string, duration_seconds: int, type: string, task_id: int|null, sequence_number: int, payload?: array}|null
     */
    #[Reactive]
    public ?array $activeFocusSession = null;

    /**
     * Pomodoro settings from parent (Index). Passed through to list-item-cards for Alpine state.
     *
     * @var array<string, mixed>|null
     */
    #[Reactive]
    public ?array $pomodoroSettings = null;

    /**
     * @var array{today: array<int, array<string, mixed>>, tomorrow: array<int, array<string, mixed>>, upcoming: array<int, array<string, mixed>>}
     */
    #[Reactive]
    public array $scheduledFocusPlanGroups = [
        'today' => [],
        'tomorrow' => [],
        'upcoming' => [],
    ];

    #[Reactive]
    public int $scheduledFocusPlanTotalCount = 0;
};

<?php

namespace App\Livewire\Concerns;

use App\Enums\EventStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Event;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

trait HandlesWorkspaceFiltering
{
    #[Url(as: 'status')]
    public ?string $filterTaskStatus = null;

    #[Url(as: 'priority')]
    public ?string $filterTaskPriority = null;

    #[Url(as: 'estatus')]
    public ?string $filterEventStatus = null;

    /**
     * Filter key to property name mapping. Add new filters here for centralization.
     *
     * @return array<string, string>
     */
    protected static function filterKeys(): array
    {
        return [
            'taskStatus' => 'filterTaskStatus',
            'taskPriority' => 'filterTaskPriority',
            'eventStatus' => 'filterEventStatus',
        ];
    }

    /**
     * Set a filter value. Triggers a re-render when filters change.
     */
    public function setFilter(string $key, mixed $value): void
    {
        $keys = static::filterKeys();

        if (! isset($keys[$key])) {
            return;
        }

        $property = $keys[$key];
        $this->{$property} = $value;
        $this->incrementListRefresh();
    }

    /**
     * Clear a single filter.
     */
    public function clearFilter(string $key): void
    {
        $keys = static::filterKeys();

        if (! isset($keys[$key])) {
            return;
        }

        $this->setFilter($key, null);
    }

    /**
     * Clear all filters.
     */
    public function clearAllFilters(): void
    {
        foreach (array_values(static::filterKeys()) as $property) {
            $this->{$property} = null;
        }
        $this->incrementListRefresh();
    }

    /**
     * Check if any filter is active.
     */
    public function hasActiveFilters(): bool
    {
        return $this->filterTaskStatus !== null
            || $this->filterTaskPriority !== null
            || $this->filterEventStatus !== null;
    }

    /**
     * Get current filter state for the frontend.
     *
     * @return array<string, mixed>
     */
    public function getFilters(): array
    {
        return [
            'taskStatus' => $this->filterTaskStatus,
            'taskPriority' => $this->filterTaskPriority,
            'eventStatus' => $this->filterEventStatus,
            'hasActiveFilters' => $this->hasActiveFilters(),
        ];
    }

    /**
     * Apply filters to the task query. Called by HandlesWorkspaceItems when this trait is used.
     */
    public function applyTaskFilters(Builder $query): void
    {
        if ($this->filterTaskPriority !== null && TaskPriority::tryFrom($this->filterTaskPriority) !== null) {
            $query->byPriority($this->filterTaskPriority);
        }
    }

    /**
     * Filter task collection by effective status (after effectiveStatusForDate is set).
     *
     * @param  Collection<int, Task>  $tasks
     * @return Collection<int, Task>
     */
    public function filterTaskCollection(Collection $tasks): Collection
    {
        if ($this->filterTaskStatus === null || TaskStatus::tryFrom($this->filterTaskStatus) === null) {
            return $tasks;
        }

        return $tasks->filter(function (Task $task): bool {
            $effective = $task->effectiveStatusForDate ?? $task->status;

            return $effective !== null && $effective->value === $this->filterTaskStatus;
        })->values();
    }

    /**
     * Apply filters to the event query.
     *
     * When no event status filter is set, excludes cancelled and completed by default
     * (user typically wants to see actionable items). When a filter is set, we do not
     * filter by status at query level: recurring events can have effectiveStatusForDate
     * that differs from DB status, so filtering must happen in filterEventCollection
     * after effective status is computed.
     */
    public function applyEventFilters(Builder $query): void
    {
        if ($this->filterEventStatus === null) {
            $query->notCancelled()->notCompleted();
        }
    }

    /**
     * Filter event collection by effective status (after effectiveStatusForDate is set).
     *
     * @param  Collection<int, Event>  $events
     * @return Collection<int, Event>
     */
    public function filterEventCollection(Collection $events): Collection
    {
        if ($this->filterEventStatus === null || EventStatus::tryFrom($this->filterEventStatus) === null) {
            return $events;
        }

        return $events->filter(function (Event $event): bool {
            $effective = $event->effectiveStatusForDate ?? $event->status;

            return $effective !== null && $effective->value === $this->filterEventStatus;
        })->values();
    }

    /**
     * Apply filters to the overdue task query.
     */
    public function applyOverdueTaskFilters(Builder $query): void
    {
        if ($this->filterTaskStatus !== null && TaskStatus::tryFrom($this->filterTaskStatus) !== null) {
            $query->byStatus($this->filterTaskStatus);
        }

        if ($this->filterTaskPriority !== null && TaskPriority::tryFrom($this->filterTaskPriority) !== null) {
            $query->byPriority($this->filterTaskPriority);
        }
    }

    /**
     * Apply filters to the overdue event query.
     */
    public function applyOverdueEventFilters(Builder $query): void
    {
        if ($this->filterEventStatus !== null && EventStatus::tryFrom($this->filterEventStatus) !== null) {
            $query->byStatus($this->filterEventStatus);
        }
    }
}

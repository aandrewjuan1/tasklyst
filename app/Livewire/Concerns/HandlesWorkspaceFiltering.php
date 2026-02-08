<?php

namespace App\Livewire\Concerns;

use App\Enums\EventStatus;
use App\Enums\TaskComplexity;
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

    #[Url(as: 'complexity')]
    public ?string $filterTaskComplexity = null;

    #[Url(as: 'estatus')]
    public ?string $filterEventStatus = null;

    #[Url(as: 'type')]
    public ?string $filterItemType = null;

    /** @var array<int>|null */
    public ?array $filterTagIds = null;

    #[Url(as: 'recurring')]
    public ?string $filterRecurring = null;

    /**
     * Filter key to property name mapping. Add new filters here for centralization.
     *
     * @return array<string, string>
     */
    protected static function filterKeys(): array
    {
        return [
            'itemType' => 'filterItemType',
            'taskStatus' => 'filterTaskStatus',
            'taskPriority' => 'filterTaskPriority',
            'taskComplexity' => 'filterTaskComplexity',
            'eventStatus' => 'filterEventStatus',
            'tagIds' => 'filterTagIds',
            'recurring' => 'filterRecurring',
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

        if ($key === 'itemType') {
            $allowed = ['all', 'tasks', 'events', 'projects'];
            if ($value === null || $value === '' || $value === 'all') {
                $value = null;
            } elseif (! in_array($value, $allowed, true)) {
                return;
            }
        }

        if ($key === 'recurring') {
            $allowed = ['all', 'recurring', 'oneTime'];
            if ($value === null || $value === '' || $value === 'all') {
                $value = null;
            } elseif (! in_array($value, $allowed, true)) {
                return;
            }
        }

        if ($key === 'tagIds') {
            if ($value === null || $value === '') {
                $value = null;
            } elseif (is_array($value)) {
                $value = array_values(array_unique(array_filter(array_map('intval', $value))));
                $value = $value === [] ? null : $value;
            } else {
                $value = null;
            }
        }

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
        return $this->filterItemType !== null
            || $this->filterTaskStatus !== null
            || $this->filterTaskPriority !== null
            || $this->filterTaskComplexity !== null
            || $this->filterEventStatus !== null
            || ($this->filterTagIds !== null && $this->filterTagIds !== [])
            || $this->filterRecurring !== null;
    }

    /**
     * Get current filter state for the frontend.
     *
     * @return array<string, mixed>
     */
    public function getFilters(): array
    {
        return [
            'itemType' => $this->filterItemType,
            'taskStatus' => $this->filterTaskStatus,
            'taskPriority' => $this->filterTaskPriority,
            'taskComplexity' => $this->filterTaskComplexity,
            'eventStatus' => $this->filterEventStatus,
            'tagIds' => $this->filterTagIds ?? [],
            'recurring' => $this->filterRecurring,
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

        if ($this->filterTaskComplexity !== null && TaskComplexity::tryFrom($this->filterTaskComplexity) !== null) {
            $query->byComplexity($this->filterTaskComplexity);
        }

        if ($this->filterTagIds !== null && $this->filterTagIds !== []) {
            $query->whereHas('tags', function (Builder $tagQuery): void {
                $tagQuery->whereIn('tags.id', $this->filterTagIds);
            });
        }

        if ($this->filterRecurring === 'recurring') {
            $query->whereHas('recurringTask');
        } elseif ($this->filterRecurring === 'oneTime') {
            $query->whereDoesntHave('recurringTask');
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

        if ($this->filterTagIds !== null && $this->filterTagIds !== []) {
            $query->whereHas('tags', function (Builder $tagQuery): void {
                $tagQuery->whereIn('tags.id', $this->filterTagIds);
            });
        }

        if ($this->filterRecurring === 'recurring') {
            $query->whereHas('recurringEvent');
        } elseif ($this->filterRecurring === 'oneTime') {
            $query->whereDoesntHave('recurringEvent');
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

        if ($this->filterTaskComplexity !== null && TaskComplexity::tryFrom($this->filterTaskComplexity) !== null) {
            $query->byComplexity($this->filterTaskComplexity);
        }

        if ($this->filterTagIds !== null && $this->filterTagIds !== []) {
            $query->whereHas('tags', function (Builder $tagQuery): void {
                $tagQuery->whereIn('tags.id', $this->filterTagIds);
            });
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

        if ($this->filterTagIds !== null && $this->filterTagIds !== []) {
            $query->whereHas('tags', function (Builder $tagQuery): void {
                $tagQuery->whereIn('tags.id', $this->filterTagIds);
            });
        }
    }
}

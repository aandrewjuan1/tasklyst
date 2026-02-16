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

trait HandlesFiltering
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

    /** @var string|null UI binding for Tags radio group ('' = All, '123' = tag id) */
    public ?string $filterTagId = null;

    #[Url(as: 'recurring')]
    public ?string $filterRecurring = null;

    /** @var bool Whether user manually set item type; skip auto-sync override when both event and task filters are set */
    protected bool $userManuallySetItemType = false;

    /**
     * Map of item type to filter keys that are specific to that type.
     * Used for auto-syncing item type when type-specific filters change.
     *
     * @return array<string, array<string>>
     */
    protected static function typeSpecificFilters(): array
    {
        return [
            'events' => ['eventStatus'],
            'tasks' => ['taskStatus', 'taskPriority', 'taskComplexity'],
        ];
    }

    /**
     * Sync filterTagId from filterTagIds for wire:model binding.
     * Call from component mount when using Tags filter.
     */
    public function syncFilterTagIdFromTagIds(): void
    {
        if ($this->filterTagIds !== null && count($this->filterTagIds) === 1) {
            $this->filterTagId = (string) $this->filterTagIds[0];
        } else {
            $this->filterTagId = '';
        }
    }

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
     * Sync item type based on type-specific filters.
     * - Only event filter → events
     * - Only task filter → tasks
     * - Both event and task filters → all (unless user manually overrode)
     * - No type-specific filters → leave item type unchanged
     */
    protected function syncItemTypeFromTypeSpecificFilters(): void
    {
        $hasEventFilter = $this->normalizeFilterValue($this->filterEventStatus) !== null;
        $hasTaskFilter = $this->normalizeFilterValue($this->filterTaskStatus) !== null
            || $this->normalizeFilterValue($this->filterTaskPriority) !== null
            || $this->normalizeFilterValue($this->filterTaskComplexity) !== null;

        if ($hasEventFilter && $hasTaskFilter) {
            if ($this->userManuallySetItemType) {
                return;
            }
            $newItemType = null;
        } elseif ($hasEventFilter) {
            $newItemType = 'events';
        } elseif ($hasTaskFilter) {
            $newItemType = 'tasks';
        } else {
            return;
        }

        $this->userManuallySetItemType = false;

        $this->filterItemType = $newItemType;
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
            $this->userManuallySetItemType = true;
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

        if ($key === 'tagIds') {
            $this->filterTagId = $value !== null && count($value) === 1 ? (string) $value[0] : null;
        }

        $typeSpecificKeys = array_merge(
            static::typeSpecificFilters()['events'] ?? [],
            static::typeSpecificFilters()['tasks'] ?? []
        );
        if (in_array($key, $typeSpecificKeys, true)) {
            $this->syncItemTypeFromTypeSpecificFilters();
        }

        $this->incrementListRefresh();
    }

    /**
     * Set tag filter by tag ID. Convenience method for wire:click to avoid array syntax in Blade.
     */
    public function setTagFilter(int $tagId): void
    {
        $this->setFilter('tagIds', [$tagId]);
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

        if ($key === 'tagIds') {
            $this->filterTagId = null;
        }

        $this->setFilter($key, null);
    }

    /**
     * Normalize empty string to null when wire:model selects "All" (value="").
     * Keeps URL params clean. Triggers list refresh when filters change.
     */
    public function updatedFilterItemType(?string $value): void
    {
        $this->userManuallySetItemType = true;
        if ($value === '') {
            $this->filterItemType = null;
        }
        $this->incrementListRefresh();
    }

    public function updatedFilterTaskStatus(?string $value): void
    {
        if ($value === '') {
            $this->filterTaskStatus = null;
        }
        $this->syncItemTypeFromTypeSpecificFilters();
        $this->incrementListRefresh();
    }

    public function updatedFilterTaskPriority(?string $value): void
    {
        if ($value === '') {
            $this->filterTaskPriority = null;
        }
        $this->syncItemTypeFromTypeSpecificFilters();
        $this->incrementListRefresh();
    }

    public function updatedFilterTaskComplexity(?string $value): void
    {
        if ($value === '') {
            $this->filterTaskComplexity = null;
        }
        $this->syncItemTypeFromTypeSpecificFilters();
        $this->incrementListRefresh();
    }

    public function updatedFilterEventStatus(?string $value): void
    {
        if ($value === '') {
            $this->filterEventStatus = null;
        }
        $this->syncItemTypeFromTypeSpecificFilters();
        $this->incrementListRefresh();
    }

    public function updatedFilterRecurring(?string $value): void
    {
        if ($value === '') {
            $this->filterRecurring = null;
        }
        $this->incrementListRefresh();
    }

    public function updatedFilterTagId(?string $value): void
    {
        if ($value === '' || $value === null) {
            $this->filterTagIds = null;
            $this->filterTagId = null;
        } else {
            $id = (int) $value;
            $this->filterTagIds = $id > 0 ? [$id] : null;
        }
        $this->incrementListRefresh();
    }

    /**
     * Clear all filters.
     */
    public function clearAllFilters(): void
    {
        $this->userManuallySetItemType = false;
        foreach (array_values(static::filterKeys()) as $property) {
            $this->{$property} = null;
        }
        $this->filterTagId = null;
        $this->incrementListRefresh();
    }

    /**
     * Normalize empty string to null for wire:model compatibility with "All" options.
     */
    protected function normalizeFilterValue(?string $value): ?string
    {
        return ($value === null || $value === '') ? null : $value;
    }

    /**
     * Check if any filter is active.
     */
    public function hasActiveFilters(): bool
    {
        return $this->normalizeFilterValue($this->filterItemType) !== null
            || $this->normalizeFilterValue($this->filterTaskStatus) !== null
            || $this->normalizeFilterValue($this->filterTaskPriority) !== null
            || $this->normalizeFilterValue($this->filterTaskComplexity) !== null
            || $this->normalizeFilterValue($this->filterEventStatus) !== null
            || ($this->filterTagIds !== null && $this->filterTagIds !== [])
            || $this->normalizeFilterValue($this->filterRecurring) !== null;
    }

    /**
     * Get current filter state for the frontend.
     * Uses normalized values (null for "All") for display.
     *
     * @return array<string, mixed>
     */
    public function getFilters(): array
    {
        return [
            'itemType' => $this->normalizeFilterValue($this->filterItemType),
            'taskStatus' => $this->normalizeFilterValue($this->filterTaskStatus),
            'taskPriority' => $this->normalizeFilterValue($this->filterTaskPriority),
            'taskComplexity' => $this->normalizeFilterValue($this->filterTaskComplexity),
            'eventStatus' => $this->normalizeFilterValue($this->filterEventStatus),
            'tagIds' => $this->filterTagIds ?? [],
            'recurring' => $this->normalizeFilterValue($this->filterRecurring),
            'hasActiveFilters' => $this->hasActiveFilters(),
        ];
    }

    /**
     * Apply filters to the task query. Called by HandlesWorkspaceItems when this trait is used.
     */
    public function applyTaskFilters(Builder $query): void
    {
        $priority = $this->normalizeFilterValue($this->filterTaskPriority);
        if ($priority !== null && TaskPriority::tryFrom($priority) !== null) {
            $query->byPriority($priority);
        }

        $complexity = $this->normalizeFilterValue($this->filterTaskComplexity);
        if ($complexity !== null && TaskComplexity::tryFrom($complexity) !== null) {
            $query->byComplexity($complexity);
        }

        if ($this->filterTagIds !== null && $this->filterTagIds !== []) {
            $query->whereHas('tags', function (Builder $tagQuery): void {
                $tagQuery->whereIn('tags.id', $this->filterTagIds);
            });
        }

        $recurring = $this->normalizeFilterValue($this->filterRecurring);
        if ($recurring === 'recurring') {
            $query->whereHas('recurringTask');
        } elseif ($recurring === 'oneTime') {
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
        $taskStatus = $this->normalizeFilterValue($this->filterTaskStatus);
        if ($taskStatus === null || TaskStatus::tryFrom($taskStatus) === null) {
            return $tasks;
        }

        return $tasks->filter(function (Task $task) use ($taskStatus): bool {
            $effective = $task->effectiveStatusForDate ?? $task->status;

            return $effective !== null && $effective->value === $taskStatus;
        })->values();
    }

    /**
     * Apply filters to the event query.
     *
     * When no event status filter is set, excludes only cancelled. Completed events
     * are shown by default. When a filter is set, we do not filter by status at query
     * level: recurring events can have effectiveStatusForDate that differs from DB
     * status, so filtering must happen in filterEventCollection after effective
     * status is computed.
     */
    public function applyEventFilters(Builder $query): void
    {
        if ($this->normalizeFilterValue($this->filterEventStatus) === null) {
            $query->notCancelled();
        }

        if ($this->filterTagIds !== null && $this->filterTagIds !== []) {
            $query->whereHas('tags', function (Builder $tagQuery): void {
                $tagQuery->whereIn('tags.id', $this->filterTagIds);
            });
        }

        $recurring = $this->normalizeFilterValue($this->filterRecurring);
        if ($recurring === 'recurring') {
            $query->whereHas('recurringEvent');
        } elseif ($recurring === 'oneTime') {
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
        $eventStatus = $this->normalizeFilterValue($this->filterEventStatus);
        if ($eventStatus === null || EventStatus::tryFrom($eventStatus) === null) {
            return $events;
        }

        return $events->filter(function (Event $event) use ($eventStatus): bool {
            $effective = $event->effectiveStatusForDate ?? $event->status;

            return $effective !== null && $effective->value === $eventStatus;
        })->values();
    }

    /**
     * Apply filters to the overdue task query.
     */
    public function applyOverdueTaskFilters(Builder $query): void
    {
        $taskStatus = $this->normalizeFilterValue($this->filterTaskStatus);
        if ($taskStatus !== null && TaskStatus::tryFrom($taskStatus) !== null) {
            $query->byStatus($taskStatus);
        }

        $priority = $this->normalizeFilterValue($this->filterTaskPriority);
        if ($priority !== null && TaskPriority::tryFrom($priority) !== null) {
            $query->byPriority($priority);
        }

        $complexity = $this->normalizeFilterValue($this->filterTaskComplexity);
        if ($complexity !== null && TaskComplexity::tryFrom($complexity) !== null) {
            $query->byComplexity($complexity);
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
        $eventStatus = $this->normalizeFilterValue($this->filterEventStatus);
        if ($eventStatus !== null && EventStatus::tryFrom($eventStatus) !== null) {
            $query->byStatus($eventStatus);
        }

        if ($this->filterTagIds !== null && $this->filterTagIds !== []) {
            $query->whereHas('tags', function (Builder $tagQuery): void {
                $tagQuery->whereIn('tags.id', $this->filterTagIds);
            });
        }
    }
}

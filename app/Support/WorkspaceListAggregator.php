<?php

namespace App\Support;

use App\Enums\TaskStatus;
use App\Models\Event;
use App\Models\Project;
use App\Models\SchoolClass;
use App\Models\Task;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Builds the unified workspace list: tasks in Doing status first, then overdue strip, then remaining day items,
 * deduped and ordered by calendar time within each segment.
 *
 * Day strip ordering: school classes first; tasks/events and projects that start or end on the list anchor day
 * follow by time; other projects (long-running / undated for that anchor) sort last.
 */
final class WorkspaceListAggregator
{
    /**
     * @param  Collection<int, array{kind: string, item: Model}>  $overdue
     * @param  Collection<int, Project>  $projects
     * @param  Collection<int, Event>  $events
     * @param  Collection<int, Task>  $tasks
     * @param  Collection<int, SchoolClass>  $schoolClasses
     * @param  string|null  $listAnchorDate  Workspace selected day (Y-m-d, app timezone). When null, project "tail" grouping is disabled.
     * @return Collection<int, array{kind: string, item: Model, isOverdue: bool}>
     */
    public static function mergeOrderAndDedupe(
        Collection $overdue,
        Collection $projects,
        Collection $events,
        Collection $tasks,
        Collection $schoolClasses,
        ?string $listAnchorDate = null,
    ): Collection {
        $overdueItems = $overdue->map(fn (array $entry): array => array_merge($entry, ['isOverdue' => true]));

        $dateItems = collect()
            ->merge($projects->map(fn (Project $item): array => [
                'kind' => 'project',
                'item' => $item,
                'isOverdue' => self::modelEndIsPast($item),
            ]))
            ->merge($events->map(fn (Event $item): array => [
                'kind' => 'event',
                'item' => $item,
                'isOverdue' => self::modelEndIsPast($item),
            ]))
            ->merge($tasks->map(fn (Task $item): array => [
                'kind' => 'task',
                'item' => $item,
                'isOverdue' => self::modelEndIsPast($item),
            ]))
            ->merge($schoolClasses->map(fn (SchoolClass $item): array => [
                'kind' => 'schoolClass',
                'item' => $item,
                'isOverdue' => self::modelEndIsPast($item),
            ]));

        $deduped = $overdueItems
            ->merge($dateItems)
            ->unique(static function (array $entry): string {
                return $entry['kind'].'-'.$entry['item']->id;
            })
            ->values();

        $overdueKeys = $overdue
            ->map(static function (array $entry): string {
                return $entry['kind'].'-'.$entry['item']->id;
            })
            ->flip();

        $overdueStrip = [];
        $dayStrip = [];

        foreach ($deduped as $entry) {
            $key = $entry['kind'].'-'.$entry['item']->id;
            if ($overdueKeys->has($key)) {
                $overdueStrip[] = $entry;
            } else {
                $dayStrip[] = $entry;
            }
        }

        usort($overdueStrip, [self::class, 'compareOverdueEntries']);
        usort($dayStrip, static function (array $a, array $b) use ($listAnchorDate): int {
            return self::compareDayEntries($a, $b, $listAnchorDate);
        });

        $combined = [...$overdueStrip, ...$dayStrip];

        $doingPinned = [];
        $rest = [];
        foreach ($combined as $entry) {
            if (self::entryIsDoingTask($entry)) {
                $doingPinned[] = $entry;
            } else {
                $rest[] = $entry;
            }
        }

        usort($doingPinned, static function (array $a, array $b) use ($listAnchorDate): int {
            return self::compareDoingPinnedEntries($a, $b, $listAnchorDate);
        });

        return collect([...$doingPinned, ...$rest])->values();
    }

    /**
     * @param  array{kind: string, item: Model, isOverdue: bool}  $entry
     */
    private static function entryIsDoingTask(array $entry): bool
    {
        if (($entry['kind'] ?? '') !== 'task') {
            return false;
        }

        $item = $entry['item'];
        if (! $item instanceof Task) {
            return false;
        }

        return $item->status === TaskStatus::Doing;
    }

    /**
     * Relative order for Doing tasks when pinned to the top of the unified list (overdue Doing before non-overdue Doing).
     *
     * @param  array{kind: string, item: Model, isOverdue: bool}  $a
     * @param  array{kind: string, item: Model, isOverdue: bool}  $b
     */
    private static function compareDoingPinnedEntries(array $a, array $b, ?string $listAnchorDate): int
    {
        $ra = $a['isOverdue'] ? 0 : 1;
        $rb = $b['isOverdue'] ? 0 : 1;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }

        if ($a['isOverdue']) {
            return self::compareOverdueEntries($a, $b);
        }

        return self::compareDayEntries($a, $b, $listAnchorDate);
    }

    private static function modelEndIsPast(Project|Event|Task|SchoolClass $model): bool
    {
        $end = self::effectiveEndForWorkspaceList($model);

        return $end !== null && $end->isPast();
    }

    private static function effectiveEndForWorkspaceList(Model $model): ?CarbonInterface
    {
        if ($model instanceof SchoolClass) {
            if ($model->relationLoaded('recurringSchoolClass') && $model->recurringSchoolClass?->end_datetime !== null) {
                return $model->recurringSchoolClass->end_datetime;
            }
        }

        if ($model instanceof Project || $model instanceof Event || $model instanceof Task || $model instanceof SchoolClass) {
            return $model->end_datetime;
        }

        return null;
    }

    /**
     * @param  array{kind: string, item: Model, isOverdue: bool}  $a
     * @param  array{kind: string, item: Model, isOverdue: bool}  $b
     */
    private static function compareOverdueEntries(array $a, array $b): int
    {
        $ra = self::overdueStripKindRank($a);
        $rb = self::overdueStripKindRank($b);
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }

        $ea = self::effectiveEndForWorkspaceList($a['item'])?->getTimestamp() ?? PHP_INT_MAX;
        $eb = self::effectiveEndForWorkspaceList($b['item'])?->getTimestamp() ?? PHP_INT_MAX;

        if ($ea !== $eb) {
            return $ea <=> $eb;
        }

        return $a['item']->id <=> $b['item']->id;
    }

    /**
     * @param  array{kind: string, item: Model, isOverdue: bool}  $entry
     */
    private static function overdueStripKindRank(array $entry): int
    {
        return $entry['kind'] === 'schoolClass' ? 0 : 1;
    }

    /**
     * @param  array{kind: string, item: Model, isOverdue: bool}  $a
     * @param  array{kind: string, item: Model, isOverdue: bool}  $b
     */
    private static function compareDayEntries(array $a, array $b, ?string $listAnchorDate): int
    {
        $sa = self::dayStripSortTier($a, $listAnchorDate);
        $sb = self::dayStripSortTier($b, $listAnchorDate);
        if ($sa !== $sb) {
            return $sa <=> $sb;
        }

        $ta = self::daySortTimestamp($a, $listAnchorDate);
        $tb = self::daySortTimestamp($b, $listAnchorDate);

        if ($ta !== $tb) {
            return $ta <=> $tb;
        }

        $pa = self::taskPriorityRank($a);
        $pb = self::taskPriorityRank($b);
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }

        return $a['item']->id <=> $b['item']->id;
    }

    /**
     * @param  array{kind: string, item: Model, isOverdue: bool}  $entry
     */
    private static function dayStripSortTier(array $entry, ?string $listAnchorDate): int
    {
        if ($entry['kind'] === 'schoolClass') {
            return 0;
        }

        if ($entry['kind'] === 'project' && $entry['item'] instanceof Project) {
            if ($listAnchorDate === null || $listAnchorDate === '') {
                return 1;
            }

            return self::projectStartOrEndOnAnchorDay($entry['item'], $listAnchorDate) ? 1 : 2;
        }

        return 1;
    }

    private static function projectStartOrEndOnAnchorDay(Project $project, string $anchorDateYmd): bool
    {
        $tz = (string) config('app.timezone', 'UTC');

        foreach ([$project->end_datetime, $project->start_datetime] as $dt) {
            if ($dt === null) {
                continue;
            }

            if ($dt->copy()->timezone($tz)->toDateString() === $anchorDateYmd) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{kind: string, item: Model, isOverdue: bool}  $entry
     */
    private static function daySortTimestamp(array $entry, ?string $listAnchorDate): int
    {
        $item = $entry['item'];

        return match ($entry['kind']) {
            'task' => self::taskDaySortTimestamp($item),
            'event' => self::eventDaySortTimestamp($item),
            'project' => self::projectDayStripSortTimestamp($entry, $listAnchorDate),
            'schoolClass' => self::schoolClassDaySortTimestamp($item),
            default => PHP_INT_MAX,
        };
    }

    /**
     * @param  array{kind: string, item: Model, isOverdue: bool}  $entry
     */
    private static function projectDayStripSortTimestamp(array $entry, ?string $listAnchorDate): int
    {
        $item = $entry['item'];
        if (! $item instanceof Project) {
            return PHP_INT_MAX;
        }

        if ($listAnchorDate !== null && $listAnchorDate !== '' && self::projectStartOrEndOnAnchorDay($item, $listAnchorDate)) {
            $tz = (string) config('app.timezone', 'UTC');
            $end = $item->end_datetime;
            if ($end !== null && $end->copy()->timezone($tz)->toDateString() === $listAnchorDate) {
                return $end->getTimestamp();
            }
            $start = $item->start_datetime;
            if ($start !== null && $start->copy()->timezone($tz)->toDateString() === $listAnchorDate) {
                return $start->getTimestamp();
            }
        }

        return self::projectDaySortTimestamp($item);
    }

    private static function taskDaySortTimestamp(Model $item): int
    {
        if (! $item instanceof Task) {
            return PHP_INT_MAX;
        }

        $dt = $item->start_datetime ?? $item->end_datetime;

        return $dt !== null ? $dt->getTimestamp() : PHP_INT_MAX;
    }

    private static function eventDaySortTimestamp(Model $item): int
    {
        if (! $item instanceof Event) {
            return PHP_INT_MAX;
        }

        $dt = $item->start_datetime ?? $item->end_datetime;

        return $dt !== null ? $dt->getTimestamp() : PHP_INT_MAX;
    }

    private static function projectDaySortTimestamp(Model $item): int
    {
        if (! $item instanceof Project) {
            return PHP_INT_MAX;
        }

        $dt = $item->start_datetime ?? $item->end_datetime;

        return $dt !== null ? $dt->getTimestamp() : PHP_INT_MAX;
    }

    private static function schoolClassDaySortTimestamp(Model $item): int
    {
        if (! $item instanceof SchoolClass) {
            return PHP_INT_MAX;
        }

        $dt = $item->start_datetime ?? $item->end_datetime;
        if ($dt === null && $item->start_time !== null) {
            $dt = now()->setTimeFromTimeString((string) $item->start_time);
        }

        return $dt !== null ? $dt->getTimestamp() : PHP_INT_MAX;
    }

    /**
     * @param  array{kind: string, item: Model, isOverdue: bool}  $entry
     */
    private static function taskPriorityRank(array $entry): int
    {
        if ($entry['kind'] !== 'task' || ! $entry['item'] instanceof Task) {
            return 99;
        }

        $priority = is_object($entry['item']->priority)
            ? $entry['item']->priority->value
            : ($entry['item']->priority ?? 'medium');

        return match ($priority) {
            'urgent' => 1,
            'high' => 2,
            'medium' => 3,
            'low' => 4,
            default => 5,
        };
    }
}

<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Project;
use App\Models\SchoolClass;
use App\Models\Task;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Builds the unified workspace list: overdue strip first, then day items, deduped and ordered by calendar time.
 */
final class WorkspaceListAggregator
{
    /**
     * @param  Collection<int, array{kind: string, item: Model}>  $overdue
     * @param  Collection<int, Project>  $projects
     * @param  Collection<int, Event>  $events
     * @param  Collection<int, Task>  $tasks
     * @param  Collection<int, SchoolClass>  $schoolClasses
     * @return Collection<int, array{kind: string, item: Model, isOverdue: bool}>
     */
    public static function mergeOrderAndDedupe(
        Collection $overdue,
        Collection $projects,
        Collection $events,
        Collection $tasks,
        Collection $schoolClasses,
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
        usort($dayStrip, [self::class, 'compareDayEntries']);

        return collect([...$overdueStrip, ...$dayStrip])->values();
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
        $ea = self::effectiveEndForWorkspaceList($a['item'])?->getTimestamp() ?? PHP_INT_MAX;
        $eb = self::effectiveEndForWorkspaceList($b['item'])?->getTimestamp() ?? PHP_INT_MAX;

        if ($ea !== $eb) {
            return $ea <=> $eb;
        }

        return $a['item']->id <=> $b['item']->id;
    }

    /**
     * @param  array{kind: string, item: Model, isOverdue: bool}  $a
     * @param  array{kind: string, item: Model, isOverdue: bool}  $b
     */
    private static function compareDayEntries(array $a, array $b): int
    {
        $ta = self::daySortTimestamp($a);
        $tb = self::daySortTimestamp($b);

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
    private static function daySortTimestamp(array $entry): int
    {
        $item = $entry['item'];

        return match ($entry['kind']) {
            'task' => self::taskDaySortTimestamp($item),
            'event' => self::eventDaySortTimestamp($item),
            'project' => self::projectDaySortTimestamp($item),
            'schoolClass' => self::schoolClassDaySortTimestamp($item),
            default => PHP_INT_MAX,
        };
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

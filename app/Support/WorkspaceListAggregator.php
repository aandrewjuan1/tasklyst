<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
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
     * @return Collection<int, array{kind: string, item: Model, isOverdue: bool}>
     */
    public static function mergeOrderAndDedupe(
        Collection $overdue,
        Collection $projects,
        Collection $events,
        Collection $tasks,
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

    private static function modelEndIsPast(Project|Event|Task $model): bool
    {
        $end = $model->end_datetime;

        return $end !== null && $end->isPast();
    }

    /**
     * @param  array{kind: string, item: Model, isOverdue: bool}  $a
     * @param  array{kind: string, item: Model, isOverdue: bool}  $b
     */
    private static function compareOverdueEntries(array $a, array $b): int
    {
        $ea = $a['item']->end_datetime?->getTimestamp() ?? PHP_INT_MAX;
        $eb = $b['item']->end_datetime?->getTimestamp() ?? PHP_INT_MAX;

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
}

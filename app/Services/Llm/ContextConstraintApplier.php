<?php

namespace App\Services\Llm;

use App\DataTransferObjects\Llm\LlmContextConstraints;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class ContextConstraintApplier
{
    public function applyTaskConstraints(Builder|QueryBuilder $query, ?LlmContextConstraints $constraints): void
    {
        if (! $constraints instanceof LlmContextConstraints) {
            return;
        }

        if ($constraints->subjectNames !== []) {
            $query->whereIn('subject_name', $constraints->subjectNames);
        }

        if ($constraints->taskStatuses !== []) {
            $query->whereIn('status', $constraints->taskStatuses);
        }

        if ($constraints->taskPriorities !== []) {
            $query->whereIn('priority', $constraints->taskPriorities);
        }

        if ($constraints->taskComplexities !== []) {
            $query->whereIn('complexity', $constraints->taskComplexities);
        }

        if ($constraints->taskHasDueDate !== null) {
            if ($constraints->taskHasDueDate) {
                $query->whereNotNull('end_datetime');
            } else {
                $query->whereNull('end_datetime');
            }
        }

        if ($constraints->taskHasStartDate !== null) {
            if ($constraints->taskHasStartDate) {
                $query->whereNotNull('start_datetime');
            } else {
                $query->whereNull('start_datetime');
            }
        }

        if ($constraints->taskRecurring !== null && $query instanceof Builder) {
            if ($constraints->taskRecurring) {
                $query->whereHas('recurringTask');
            } else {
                $query->whereDoesntHave('recurringTask');
            }
        }

        if ($constraints->hasTimeWindow()) {
            $query->where(function (Builder $q) use ($constraints): void {
                $q->whereBetween('end_datetime', [$constraints->windowStart, $constraints->windowEnd])
                    ->orWhereBetween('start_datetime', [$constraints->windowStart, $constraints->windowEnd]);

                if ($constraints->includeOverdueInWindow) {
                    $q->orWhere(function (Builder $overdueQuery) use ($constraints): void {
                        $overdueQuery
                            ->whereNotNull('end_datetime')
                            ->where('end_datetime', '<', $constraints->windowStart);
                    });
                }
            });
        }

        $this->applyRequiredTags($query, $constraints);
        $this->applyExcludedTags($query, $constraints);
    }

    public function applyEventConstraints(Builder|QueryBuilder $query, ?LlmContextConstraints $constraints): void
    {
        if (! $constraints instanceof LlmContextConstraints) {
            return;
        }

        if ($constraints->hasTimeWindow()) {
            $query->whereNotNull('start_datetime')
                ->whereBetween('start_datetime', [$constraints->windowStart, $constraints->windowEnd]);
        }

        $this->applyRequiredTags($query, $constraints);
        $this->applyExcludedTags($query, $constraints);
    }

    public function applyProjectConstraints(Builder|QueryBuilder $query, ?LlmContextConstraints $constraints): void
    {
        if (! $constraints instanceof LlmContextConstraints) {
            return;
        }

        if ($constraints->hasTimeWindow()) {
            $query->where(function (Builder $q) use ($constraints): void {
                $q->whereBetween('end_datetime', [$constraints->windowStart, $constraints->windowEnd])
                    ->orWhereBetween('start_datetime', [$constraints->windowStart, $constraints->windowEnd]);
            });
        }

        // Projects are not taggable in the current domain model. If the user asks for
        // required tags (e.g. "tagged as Exam"), exclude projects from this context.
        if ($constraints->requiredTagNames !== []) {
            $query->whereRaw('1 = 0');
        }
    }

    private function applyRequiredTags(Builder|QueryBuilder $query, LlmContextConstraints $constraints): void
    {
        if (! $query instanceof Builder || $constraints->requiredTagNames === []) {
            return;
        }

        $normalized = array_values(array_filter(array_map(
            static fn (mixed $tag): string => mb_strtolower(trim((string) $tag)),
            $constraints->requiredTagNames
        )));

        if ($normalized === []) {
            return;
        }

        $query->whereHas('tags', static function (Builder $tagQuery) use ($normalized): void {
            $tagQuery->where(static function (Builder $normalizedTagQuery) use ($normalized): void {
                foreach ($normalized as $name) {
                    $normalizedTagQuery->orWhereRaw('LOWER(name) = ?', [$name]);
                }
            });
        });
    }

    private function applyExcludedTags(Builder|QueryBuilder $query, LlmContextConstraints $constraints): void
    {
        if (! $query instanceof Builder || $constraints->excludedTagNames === []) {
            return;
        }

        $normalized = array_values(array_filter(array_map(
            static fn (mixed $tag): string => mb_strtolower(trim((string) $tag)),
            $constraints->excludedTagNames
        )));

        if ($normalized === []) {
            return;
        }

        $query->whereDoesntHave('tags', static function (Builder $tagQuery) use ($normalized): void {
            $tagQuery->where(static function (Builder $normalizedTagQuery) use ($normalized): void {
                foreach ($normalized as $name) {
                    $normalizedTagQuery->orWhereRaw('LOWER(name) = ?', [$name]);
                }
            });
        });
    }
}

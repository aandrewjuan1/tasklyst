<?php

namespace App\Services\Llm;

use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Enums\LlmOperationMode;

class LlmIntentAliasResolver
{
    /**
     * @param  array<int, LlmEntityType>  $entityTargets
     */
    public function resolve(LlmOperationMode $operationMode, LlmEntityType $entityScope, array $entityTargets = [], bool $adjustLike = false): LlmIntent
    {
        $targets = $this->normalizedTargets($entityScope, $entityTargets);

        return match ($operationMode) {
            LlmOperationMode::ResolveDependency => LlmIntent::ResolveDependency,
            LlmOperationMode::Create => $this->resolveCreateIntent($targets, $entityScope),
            LlmOperationMode::Update => $this->resolveUpdateIntent($targets, $entityScope),
            LlmOperationMode::Schedule => $this->resolveScheduleIntent($targets, $entityScope, $adjustLike),
            LlmOperationMode::Prioritize => $this->resolvePrioritizeIntent($targets, $entityScope),
            LlmOperationMode::ListFilterSearch => LlmIntent::ListFilterSearch,
            LlmOperationMode::General => LlmIntent::GeneralQuery,
        };
    }

    /**
     * @param  array<int, LlmEntityType>  $entityTargets
     * @return array<int, LlmEntityType>
     */
    private function normalizedTargets(LlmEntityType $entityScope, array $entityTargets): array
    {
        if ($entityScope !== LlmEntityType::Multiple) {
            return [$entityScope];
        }

        if ($entityTargets === []) {
            return [LlmEntityType::Task, LlmEntityType::Event, LlmEntityType::Project];
        }

        $normalized = [];
        foreach ($entityTargets as $target) {
            if (! $target instanceof LlmEntityType || $target === LlmEntityType::Multiple) {
                continue;
            }

            if (! in_array($target, $normalized, true)) {
                $normalized[] = $target;
            }
        }

        return $normalized === [] ? [LlmEntityType::Task, LlmEntityType::Event, LlmEntityType::Project] : $normalized;
    }

    /**
     * @param  array<int, LlmEntityType>  $targets
     */
    private function resolveCreateIntent(array $targets, LlmEntityType $scope): LlmIntent
    {
        if ($scope === LlmEntityType::Multiple) {
            return LlmIntent::GeneralQuery;
        }

        return match ($targets[0] ?? LlmEntityType::Task) {
            LlmEntityType::Event => LlmIntent::CreateEvent,
            LlmEntityType::Project => LlmIntent::CreateProject,
            default => LlmIntent::CreateTask,
        };
    }

    /**
     * @param  array<int, LlmEntityType>  $targets
     */
    private function resolveUpdateIntent(array $targets, LlmEntityType $scope): LlmIntent
    {
        if ($scope === LlmEntityType::Multiple) {
            return LlmIntent::GeneralQuery;
        }

        return match ($targets[0] ?? LlmEntityType::Task) {
            LlmEntityType::Event => LlmIntent::UpdateEventProperties,
            LlmEntityType::Project => LlmIntent::UpdateProjectProperties,
            default => LlmIntent::UpdateTaskProperties,
        };
    }

    /**
     * @param  array<int, LlmEntityType>  $targets
     */
    private function resolveScheduleIntent(array $targets, LlmEntityType $scope, bool $adjustLike): LlmIntent
    {
        if ($scope !== LlmEntityType::Multiple) {
            if ($adjustLike) {
                return match ($targets[0] ?? LlmEntityType::Task) {
                    LlmEntityType::Event => LlmIntent::AdjustEventTime,
                    LlmEntityType::Project => LlmIntent::AdjustProjectTimeline,
                    default => LlmIntent::AdjustTaskDeadline,
                };
            }

            return match ($targets[0] ?? LlmEntityType::Task) {
                LlmEntityType::Event => LlmIntent::ScheduleEvent,
                LlmEntityType::Project => LlmIntent::ScheduleProject,
                default => LlmIntent::ScheduleTask,
            };
        }

        // Product decision: multi-scheduling is task-only.
        return LlmIntent::ScheduleTasks;
    }

    /**
     * @param  array<int, LlmEntityType>  $targets
     */
    private function resolvePrioritizeIntent(array $targets, LlmEntityType $scope): LlmIntent
    {
        if ($scope !== LlmEntityType::Multiple) {
            return match ($targets[0] ?? LlmEntityType::Task) {
                LlmEntityType::Event => LlmIntent::PrioritizeEvents,
                LlmEntityType::Project => LlmIntent::PrioritizeProjects,
                default => LlmIntent::PrioritizeTasks,
            };
        }

        $hasTask = in_array(LlmEntityType::Task, $targets, true);
        $hasEvent = in_array(LlmEntityType::Event, $targets, true);
        $hasProject = in_array(LlmEntityType::Project, $targets, true);

        if ($hasTask && $hasEvent && $hasProject) {
            return LlmIntent::PrioritizeAll;
        }
        if ($hasTask && $hasEvent) {
            return LlmIntent::PrioritizeTasksAndEvents;
        }
        if ($hasTask && $hasProject) {
            return LlmIntent::PrioritizeTasksAndProjects;
        }
        if ($hasEvent && $hasProject) {
            return LlmIntent::PrioritizeEventsAndProjects;
        }

        if ($hasTask) {
            return LlmIntent::PrioritizeTasks;
        }
        if ($hasEvent) {
            return LlmIntent::PrioritizeEvents;
        }
        if ($hasProject) {
            return LlmIntent::PrioritizeProjects;
        }

        return LlmIntent::PrioritizeAll;
    }
}

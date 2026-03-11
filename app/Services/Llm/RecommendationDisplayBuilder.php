<?php

namespace App\Services\Llm;

use App\DataTransferObjects\Llm\EventCreateRecommendationDto;
use App\DataTransferObjects\Llm\EventScheduleRecommendationDto;
use App\DataTransferObjects\Llm\EventUpdatePropertiesRecommendationDto;
use App\DataTransferObjects\Llm\LlmInferenceResult;
use App\DataTransferObjects\Llm\ProjectCreateRecommendationDto;
use App\DataTransferObjects\Llm\ProjectScheduleRecommendationDto;
use App\DataTransferObjects\Llm\ProjectUpdatePropertiesRecommendationDto;
use App\DataTransferObjects\Llm\RecommendationDisplayDto;
use App\DataTransferObjects\Llm\TaskCreateRecommendationDto;
use App\DataTransferObjects\Llm\TaskScheduleRecommendationDto;
use App\DataTransferObjects\Llm\TaskUpdatePropertiesRecommendationDto;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use Carbon\Carbon;

/**
 * Builds validated RecommendationDisplayDto from Phase 5 inference result.
 * Uses the canonical structured output (derived from raw LLM output in RunLlmInferenceAction
 * with minimal transforms) as the single reference for display and appliable_changes.
 * Computes validation-based confidence (required fields, date parse, enums) for UI; do not use model self-reported confidence.
 */
class RecommendationDisplayBuilder
{
    private const PRIORITY_VALUES = ['low', 'medium', 'high', 'urgent'];

    public function build(LlmInferenceResult $result, LlmIntent $intent, LlmEntityType $entityType): RecommendationDisplayDto
    {
        /** @var array<string, mixed> Canonical structured output from LLM (raw with minimal transforms). */
        $structured = $result->structured;
        $validationScore = $this->computeValidationConfidence($structured, $intent, $entityType);

        $recommendedAction = (string) ($structured['recommended_action'] ?? '');
        $reasoning = (string) ($structured['reasoning'] ?? '');

        $actionForDisplay = $recommendedAction !== '' ? $recommendedAction : __('No specific action suggested.');
        $reasoningForDisplay = $reasoning !== '' ? $reasoning : __('The assistant could not provide detailed reasoning.');

        $listedItems = isset($structured['listed_items']) && is_array($structured['listed_items']) ? $structured['listed_items'] : null;

        if ($intent === LlmIntent::GeneralQuery && $listedItems !== null && $listedItems !== []) {
            $count = count($listedItems);
            $entityLabel = match ($entityType) {
                LlmEntityType::Event => $count === 1 ? __('event') : __('events'),
                LlmEntityType::Project => $count === 1 ? __('project') : __('projects'),
                default => $count === 1 ? __('task') : __('tasks'),
            };

            $actionForDisplay = __('You have :count :entity matching that request.', [
                'count' => $count,
                'entity' => $entityLabel,
            ]);
        }

        if (in_array($intent, [
            LlmIntent::ScheduleTask,
            LlmIntent::AdjustTaskDeadline,
            LlmIntent::ScheduleEvent,
            LlmIntent::AdjustEventTime,
            LlmIntent::ScheduleProject,
            LlmIntent::AdjustProjectTimeline,
        ], true)) {
            $structured = $this->ensureScheduleFromNarrativeWhenMissing($structured);
        }

        // If the model provided the target title/name in structured output but forgot to mention it in the
        // narrative recommended_action, inject it so the UI always shows which entity the suggestion targets.
        if ($this->isScheduleOrAdjustIntent($intent) && in_array($entityType, [LlmEntityType::Task, LlmEntityType::Event, LlmEntityType::Project], true)) {
            $targetLabel = $this->targetLabelFromStructured($structured, $entityType);
            if ($targetLabel !== '' && mb_stripos($actionForDisplay, $targetLabel) === false) {
                $actionForDisplay = $targetLabel.' — '.trim($actionForDisplay);
            }
        }

        $rankedLines = $this->formatRankedListForMessage($structured, $intent);
        $nextStepsLines = $this->formatNextStepsForMessage($structured);
        $message = $this->buildMessage($actionForDisplay, $reasoningForDisplay, $listedItems, $rankedLines, $nextStepsLines);
        $displayStructured = $this->sanitizeStructuredForDisplay($structured, $intent);
        $appliableChanges = $this->buildAppliableChanges($structured, $intent, $entityType);

        if ($entityType === LlmEntityType::Multiple && $appliableChanges !== [] && ($appliableChanges['entity_type'] ?? '') === 'task') {
            $firstTask = $structured['scheduled_tasks'][0] ?? null;
            if (is_array($firstTask)) {
                if (isset($firstTask['title']) && trim((string) $firstTask['title']) !== '') {
                    $displayStructured['target_task_title'] = trim((string) $firstTask['title']);
                }
                if (isset($firstTask['id']) && is_numeric($firstTask['id'])) {
                    $displayStructured['target_task_id'] = (int) $firstTask['id'];
                }
            }
        }

        // ScheduleTask/AdjustTaskDeadline: use id and title from LLM structured output.
        if (in_array($intent, [LlmIntent::ScheduleTask, LlmIntent::AdjustTaskDeadline], true)
            && $entityType === LlmEntityType::Task) {
            if (isset($structured['title']) && trim((string) $structured['title']) !== '') {
                $displayStructured['target_task_title'] = trim((string) $structured['title']);
            } elseif (isset($structured['target_task_title']) && trim((string) $structured['target_task_title']) !== '') {
                $displayStructured['target_task_title'] = trim((string) $structured['target_task_title']);
            }
            if (isset($structured['id']) && is_numeric($structured['id'])) {
                $displayStructured['target_task_id'] = (int) $structured['id'];
            } elseif (isset($structured['target_task_id']) && is_numeric($structured['target_task_id'])) {
                $displayStructured['target_task_id'] = (int) $structured['target_task_id'];
            }
        }

        // ScheduleEvent/AdjustEventTime: use id and title from LLM structured output.
        if (in_array($intent, [LlmIntent::ScheduleEvent, LlmIntent::AdjustEventTime], true)
            && $entityType === LlmEntityType::Event) {
            if (isset($structured['title']) && trim((string) $structured['title']) !== '') {
                $displayStructured['target_event_title'] = trim((string) $structured['title']);
            } elseif (isset($structured['target_event_title']) && trim((string) $structured['target_event_title']) !== '') {
                $displayStructured['target_event_title'] = trim((string) $structured['target_event_title']);
            }
            if (isset($structured['id']) && is_numeric($structured['id'])) {
                $displayStructured['target_event_id'] = (int) $structured['id'];
            } elseif (isset($structured['target_event_id']) && is_numeric($structured['target_event_id'])) {
                $displayStructured['target_event_id'] = (int) $structured['target_event_id'];
            }
        }

        // ScheduleProject/AdjustProjectTimeline: use id and name from LLM structured output.
        if (in_array($intent, [LlmIntent::ScheduleProject, LlmIntent::AdjustProjectTimeline], true)
            && $entityType === LlmEntityType::Project) {
            if (isset($structured['name']) && trim((string) $structured['name']) !== '') {
                $displayStructured['target_project_name'] = trim((string) $structured['name']);
            } elseif (isset($structured['target_project_name']) && trim((string) $structured['target_project_name']) !== '') {
                $displayStructured['target_project_name'] = trim((string) $structured['target_project_name']);
            } elseif (isset($structured['title']) && trim((string) $structured['title']) !== '') {
                $displayStructured['target_project_name'] = trim((string) $structured['title']);
            }
            if (isset($structured['id']) && is_numeric($structured['id'])) {
                $displayStructured['target_project_id'] = (int) $structured['id'];
            } elseif (isset($structured['target_project_id']) && is_numeric($structured['target_project_id'])) {
                $displayStructured['target_project_id'] = (int) $structured['target_project_id'];
            }
        }

        return new RecommendationDisplayDto(
            intent: $intent,
            entityType: $entityType,
            recommendedAction: $actionForDisplay,
            reasoning: $reasoningForDisplay,
            message: $message,
            validationConfidence: $validationScore,
            usedFallback: $result->usedFallback,
            fallbackReason: $result->fallbackReason,
            structured: $displayStructured,
            followupSuggestions: [],
            appliableChanges: $appliableChanges,
        );
    }

    private function isScheduleOrAdjustIntent(LlmIntent $intent): bool
    {
        return in_array($intent, [
            LlmIntent::ScheduleTask,
            LlmIntent::AdjustTaskDeadline,
            LlmIntent::ScheduleEvent,
            LlmIntent::AdjustEventTime,
            LlmIntent::ScheduleProject,
            LlmIntent::AdjustProjectTimeline,
        ], true);
    }

    private function targetLabelFromStructured(array $structured, LlmEntityType $entityType): string
    {
        return match ($entityType) {
            LlmEntityType::Task => trim((string) ($structured['title'] ?? $structured['target_task_title'] ?? '')),
            LlmEntityType::Event => trim((string) ($structured['title'] ?? $structured['target_event_title'] ?? '')),
            LlmEntityType::Project => trim((string) ($structured['name'] ?? $structured['target_project_name'] ?? $structured['title'] ?? '')),
            default => '',
        };
    }

    /**
     * Validation-based confidence: 0–1 from required fields, date parse, enum validity.
     */
    private function computeValidationConfidence(array $structured, LlmIntent $intent, LlmEntityType $entityType): float
    {
        $checks = 0;
        $passed = 0;

        $multiEntityPrioritizeIntents = [
            LlmIntent::PrioritizeTasksAndEvents,
            LlmIntent::PrioritizeTasksAndProjects,
            LlmIntent::PrioritizeEventsAndProjects,
            LlmIntent::PrioritizeAll,
        ];
        if (in_array($intent, $multiEntityPrioritizeIntents, true) && $entityType === LlmEntityType::Multiple) {
            $passed += isset($structured['recommended_action']) && is_string($structured['recommended_action']) && trim($structured['recommended_action']) !== '' ? 1 : 0;
            $checks++;
            $passed += isset($structured['reasoning']) && is_string($structured['reasoning']) && trim($structured['reasoning']) !== '' ? 1 : 0;
            $checks++;
            $hasTasks = isset($structured['ranked_tasks']) && is_array($structured['ranked_tasks']);
            $hasEvents = isset($structured['ranked_events']) && is_array($structured['ranked_events']);
            $hasProjects = isset($structured['ranked_projects']) && is_array($structured['ranked_projects']);
            $atLeastOne = match ($intent) {
                LlmIntent::PrioritizeTasksAndEvents => $hasTasks || $hasEvents,
                LlmIntent::PrioritizeTasksAndProjects => $hasTasks || $hasProjects,
                LlmIntent::PrioritizeEventsAndProjects => $hasEvents || $hasProjects,
                LlmIntent::PrioritizeAll => $hasTasks || $hasEvents || $hasProjects,
                default => false,
            };
            $passed += $atLeastOne ? 1 : 0;
            $checks++;
            if ($hasTasks && is_array($structured['ranked_tasks']) && count($structured['ranked_tasks']) > 0) {
                $passed += 1;
                $checks++;
            }
            if ($hasEvents && is_array($structured['ranked_events']) && count($structured['ranked_events']) > 0) {
                $passed += 1;
                $checks++;
            }
            if ($hasProjects && is_array($structured['ranked_projects']) && count($structured['ranked_projects']) > 0) {
                $passed += 1;
                $checks++;
            }
            $checks = max(1, $checks);

            return round($passed / $checks, 2);
        }

        $multiEntityScheduleIntents = [
            LlmIntent::ScheduleTasksAndEvents,
            LlmIntent::ScheduleTasksAndProjects,
            LlmIntent::ScheduleEventsAndProjects,
            LlmIntent::ScheduleAll,
        ];
        if (in_array($intent, $multiEntityScheduleIntents, true) && $entityType === LlmEntityType::Multiple) {
            $passed += isset($structured['recommended_action']) && is_string($structured['recommended_action']) && trim($structured['recommended_action']) !== '' ? 1 : 0;
            $checks++;
            $passed += isset($structured['reasoning']) && is_string($structured['reasoning']) && trim($structured['reasoning']) !== '' ? 1 : 0;
            $checks++;
            $hasTasks = isset($structured['scheduled_tasks']) && is_array($structured['scheduled_tasks']);
            $hasEvents = isset($structured['scheduled_events']) && is_array($structured['scheduled_events']);
            $hasProjects = isset($structured['scheduled_projects']) && is_array($structured['scheduled_projects']);
            $atLeastOne = match ($intent) {
                LlmIntent::ScheduleTasksAndEvents => $hasTasks || $hasEvents,
                LlmIntent::ScheduleTasksAndProjects => $hasTasks || $hasProjects,
                LlmIntent::ScheduleEventsAndProjects => $hasEvents || $hasProjects,
                LlmIntent::ScheduleAll => $hasTasks || $hasEvents || $hasProjects,
                default => false,
            };
            $passed += $atLeastOne ? 1 : 0;
            $checks++;
            if ($hasTasks && is_array($structured['scheduled_tasks']) && count($structured['scheduled_tasks']) > 0) {
                $passed += 1;
                $checks++;
            }
            if ($hasEvents && is_array($structured['scheduled_events']) && count($structured['scheduled_events']) > 0) {
                $passed += 1;
                $checks++;
            }
            if ($hasProjects && is_array($structured['scheduled_projects']) && count($structured['scheduled_projects']) > 0) {
                $passed += 1;
                $checks++;
            }
            $checks = max(1, $checks);

            return round($passed / $checks, 2);
        }

        $checks++;
        $passed += isset($structured['entity_type']) && (string) $structured['entity_type'] === $entityType->value ? 1 : 0;

        $checks++;
        $passed += isset($structured['recommended_action']) && is_string($structured['recommended_action']) && trim($structured['recommended_action']) !== '' ? 1 : 0;

        $checks++;
        $passed += isset($structured['reasoning']) && is_string($structured['reasoning']) && trim($structured['reasoning']) !== '' ? 1 : 0;

        if ($intent === LlmIntent::PrioritizeTasks || $intent === LlmIntent::PrioritizeEvents || $intent === LlmIntent::PrioritizeProjects) {
            $ranked = $structured['ranked_tasks'] ?? $structured['ranked_events'] ?? $structured['ranked_projects'] ?? null;
            $checks++;
            $passed += is_array($ranked) && count($ranked) > 0 ? 1 : 0;
        }

        if ($intent === LlmIntent::ResolveDependency) {
            $steps = $structured['next_steps'] ?? null;
            $checks++;
            $passed += is_array($steps) && count($steps) >= 2 ? 1 : 0;
        }

        if (in_array($intent, [LlmIntent::ScheduleTask, LlmIntent::AdjustTaskDeadline], true)) {
            if (! empty($structured['start_datetime'])) {
                $checks++;
                $passed += $this->parseDateTime($structured['start_datetime']) !== null ? 1 : 0;
            }
            if (isset($structured['duration']) && is_numeric($structured['duration']) && (int) $structured['duration'] > 0) {
                $checks++;
                $passed += 1;
            }
            if (isset($structured['priority']) && $structured['priority'] !== '') {
                $checks++;
                $passed += in_array(strtolower((string) $structured['priority']), self::PRIORITY_VALUES, true) ? 1 : 0;
            }
        }

        if (in_array($intent, [LlmIntent::CreateTask], true)) {
            if (! empty($structured['start_datetime'])) {
                $checks++;
                $passed += $this->parseDateTime($structured['start_datetime']) !== null ? 1 : 0;
            }
            if (! empty($structured['end_datetime'])) {
                $checks++;
                $passed += $this->parseDateTime($structured['end_datetime']) !== null ? 1 : 0;
            }
            if (isset($structured['priority']) && $structured['priority'] !== '') {
                $checks++;
                $passed += in_array(strtolower((string) $structured['priority']), self::PRIORITY_VALUES, true) ? 1 : 0;
            }
        }

        if (in_array($intent, [LlmIntent::ScheduleEvent, LlmIntent::AdjustEventTime, LlmIntent::CreateEvent], true)) {
            if (! empty($structured['start_datetime'])) {
                $checks++;
                $passed += $this->parseDateTime($structured['start_datetime']) !== null ? 1 : 0;
            }
            if (! empty($structured['end_datetime'])) {
                $checks++;
                $passed += $this->parseDateTime($structured['end_datetime']) !== null ? 1 : 0;
            }
        }

        if (in_array($intent, [LlmIntent::ScheduleProject, LlmIntent::AdjustProjectTimeline, LlmIntent::CreateProject], true)) {
            if (! empty($structured['start_datetime'])) {
                $checks++;
                $passed += $this->parseDateTime($structured['start_datetime']) !== null ? 1 : 0;
            }
            if (! empty($structured['end_datetime'])) {
                $checks++;
                $passed += $this->parseDateTime($structured['end_datetime']) !== null ? 1 : 0;
            }
        }

        $checks = max(1, $checks);

        return round($passed / $checks, 2);
    }

    /**
     * Build a machine-friendly description of properties that can be applied to a concrete entity.
     * For the initial slice, this is limited to schedule/adjust intents for single-entity modes.
     *
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    private function buildAppliableChanges(array $structured, LlmIntent $intent, LlmEntityType $entityType): array
    {
        if (! in_array($intent, [
            LlmIntent::ScheduleTask,
            LlmIntent::AdjustTaskDeadline,
            LlmIntent::CreateTask,
            LlmIntent::ScheduleEvent,
            LlmIntent::AdjustEventTime,
            LlmIntent::CreateEvent,
            LlmIntent::ScheduleProject,
            LlmIntent::AdjustProjectTimeline,
            LlmIntent::CreateProject,
            LlmIntent::UpdateTaskProperties,
            LlmIntent::UpdateEventProperties,
            LlmIntent::UpdateProjectProperties,
            LlmIntent::ScheduleTasksAndEvents,
            LlmIntent::ScheduleTasksAndProjects,
        ], true)) {
            return [];
        }

        if ($entityType === LlmEntityType::Multiple) {
            $singleTaskChanges = $this->buildAppliableChangesFromFirstScheduledTask($structured, $intent);
            if ($singleTaskChanges !== []) {
                return $singleTaskChanges;
            }

            return [];
        }

        if ($intent === LlmIntent::ScheduleTask || $intent === LlmIntent::AdjustTaskDeadline) {
            $dto = TaskScheduleRecommendationDto::fromStructured($structured);
            $properties = $dto !== null ? $dto->proposedProperties() : $this->schedulePropertiesFromStructured($structured);

            $result = [
                'entity_type' => 'task',
                'properties' => $properties,
            ];
            if (isset($structured['id']) && is_numeric($structured['id'])) {
                $result['target_task_id'] = (int) $structured['id'];
            }

            return $properties !== [] ? $result : [];
        }

        if ($intent === LlmIntent::CreateTask) {
            $dto = TaskCreateRecommendationDto::fromStructured($structured);
            if ($dto === null) {
                return [];
            }

            $properties = [];
            if ($dto->startDatetime !== null) {
                $properties['startDatetime'] = $dto->startDatetime->toIso8601String();
            }
            if ($dto->endDatetime !== null) {
                $properties['endDatetime'] = $dto->endDatetime->toIso8601String();
            }
            if ($dto->durationMinutes !== null) {
                $properties['duration'] = $dto->durationMinutes;
            }
            if ($dto->priority !== null) {
                $properties['priority'] = $dto->priority;
            }

            return $properties !== []
                ? [
                    'entity_type' => 'task',
                    'properties' => $properties,
                ]
                : [];
        }

        if ($intent === LlmIntent::ScheduleEvent || $intent === LlmIntent::AdjustEventTime) {
            $dto = EventScheduleRecommendationDto::fromStructured($structured);
            if ($dto === null) {
                return [];
            }

            $properties = $dto->proposedProperties();
            $result = [
                'entity_type' => 'event',
                'properties' => $properties,
            ];
            if (isset($structured['id']) && is_numeric($structured['id'])) {
                $result['target_event_id'] = (int) $structured['id'];
            }

            return $properties !== [] ? $result : [];
        }

        if ($intent === LlmIntent::CreateEvent) {
            $dto = EventCreateRecommendationDto::fromStructured($structured);
            if ($dto === null) {
                return [];
            }

            $properties = [];
            if ($dto->startDatetime !== null) {
                $properties['startDatetime'] = $dto->startDatetime->toIso8601String();
            }
            if ($dto->endDatetime !== null) {
                $properties['endDatetime'] = $dto->endDatetime->toIso8601String();
            }

            return $properties !== []
                ? [
                    'entity_type' => 'event',
                    'properties' => $properties,
                ]
                : [];
        }

        if ($intent === LlmIntent::ScheduleProject || $intent === LlmIntent::AdjustProjectTimeline) {
            $dto = ProjectScheduleRecommendationDto::fromStructured($structured);
            if ($dto === null) {
                return [];
            }

            $properties = $dto->proposedProperties();
            $result = [
                'entity_type' => 'project',
                'properties' => $properties,
            ];
            if (isset($structured['id']) && is_numeric($structured['id'])) {
                $result['target_project_id'] = (int) $structured['id'];
            }

            return $properties !== [] ? $result : [];
        }

        if ($intent === LlmIntent::CreateProject) {
            $dto = ProjectCreateRecommendationDto::fromStructured($structured);
            if ($dto === null) {
                return [];
            }

            $properties = [];
            if ($dto->startDatetime !== null) {
                $properties['startDatetime'] = $dto->startDatetime->toIso8601String();
            }
            if ($dto->endDatetime !== null) {
                $properties['endDatetime'] = $dto->endDatetime->toIso8601String();
            }

            return $properties !== []
                ? [
                    'entity_type' => 'project',
                    'properties' => $properties,
                ]
                : [];
        }

        if ($intent === LlmIntent::UpdateTaskProperties) {
            $dto = TaskUpdatePropertiesRecommendationDto::fromStructured($structured);
            if ($dto === null) {
                return [];
            }

            $properties = $dto->proposedProperties();

            return $properties !== []
                ? [
                    'entity_type' => 'task',
                    'properties' => $properties,
                ]
                : [];
        }

        if ($intent === LlmIntent::UpdateEventProperties) {
            $dto = EventUpdatePropertiesRecommendationDto::fromStructured($structured);
            if ($dto === null) {
                return [];
            }

            $properties = $dto->proposedProperties();

            return $properties !== []
                ? [
                    'entity_type' => 'event',
                    'properties' => $properties,
                ]
                : [];
        }

        if ($intent === LlmIntent::UpdateProjectProperties) {
            $dto = ProjectUpdatePropertiesRecommendationDto::fromStructured($structured);
            if ($dto === null) {
                return [];
            }

            $properties = $dto->proposedProperties();

            return $properties !== []
                ? [
                    'entity_type' => 'project',
                    'properties' => $properties,
                ]
                : [];
        }

        return [];
    }

    /**
     * When intent is ScheduleTasksAndEvents or ScheduleTasksAndProjects with entity Multiple,
     * build appliable_changes from the first scheduled task when at least one has valid time/duration.
     * Uses top-level start_datetime/duration when the first item does not specify them (shared schedule).
     *
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    private function buildAppliableChangesFromFirstScheduledTask(array $structured, LlmIntent $intent): array
    {
        if (! in_array($intent, [LlmIntent::ScheduleTasksAndEvents, LlmIntent::ScheduleTasksAndProjects], true)) {
            return [];
        }

        $scheduledTasks = $structured['scheduled_tasks'] ?? null;
        if (! is_array($scheduledTasks) || $scheduledTasks === []) {
            return [];
        }

        $item = $scheduledTasks[0];
        if (! is_array($item)) {
            return [];
        }

        $startRaw = $item['start_datetime'] ?? $structured['start_datetime'] ?? null;
        $duration = isset($item['duration']) && is_numeric($item['duration'])
            ? (int) $item['duration']
            : (isset($structured['duration']) && is_numeric($structured['duration']) ? (int) $structured['duration'] : null);
        $priorityRaw = $item['priority'] ?? $structured['priority'] ?? null;
        $priority = $priorityRaw !== null && $priorityRaw !== ''
            ? strtolower((string) $priorityRaw)
            : null;
        if ($priority !== null && ! in_array($priority, self::PRIORITY_VALUES, true)) {
            $priority = null;
        }

        $start = $startRaw !== null && $startRaw !== '' ? $this->parseDateTime($startRaw) : null;
        if ($start !== null && $start->lt(Carbon::now())) {
            return [];
        }

        if ($start === null && $duration === null && $priority === null) {
            return [];
        }

        $properties = [];
        if ($start !== null) {
            $properties['startDatetime'] = $start->toIso8601String();
        }
        if ($duration !== null && $duration > 0) {
            $properties['duration'] = $duration;
        }
        if ($priority !== null) {
            $properties['priority'] = $priority;
        }

        if ($properties === []) {
            return [];
        }

        $result = [
            'entity_type' => 'task',
            'properties' => $properties,
        ];
        if (isset($item['id']) && is_numeric($item['id'])) {
            $result['target_task_id'] = (int) $item['id'];
        }

        return $result;
    }

    /**
     * Format ranked_tasks, ranked_events, or ranked_projects into lines for the message body.
     *
     * @param  array<string, mixed>  $structured
     * @return array<int, string>
     */
    private function formatRankedListForMessage(array $structured, LlmIntent $intent): array
    {
        $scheduleIntents = [
            LlmIntent::ScheduleTasksAndEvents,
            LlmIntent::ScheduleTasksAndProjects,
            LlmIntent::ScheduleEventsAndProjects,
            LlmIntent::ScheduleAll,
        ];
        if (in_array($intent, $scheduleIntents, true)) {
            return $this->formatScheduledListForMessage($structured, $intent);
        }

        if ($intent === LlmIntent::PrioritizeTasksAndEvents) {
            $taskLines = $this->formatSingleRankedListForMessage($structured['ranked_tasks'] ?? null);
            $eventLines = $this->formatSingleRankedListForMessage($structured['ranked_events'] ?? null);
            $lines = [];
            if ($taskLines !== []) {
                $lines[] = __('Tasks');
                $lines = array_merge($lines, $taskLines);
            }
            if ($eventLines !== []) {
                $lines[] = __('Events');
                $lines = array_merge($lines, $eventLines);
            }

            return $lines;
        }

        if ($intent === LlmIntent::PrioritizeTasksAndProjects) {
            $taskLines = $this->formatSingleRankedListForMessage($structured['ranked_tasks'] ?? null);
            $projectLines = $this->formatSingleRankedListForMessage($structured['ranked_projects'] ?? null);
            $lines = [];
            if ($taskLines !== []) {
                $lines[] = __('Tasks');
                $lines = array_merge($lines, $taskLines);
            }
            if ($projectLines !== []) {
                $lines[] = __('Projects');
                $lines = array_merge($lines, $projectLines);
            }

            return $lines;
        }

        if ($intent === LlmIntent::PrioritizeEventsAndProjects) {
            $eventLines = $this->formatSingleRankedListForMessage($structured['ranked_events'] ?? null);
            $projectLines = $this->formatSingleRankedListForMessage($structured['ranked_projects'] ?? null);
            $lines = [];
            if ($eventLines !== []) {
                $lines[] = __('Events');
                $lines = array_merge($lines, $eventLines);
            }
            if ($projectLines !== []) {
                $lines[] = __('Projects');
                $lines = array_merge($lines, $projectLines);
            }

            return $lines;
        }

        if ($intent === LlmIntent::PrioritizeAll) {
            $taskLines = $this->formatSingleRankedListForMessage($structured['ranked_tasks'] ?? null);
            $eventLines = $this->formatSingleRankedListForMessage($structured['ranked_events'] ?? null);
            $projectLines = $this->formatSingleRankedListForMessage($structured['ranked_projects'] ?? null);
            $lines = [];
            if ($taskLines !== []) {
                $lines[] = __('Tasks');
                $lines = array_merge($lines, $taskLines);
            }
            if ($eventLines !== []) {
                $lines[] = __('Events');
                $lines = array_merge($lines, $eventLines);
            }
            if ($projectLines !== []) {
                $lines[] = __('Projects');
                $lines = array_merge($lines, $projectLines);
            }

            return $lines;
        }

        $ranked = $structured['ranked_tasks'] ?? $structured['ranked_events'] ?? $structured['ranked_projects'] ?? null;
        if (! is_array($ranked) || $ranked === []) {
            return [];
        }

        return $this->formatSingleRankedListForMessage($ranked);
    }

    /**
     * Format scheduled_tasks, scheduled_events, scheduled_projects into lines (e.g. "Task: X — Fri 2pm → 4pm").
     *
     * @param  array<string, mixed>  $structured
     * @return array<int, string>
     */
    private function formatScheduledListForMessage(array $structured, LlmIntent $intent): array
    {
        $lines = [];
        if (in_array($intent, [LlmIntent::ScheduleTasksAndEvents, LlmIntent::ScheduleTasksAndProjects, LlmIntent::ScheduleAll], true)) {
            $taskLines = $this->formatSingleScheduledListForMessage($structured['scheduled_tasks'] ?? null);
            if ($taskLines !== []) {
                $lines[] = __('Tasks');
                $lines = array_merge($lines, $taskLines);
            }
        }
        if (in_array($intent, [LlmIntent::ScheduleTasksAndEvents, LlmIntent::ScheduleEventsAndProjects, LlmIntent::ScheduleAll], true)) {
            $eventLines = $this->formatSingleScheduledListForMessage($structured['scheduled_events'] ?? null);
            if ($eventLines !== []) {
                $lines[] = __('Events');
                $lines = array_merge($lines, $eventLines);
            }
        }
        if (in_array($intent, [LlmIntent::ScheduleTasksAndProjects, LlmIntent::ScheduleEventsAndProjects, LlmIntent::ScheduleAll], true)) {
            $projectLines = $this->formatSingleScheduledListForMessage($structured['scheduled_projects'] ?? null, 'name');
            if ($projectLines !== []) {
                $lines[] = __('Projects');
                $lines = array_merge($lines, $projectLines);
            }
        }

        return $lines;
    }

    /**
     * Format a single scheduled array (title/name + start_datetime, end_datetime) into lines.
     *
     * @param  array<int, array<string, mixed>>|null  $items
     * @return array<int, string>
     */
    private function formatSingleScheduledListForMessage(?array $items, string $titleKey = 'title'): array
    {
        if (! is_array($items) || $items === []) {
            return [];
        }

        $lines = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $label = isset($item[$titleKey]) && is_string($item[$titleKey]) ? trim($item[$titleKey]) : '';
            if ($label === '') {
                continue;
            }
            $startRaw = isset($item['start_datetime']) && is_string($item['start_datetime']) ? trim($item['start_datetime']) : null;
            $endRaw = isset($item['end_datetime']) && is_string($item['end_datetime']) ? trim($item['end_datetime']) : null;
            $duration = isset($item['duration']) && is_numeric($item['duration']) ? (int) $item['duration'] : null;
            $timePart = '';
            if ($startRaw !== null && $startRaw !== '') {
                try {
                    $start = Carbon::parse($startRaw)->setTimezone(config('app.timezone'));
                    $timePart = $start->format('D g:i a');
                    if ($endRaw !== null && $endRaw !== '') {
                        try {
                            $end = Carbon::parse($endRaw)->setTimezone(config('app.timezone'));
                            $timePart .= ' → '.$end->format('g:i a');
                        } catch (\Throwable) {
                            $timePart .= ' → '.$endRaw;
                        }
                    } elseif ($duration !== null && $duration > 0) {
                        $timePart .= ' '.__('for :min min', ['min' => $duration]);
                    }
                } catch (\Throwable) {
                    $timePart = $startRaw.($endRaw !== null && $endRaw !== '' ? ' → '.$endRaw : '');
                }
            } elseif ($endRaw !== null && $endRaw !== '') {
                try {
                    $end = Carbon::parse($endRaw)->setTimezone(config('app.timezone'));
                    $timePart = $end->format('D g:i a');
                } catch (\Throwable) {
                    $timePart = $endRaw;
                }
            }
            $lines[] = $timePart !== '' ? $label.' — '.$timePart : $label;
        }

        return $lines;
    }

    /**
     * Format a single ranked array (tasks, events, or projects) into lines.
     *
     * @param  array<int, array<string, mixed>>|null  $ranked
     * @return array<int, string>
     */
    private function formatSingleRankedListForMessage(?array $ranked): array
    {
        if (! is_array($ranked) || $ranked === []) {
            return [];
        }

        $lines = [];
        foreach ($ranked as $item) {
            if (! is_array($item)) {
                continue;
            }
            $rank = $item['rank'] ?? null;
            $title = isset($item['title']) && is_string($item['title']) ? trim($item['title']) : null;
            $name = isset($item['name']) && is_string($item['name']) ? trim($item['name']) : null;
            $label = $title ?? $name ?? '';
            if ($label === '') {
                continue;
            }
            $suffix = [];
            $dateRaw = null;
            if (isset($item['end_datetime']) && is_string($item['end_datetime']) && $item['end_datetime'] !== '') {
                $dateRaw = $item['end_datetime'];
            } elseif (isset($item['start_datetime']) && is_string($item['start_datetime']) && $item['start_datetime'] !== '') {
                $dateRaw = $item['start_datetime'];
            }

            if ($dateRaw !== null) {
                try {
                    $date = Carbon::parse($dateRaw)->setTimezone(config('app.timezone'));
                    $now = Carbon::now($date->getTimezone());
                    if ($date->lessThan($now)) {
                        $suffix[] = __('overdue since :date', ['date' => $date->toDayDateTimeString()]);
                    } else {
                        $suffix[] = __('due :date', ['date' => $date->toDayDateTimeString()]);
                    }
                } catch (\Throwable) {
                    $suffix[] = $dateRaw;
                }
            }
            $line = ($rank !== null ? '#'.$rank.' ' : '').$label;
            if ($suffix !== []) {
                $line .= ' ('.implode(', ', $suffix).')';
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Format next_steps (resolve_dependency) into numbered lines for the message body.
     *
     * @param  array<string, mixed>  $structured
     * @return array<int, string>
     */
    private function formatNextStepsForMessage(array $structured): array
    {
        $steps = $structured['next_steps'] ?? null;
        if (! is_array($steps) || $steps === []) {
            return [];
        }

        $lines = [];
        $i = 1;
        foreach ($steps as $step) {
            if (is_string($step) && trim($step) !== '') {
                $lines[] = $i.'. '.trim($step);
                $i++;
            }
        }

        return $lines;
    }

    /**
     * Build reply: optional summary, optional ranked list (prioritize intents), optional bullet list (listed_items), optional next_steps, optional reasoning.
     * Ensures the full LLM answer (including ranked_* and listed_items) is shown to the user.
     *
     * @param  array<int, array{title: string, priority?: string, end_datetime?: string}>|null  $listedItems
     * @param  array<int, string>  $rankedLines  Pre-formatted lines for ranked_tasks / ranked_events / ranked_projects
     * @param  array<int, string>  $nextStepsLines  Pre-formatted lines for next_steps (resolve_dependency)
     */
    private function buildMessage(string $recommendedAction, string $reasoning, ?array $listedItems, array $rankedLines = [], array $nextStepsLines = []): string
    {
        $action = trim($recommendedAction);
        $reason = trim($reasoning);

        if ($action === '' && $reason === '' && empty($listedItems) && $rankedLines === [] && $nextStepsLines === []) {
            return __('No specific action suggested. The assistant could not provide detailed reasoning.');
        }

        $parts = [];

        if ($action !== '') {
            $parts[] = $action;
        }

        if ($rankedLines !== []) {
            $parts[] = implode("\n", $rankedLines);
        }

        if (! empty($listedItems)) {
            $lines = [];
            foreach ($listedItems as $item) {
                $title = isset($item['title']) && is_string($item['title']) ? trim($item['title']) : '';
                if ($title === '') {
                    continue;
                }

                $fragments = [];

                if (! empty($item['priority']) && is_string($item['priority'])) {
                    $priority = strtolower(trim($item['priority']));
                    $priorityLabel = match ($priority) {
                        'low' => __('low priority'),
                        'medium' => __('medium priority'),
                        'high' => __('high priority'),
                        'urgent' => __('urgent priority'),
                        default => $priority,
                    };
                    $fragments[] = $priorityLabel;
                }

                if (! empty($item['end_datetime']) && is_string($item['end_datetime'])) {
                    try {
                        $date = Carbon::parse($item['end_datetime'])->setTimezone(config('app.timezone'));
                        $fragments[] = __('due :date', ['date' => $date->toDayDateTimeString()]);
                    } catch (\Throwable) {
                        $fragments[] = $item['end_datetime'];
                    }
                }

                $suffix = $fragments !== [] ? ' ('.implode(', ', $fragments).')' : '';
                $lines[] = '• '.$title.$suffix;
            }

            if ($lines !== []) {
                $parts[] = implode("\n", $lines);
            }
        }

        if ($nextStepsLines !== []) {
            $parts[] = implode("\n", $nextStepsLines);
        }

        if ($reason !== '') {
            $parts[] = $reason;
        }

        return implode("\n\n", $parts);
    }

    /**
     * Build schedule properties from structured when DTO is null (e.g. LLM put time only in text or in non-standard place).
     *
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    private function schedulePropertiesFromStructured(array $structured): array
    {
        $proposed = isset($structured['proposed_properties']) && is_array($structured['proposed_properties'])
            ? $structured['proposed_properties']
            : [];
        $source = array_merge($structured, $proposed);

        $properties = [];
        $startRaw = $source['start_datetime'] ?? $source['startDatetime'] ?? null;
        if (is_string($startRaw) && trim($startRaw) !== '') {
            $start = $this->parseDateTime(trim($startRaw));
            if ($start !== null) {
                $properties['startDatetime'] = $start->toIso8601String();
            }
        }
        if (isset($source['duration']) && is_numeric($source['duration']) && (int) $source['duration'] > 0) {
            $properties['duration'] = (int) $source['duration'];
        }
        if (isset($source['priority']) && is_string($source['priority'])) {
            $p = strtolower(trim($source['priority']));
            if (in_array($p, self::PRIORITY_VALUES, true)) {
                $properties['priority'] = $p;
            }
        }

        return $properties;
    }

    private function parseDateTime(mixed $value): ?Carbon
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        try {
            $parsed = Carbon::parse($value);

            return $parsed;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * When the LLM mentions a time in the narrative but omits start_datetime in JSON, infer from text
     * so the Proposed schedule section can show and we support the LLM instead of contradicting it.
     *
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    private function ensureScheduleFromNarrativeWhenMissing(array $structured): array
    {
        $startRaw = isset($structured['start_datetime']) && is_string($structured['start_datetime'])
            ? trim($structured['start_datetime'])
            : '';
        if ($startRaw !== '' && $this->parseDateTime($startRaw) !== null) {
            return $structured;
        }

        $text = trim((string) ($structured['recommended_action'] ?? '').' '.(string) ($structured['reasoning'] ?? ''));
        if ($text === '') {
            return $structured;
        }

        $inferred = $this->inferScheduleFromNarrative($text);
        if ($inferred === []) {
            return $structured;
        }

        return array_merge($structured, $inferred);
    }

    /**
     * Parse time (e.g. "11pm", "8pm", "20:00") and duration (e.g. "for 1 hour") from narrative text.
     *
     * @return array{start_datetime?: string, duration?: int}
     */
    private function inferScheduleFromNarrative(string $text): array
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $now = Carbon::now($timezone);

        $hour = null;
        $minute = 0;

        if (preg_match('/\b(\d{1,2})\s*:\s*(\d{2})\s*(?:\s*[ap]\.?m\.?)?\b/iu', $text, $m)) {
            $hour = (int) $m[1];
            $minute = (int) $m[2];
            if (preg_match('/\s*[p]\.?m\.?\b/iu', $m[0]) && $hour >= 1 && $hour <= 12) {
                $hour = $hour === 12 ? 12 : $hour + 12;
            } elseif (preg_match('/\s*[a]\.?m\.?\b/iu', $m[0]) && $hour === 12) {
                $hour = 0;
            }
        } elseif (preg_match('/\b(\d{1,2})\s*[ap]\.?m\.?\b/iu', $text, $m)) {
            $hour = (int) $m[1];
            $isPm = preg_match('/\b\d{1,2}\s*p\.?m\.?\b/iu', $text);
            $isAm = preg_match('/\b\d{1,2}\s*a\.?m\.?\b/iu', $text);
            if ($isPm && $hour >= 1 && $hour <= 12) {
                $hour = $hour === 12 ? 12 : $hour + 12;
            } elseif ($isAm && $hour === 12) {
                $hour = 0;
            } elseif (! $isAm && ! $isPm && $hour >= 1 && $hour <= 12 && $hour < 7) {
                $hour += 12;
            }
        } elseif (preg_match('/\b(2[0-3]|[01]?\d)\s*:\s*(\d{2})\b/', $text, $m)) {
            $hour = (int) $m[1];
            $minute = (int) $m[2];
        }

        if ($hour === null || $hour < 0 || $hour > 23) {
            return [];
        }

        $start = $now->copy()->setTime($hour, $minute, 0);
        if ($start->lte($now)) {
            $start = $start->addDay();
        }
        $startIso = $start->toIso8601String();

        $duration = null;
        if (preg_match('/\b(?:for\s+)?(\d+)\s*(?:hour|hr)s?\b/iu', $text, $m)) {
            $duration = (int) $m[1] * 60;
        } elseif (preg_match('/\b(?:for\s+)?(\d+)\s*(?:min(?:ute)?s?)\b/iu', $text, $m)) {
            $duration = (int) $m[1];
        }

        $out = ['start_datetime' => $startIso];
        if ($duration !== null && $duration > 0) {
            $out['duration'] = $duration;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizeStructuredForDisplay(array $structured, LlmIntent $intent): array
    {
        $out = [];

        $allowedKeys = [
            'ranked_tasks',
            'ranked_events',
            'ranked_projects',
            'scheduled_tasks',
            'scheduled_events',
            'scheduled_projects',
            'listed_items',
            'start_datetime',
            'end_datetime',
            'priority',
            'duration',
            'timezone',
            'location',
            'blockers',
            'next_steps',
            'proposed_properties',
            'target_task_title',
            'target_task_id',
            'id',
            'title',
        ];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $structured) && $structured[$key] !== null) {
                $out[$key] = $structured[$key];
            }
        }

        return $out;
    }
}

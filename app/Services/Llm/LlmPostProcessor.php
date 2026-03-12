<?php

namespace App\Services\Llm;

use App\DataTransferObjects\Llm\LlmInferenceResult;
use App\DataTransferObjects\Llm\LlmSystemPromptResult;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Models\User;
use App\Services\LlmInferenceService;

class LlmPostProcessor
{
    public function __construct(
        private StructuredOutputSanitizer $sanitizer,
        private ExplicitUserTimeParser $explicitUserTimeParser,
        private DeterministicScheduleTasksService $deterministicScheduleTasks,
        private LlmInferenceService $inferenceService,
        private LlmInteractionLogger $interactionLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function process(
        User $user,
        LlmIntent $intent,
        LlmEntityType $entityType,
        array $context,
        string $userMessage,
        string $userPrompt,
        LlmSystemPromptResult $promptResult,
        LlmInferenceResult $result,
        ?string $traceId = null
    ): array {
        $rawStructured = $result->structured;

        $structured = in_array($intent, [LlmIntent::ScheduleTask, LlmIntent::AdjustTaskDeadline], true)
            ? $this->taskScheduleStructuredFromRaw($rawStructured, $context)
            : $this->sanitizer->sanitize($rawStructured, $context, $intent, $entityType, $userMessage);

        if ($intent === LlmIntent::ScheduleTasks && $entityType === LlmEntityType::Multiple) {
            $structured = $this->retryScheduleTasksOnceWhenInvalid(
                user: $user,
                promptResult: $promptResult,
                userPrompt: $userPrompt,
                userMessage: $userMessage,
                context: $context,
                structured: $structured,
                traceId: $traceId,
            );

            $structured = $this->fallbackToDeterministicScheduleTasksWhenStillInvalid($structured, $context);
        }

        if ($intent === LlmIntent::PrioritizeTasks) {
            $structured = $this->ensurePrioritizeTasksUsesDeterministicRanking($structured, $context);
        }
        if ($intent === LlmIntent::PrioritizeEvents) {
            $structured = $this->ensurePrioritizeEventsUsesDeterministicRanking($structured, $context);
        }
        if ($intent === LlmIntent::PrioritizeProjects) {
            $structured = $this->ensurePrioritizeProjectsUsesDeterministicRanking($structured, $context);
        }
        if ($intent === LlmIntent::PrioritizeTasksAndEvents) {
            $structured = $this->ensurePrioritizeTasksAndEventsUsesDeterministicRanking($structured, $context);
        }
        if ($intent === LlmIntent::PrioritizeTasksAndProjects) {
            $structured = $this->ensurePrioritizeTasksAndProjectsUsesDeterministicRanking($structured, $context);
        }
        if ($intent === LlmIntent::PrioritizeEventsAndProjects) {
            $structured = $this->ensurePrioritizeEventsAndProjectsUsesDeterministicRanking($structured, $context);
        }
        if ($intent === LlmIntent::PrioritizeAll) {
            $structured = $this->ensurePrioritizeAllUsesDeterministicRanking($structured, $context);
        }

        if (in_array($intent, [
            LlmIntent::ScheduleTask,
            LlmIntent::AdjustTaskDeadline,
            LlmIntent::ScheduleEvent,
            LlmIntent::AdjustEventTime,
            LlmIntent::ScheduleProject,
            LlmIntent::AdjustProjectTimeline,
        ], true)) {
            $structured = $this->overrideStartFromExplicitUserTime($structured, $context, $userMessage);
        }

        return $structured;
    }

    /**
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function fallbackToDeterministicScheduleTasksWhenStillInvalid(array $structured, array $context): array
    {
        $contextTasks = $context['tasks'] ?? [];
        $hasManyContextTasks = is_array($contextTasks) && count($contextTasks) >= 2;
        $scheduled = $structured['scheduled_tasks'] ?? null;
        $scheduledCount = is_array($scheduled) ? count($scheduled) : 0;
        $targetCount = 2;
        if (isset($context['requested_schedule_n']) && is_numeric($context['requested_schedule_n'])) {
            $requested = (int) $context['requested_schedule_n'];
            if ($requested > 0 && is_array($contextTasks) && $contextTasks !== []) {
                $targetCount = min($requested, count($contextTasks));
            }
        } elseif (is_array($contextTasks) && $contextTasks !== [] && isset($context['previous_list_context']) && is_array($context['previous_list_context'])) {
            // For followups like “schedule those”, default to scheduling every
            // task in the previous list slice when no explicit requested count
            // was provided.
            $targetCount = count($contextTasks);
        }
        $hasWindow = isset($context['requested_window_start'], $context['requested_window_end'])
            && is_string($context['requested_window_start'])
            && is_string($context['requested_window_end'])
            && trim((string) $context['requested_window_start']) !== ''
            && trim((string) $context['requested_window_end']) !== '';

        if ($hasManyContextTasks && $scheduledCount < $targetCount && $hasWindow) {
            return $this->deterministicScheduleTasks->buildStructured($context);
        }

        return $structured;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    private function retryScheduleTasksOnceWhenInvalid(
        User $user,
        LlmSystemPromptResult $promptResult,
        string $userPrompt,
        string $userMessage,
        array $context,
        array $structured,
        ?string $traceId
    ): array {
        $contextTasks = $context['tasks'] ?? [];
        $hasManyContextTasks = is_array($contextTasks) && count($contextTasks) >= 2;
        $scheduled = $structured['scheduled_tasks'] ?? null;
        $scheduledCount = is_array($scheduled) ? count($scheduled) : 0;

        $targetCount = 2;
        if (isset($context['requested_schedule_n']) && is_numeric($context['requested_schedule_n'])) {
            $requested = (int) $context['requested_schedule_n'];
            if ($requested > 0 && is_array($contextTasks) && $contextTasks !== []) {
                $targetCount = min($requested, count($contextTasks));
            }
        } elseif (is_array($contextTasks) && $contextTasks !== [] && isset($context['previous_list_context']) && is_array($context['previous_list_context'])) {
            // For followups like “schedule those”, default to scheduling every
            // task in the previous list slice when no explicit requested count
            // was provided.
            $targetCount = count($contextTasks);
        }

        $shouldRetry = $hasManyContextTasks && $scheduledCount < $targetCount;
        if (! $shouldRetry) {
            return $structured;
        }

        $retryGuidance = "\n\nGuidance (critical retry):\n";
        $retryGuidance .= 'You MUST schedule multiple tasks and you MUST keep every scheduled_tasks[*].start_datetime within requested_window_start and requested_window_end from Context when those fields are present. ';
        $retryGuidance .= 'If Context.requested_schedule_n is present and > 0, you MUST return exactly requested_schedule_n scheduled_tasks items unless there are fewer tasks in Context.tasks, in which case schedule all of them. ';
        $retryGuidance .= 'If requested_schedule_n is not present and Context has 2+ tasks, return at least 2 scheduled_tasks items unless the cap/window makes it impossible. ';
        $retryGuidance .= 'Also respect focused_work_cap_minutes if present.';

        $retryResult = $this->inferenceService->infer(
            systemPrompt: $promptResult->systemPrompt,
            userPrompt: $userPrompt.$retryGuidance,
            intent: LlmIntent::ScheduleTasks,
            promptResult: $promptResult,
            user: $user,
        );

        $retryRaw = $retryResult->structured;
        $retryStructured = $this->sanitizer->sanitize($retryRaw, $context, LlmIntent::ScheduleTasks, LlmEntityType::Multiple, $userMessage);

        $this->interactionLogger->logInference(
            user: $user,
            intent: LlmIntent::ScheduleTasks,
            entityType: LlmEntityType::Multiple,
            promptResult: $promptResult,
            inferenceResult: new LlmInferenceResult(
                structured: $retryStructured,
                promptVersion: $retryResult->promptVersion,
                promptTokens: $retryResult->promptTokens,
                completionTokens: $retryResult->completionTokens,
                usedFallback: $retryResult->usedFallback,
                fallbackReason: $retryResult->fallbackReason,
                rawStructured: $retryResult->usedFallback ? null : $retryRaw,
                contextFacts: null
            ),
            context: $context,
            durationMs: 0,
            llmReachable: true,
            traceId: $traceId !== null ? $traceId.'-retry1' : null,
        );

        return $retryStructured;
    }

    /**
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function ensurePrioritizeAllUsesDeterministicRanking(array $structured, array $context): array
    {
        $tasksContext = $context['tasks'] ?? [];
        $eventsContext = $context['events'] ?? [];
        $projectsContext = $context['projects'] ?? [];

        if ((! is_array($tasksContext) || $tasksContext === [])
            && (! is_array($eventsContext) || $eventsContext === [])
            && (! is_array($projectsContext) || $projectsContext === [])
        ) {
            return $structured;
        }

        $structured['ranked_tasks'] = $this->buildDeterministicTaskRankingFromContext($tasksContext);
        $structured['ranked_events'] = $this->buildDeterministicEventRankingFromContext($eventsContext);
        $structured['ranked_projects'] = $this->buildDeterministicProjectRankingFromContext($projectsContext);

        if (! isset($structured['entity_type']) || ! is_string($structured['entity_type']) || trim($structured['entity_type']) === '') {
            $structured['entity_type'] = 'all';
        }

        return $structured;
    }

    /**
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function ensurePrioritizeEventsUsesDeterministicRanking(array $structured, array $context): array
    {
        $eventsContext = $context['events'] ?? [];
        if (! is_array($eventsContext) || $eventsContext === []) {
            return $structured;
        }

        $ranked = $this->buildDeterministicEventRankingFromContext($eventsContext);
        $requestedTopN = isset($context['requested_top_n']) && is_numeric($context['requested_top_n'])
            ? (int) $context['requested_top_n']
            : null;
        if ($requestedTopN !== null && $requestedTopN > 0) {
            $ranked = array_values(array_slice($ranked, 0, $requestedTopN));
        }

        $structured['ranked_events'] = $ranked;
        if (! isset($structured['entity_type']) || ! is_string($structured['entity_type']) || trim($structured['entity_type']) === '') {
            $structured['entity_type'] = 'event';
        }

        return $structured;
    }

    /**
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function ensurePrioritizeProjectsUsesDeterministicRanking(array $structured, array $context): array
    {
        $projectsContext = $context['projects'] ?? [];
        if (! is_array($projectsContext) || $projectsContext === []) {
            return $structured;
        }

        $ranked = $this->buildDeterministicProjectRankingFromContext($projectsContext);
        $requestedTopN = isset($context['requested_top_n']) && is_numeric($context['requested_top_n'])
            ? (int) $context['requested_top_n']
            : null;
        if ($requestedTopN !== null && $requestedTopN > 0) {
            $ranked = array_values(array_slice($ranked, 0, $requestedTopN));
        }

        $structured['ranked_projects'] = $ranked;
        if (! isset($structured['entity_type']) || ! is_string($structured['entity_type']) || trim($structured['entity_type']) === '') {
            $structured['entity_type'] = 'project';
        }

        return $structured;
    }

    /**
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function ensurePrioritizeTasksAndEventsUsesDeterministicRanking(array $structured, array $context): array
    {
        $tasksContext = $context['tasks'] ?? [];
        $eventsContext = $context['events'] ?? [];

        if (is_array($tasksContext)) {
            $structured['ranked_tasks'] = $this->buildDeterministicTaskRankingFromContext($tasksContext);
        }
        if (is_array($eventsContext)) {
            $structured['ranked_events'] = $this->buildDeterministicEventRankingFromContext($eventsContext);
        }
        if (! isset($structured['entity_type']) || ! is_string($structured['entity_type']) || trim($structured['entity_type']) === '') {
            $structured['entity_type'] = 'task,event';
        }

        return $structured;
    }

    /**
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function ensurePrioritizeTasksAndProjectsUsesDeterministicRanking(array $structured, array $context): array
    {
        $tasksContext = $context['tasks'] ?? [];
        $projectsContext = $context['projects'] ?? [];

        if (is_array($tasksContext)) {
            $structured['ranked_tasks'] = $this->buildDeterministicTaskRankingFromContext($tasksContext);
        }
        if (is_array($projectsContext)) {
            $structured['ranked_projects'] = $this->buildDeterministicProjectRankingFromContext($projectsContext);
        }
        if (! isset($structured['entity_type']) || ! is_string($structured['entity_type']) || trim($structured['entity_type']) === '') {
            $structured['entity_type'] = 'task,project';
        }

        return $structured;
    }

    /**
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function ensurePrioritizeEventsAndProjectsUsesDeterministicRanking(array $structured, array $context): array
    {
        $eventsContext = $context['events'] ?? [];
        $projectsContext = $context['projects'] ?? [];

        if (is_array($eventsContext)) {
            $structured['ranked_events'] = $this->buildDeterministicEventRankingFromContext($eventsContext);
        }
        if (is_array($projectsContext)) {
            $structured['ranked_projects'] = $this->buildDeterministicProjectRankingFromContext($projectsContext);
        }
        if (! isset($structured['entity_type']) || ! is_string($structured['entity_type']) || trim($structured['entity_type']) === '') {
            $structured['entity_type'] = 'event,project';
        }

        return $structured;
    }

    /**
     * Use deterministic ranking as source-of-truth for PrioritizeTasks whenever
     * filtered task context exists. Hermes still provides narrative and coaching
     * language, but ranked_tasks ordering is backend-owned for maximum stability.
     * Honors requested_top_n when present in context.
     *
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function ensurePrioritizeTasksUsesDeterministicRanking(array $structured, array $context): array
    {
        $tasksContext = $context['tasks'] ?? [];
        if (! is_array($tasksContext) || $tasksContext === []) {
            return $structured;
        }

        $ranked = $this->buildDeterministicTaskRankingFromContext($tasksContext);

        $requestedTopN = isset($context['requested_top_n']) && is_numeric($context['requested_top_n'])
            ? (int) $context['requested_top_n']
            : null;
        if ($requestedTopN !== null && $requestedTopN > 0) {
            $ranked = array_values(array_slice($ranked, 0, $requestedTopN));
        }

        $structured['ranked_tasks'] = $ranked;

        if (! isset($structured['entity_type']) || ! is_string($structured['entity_type']) || trim($structured['entity_type']) === '') {
            $structured['entity_type'] = 'task';
        }

        return $structured;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tasksContext
     * @return array<int, array<string, mixed>>
     */
    private function buildDeterministicTaskRankingFromContext(array $tasksContext): array
    {
        if ($tasksContext === []) {
            return [];
        }

        $items = [];
        foreach ($tasksContext as $task) {
            if (! is_array($task)) {
                continue;
            }

            $status = isset($task['status']) && is_string($task['status'])
                ? mb_strtolower(trim($task['status']))
                : null;

            if ($status === 'done') {
                continue;
            }

            $title = isset($task['title']) && is_string($task['title']) ? trim($task['title']) : '';
            if ($title === '') {
                continue;
            }

            $end = isset($task['end_datetime']) && is_string($task['end_datetime']) ? $task['end_datetime'] : null;
            $priority = isset($task['priority']) && is_string($task['priority'])
                ? mb_strtolower(trim($task['priority']))
                : null;

            $items[] = [
                'title' => $title,
                'end_datetime' => $end,
                'priority' => $priority,
            ];
        }

        if ($items === []) {
            return [];
        }

        usort($items, static function (array $a, array $b): int {
            $aEnd = $a['end_datetime'] ?? null;
            $bEnd = $b['end_datetime'] ?? null;

            if ($aEnd !== null && $bEnd !== null && $aEnd !== $bEnd) {
                return strcmp($aEnd, $bEnd);
            }

            $priorityWeight = static function (?string $priority): int {
                return match ($priority) {
                    'urgent' => 1,
                    'high' => 2,
                    'medium' => 3,
                    'low' => 4,
                    default => 5,
                };
            };

            $aWeight = $priorityWeight($a['priority'] ?? null);
            $bWeight = $priorityWeight($b['priority'] ?? null);

            if ($aWeight !== $bWeight) {
                return $aWeight <=> $bWeight;
            }

            return strcmp($a['title'], $b['title']);
        });

        $ranked = [];
        $rank = 1;
        foreach ($items as $item) {
            $ranked[] = [
                'rank' => $rank++,
                'title' => $item['title'],
                'end_datetime' => $item['end_datetime'] ?? null,
            ];
        }

        return $ranked;
    }

    /**
     * @param  array<int, array<string, mixed>>  $eventsContext
     * @return array<int, array<string, mixed>>
     */
    private function buildDeterministicEventRankingFromContext(array $eventsContext): array
    {
        if ($eventsContext === []) {
            return [];
        }

        $items = [];
        foreach ($eventsContext as $event) {
            if (! is_array($event)) {
                continue;
            }

            $title = isset($event['title']) && is_string($event['title']) ? trim($event['title']) : '';
            if ($title === '') {
                continue;
            }

            $start = isset($event['start_datetime']) && is_string($event['start_datetime']) ? $event['start_datetime'] : null;
            $end = isset($event['end_datetime']) && is_string($event['end_datetime']) ? $event['end_datetime'] : null;

            $items[] = [
                'title' => $title,
                'start_datetime' => $start,
                'end_datetime' => $end,
            ];
        }

        if ($items === []) {
            return [];
        }

        usort($items, static function (array $a, array $b): int {
            $aStart = $a['start_datetime'] ?? null;
            $bStart = $b['start_datetime'] ?? null;

            if ($aStart !== null && $bStart !== null && $aStart !== $bStart) {
                return strcmp($aStart, $bStart);
            }

            return strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
        });

        $ranked = [];
        $rank = 1;
        foreach ($items as $item) {
            $ranked[] = [
                'rank' => $rank++,
                'title' => $item['title'],
                'start_datetime' => $item['start_datetime'] ?? null,
                'end_datetime' => $item['end_datetime'] ?? null,
            ];
        }

        return $ranked;
    }

    /**
     * @param  array<int, array<string, mixed>>  $projectsContext
     * @return array<int, array<string, mixed>>
     */
    private function buildDeterministicProjectRankingFromContext(array $projectsContext): array
    {
        if ($projectsContext === []) {
            return [];
        }

        $items = [];
        foreach ($projectsContext as $project) {
            if (! is_array($project)) {
                continue;
            }

            $name = isset($project['name']) && is_string($project['name']) ? trim($project['name']) : '';
            if ($name === '') {
                continue;
            }

            $end = isset($project['end_datetime']) && is_string($project['end_datetime']) ? $project['end_datetime'] : null;

            $items[] = [
                'name' => $name,
                'end_datetime' => $end,
            ];
        }

        if ($items === []) {
            return [];
        }

        usort($items, static function (array $a, array $b): int {
            $aEnd = $a['end_datetime'] ?? null;
            $bEnd = $b['end_datetime'] ?? null;

            if ($aEnd !== null && $bEnd !== null && $aEnd !== $bEnd) {
                return strcmp($aEnd, $bEnd);
            }

            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        $ranked = [];
        $rank = 1;
        foreach ($items as $item) {
            $ranked[] = [
                'rank' => $rank++,
                'name' => $item['name'],
                'end_datetime' => $item['end_datetime'] ?? null,
            ];
        }

        return $ranked;
    }

    /**
     * @param  array<string, mixed>  $rawStructured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function taskScheduleStructuredFromRaw(array $rawStructured, array $context): array
    {
        $tasks = $context['tasks'] ?? [];
        if (! is_array($tasks) || $tasks === []) {
            return [
                'entity_type' => 'task',
                'recommended_action' => __('You have no tasks yet. Add tasks to your list to get scheduling suggestions.'),
                'reasoning' => __('I checked your tasks and there are none to schedule right now.'),
            ];
        }

        $structured = $rawStructured;
        unset($structured['end_datetime']);
        if (isset($structured['proposed_properties']) && is_array($structured['proposed_properties'])) {
            unset($structured['proposed_properties']['end_datetime']);
        }

        $structured = $this->ensureSensibleStartTimeForTaskSchedule($structured);

        return $structured;
    }

    /**
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function overrideStartFromExplicitUserTime(array $structured, array $context, ?string $userMessage): array
    {
        $candidate = $this->explicitUserTimeParser->parseStartDatetime(
            $userMessage ?? ($context['user_scheduling_request'] ?? null),
            $context
        );

        if ($candidate === null) {
            return $structured;
        }

        $structured['start_datetime'] = $candidate->toIso8601String();
        if (isset($structured['proposed_properties']) && is_array($structured['proposed_properties'])) {
            $structured['proposed_properties']['start_datetime'] = $candidate->toIso8601String();
        }

        return $structured;
    }

    /**
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    private function ensureSensibleStartTimeForTaskSchedule(array $structured): array
    {
        $startRaw = $structured['start_datetime'] ?? null;
        if (! is_string($startRaw) || trim($startRaw) === '') {
            return $structured;
        }

        $timezone = config('app.timezone', 'Asia/Manila');
        try {
            $start = \Carbon\CarbonImmutable::parse($startRaw, $timezone)->setTimezone($timezone);
        } catch (\Throwable) {
            return $structured;
        }

        $now = \Carbon\CarbonImmutable::now($timezone);
        $earliestSensible = $now->addMinutes(30)->setSecond(0)->setMicrosecond(0);
        if ($start->gte($earliestSensible)) {
            return $structured;
        }

        $structured['start_datetime'] = $earliestSensible->toIso8601String();
        if (isset($structured['proposed_properties']) && is_array($structured['proposed_properties'])) {
            $structured['proposed_properties']['start_datetime'] = $earliestSensible->toIso8601String();
        }

        return $structured;
    }
}

<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\LlmInferenceResult;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Enums\LlmOperationMode;
use App\Models\AssistantThread;
use App\Models\User;
use App\Services\Llm\LlmHealthCheck;
use App\Services\Llm\LlmInteractionLogger;
use App\Services\Llm\LlmPostProcessor;
use App\Services\LlmInferenceService;
use Illuminate\Support\Str;

class RunLlmInferenceAction
{
    public function __construct(
        private GetSystemPromptAction $getSystemPrompt,
        private BuildLlmContextAction $buildContext,
        private LlmInferenceService $inferenceService,
        private LlmHealthCheck $healthCheck,
        private LlmInteractionLogger $interactionLogger,
        private LlmPostProcessor $postProcessor,
    ) {}

    public function execute(
        User $user,
        string $userMessage,
        LlmIntent $intent,
        LlmEntityType $entityType,
        ?int $entityId = null,
        ?AssistantThread $thread = null,
        ?string $traceId = null,
    ): LlmInferenceResult {
        // Backward-compat alias: route legacy plan_time_block through the canonical schedule_tasks pipeline.
        $effectiveIntent = $intent === LlmIntent::PlanTimeBlock ? LlmIntent::ScheduleTasks : $intent;
        $effectiveEntityType = $intent === LlmIntent::PlanTimeBlock ? LlmEntityType::Multiple : $entityType;

        $operationMode = $this->operationModeForIntent($effectiveIntent);
        $entityTargets = $this->entityTargetsForIntent($effectiveIntent, $effectiveEntityType);
        $promptResult = $this->getSystemPrompt->executeForModeAndScope(
            mode: $operationMode,
            scope: $effectiveEntityType,
            entityTargets: $entityTargets,
        );

        if (! $this->healthCheck->isReachable()) {
            $result = $this->inferenceService->fallbackOnly(
                intent: $effectiveIntent,
                promptVersion: $promptResult->version,
                user: $user,
                fallbackReason: 'health_unreachable',
            );

            $this->interactionLogger->logInference(
                user: $user,
                intent: $effectiveIntent,
                entityType: $effectiveEntityType,
                promptResult: $promptResult,
                inferenceResult: $result,
                context: [],
                durationMs: 0,
                llmReachable: false,
                traceId: $traceId,
            );

            return $result;
        }

        $context = $this->buildContext->execute($user, $effectiveIntent, $effectiveEntityType, $entityId, $thread, $userMessage);

        $contextJson = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $userPrompt = Str::limit($userMessage, 2000)."\n\nContext:\n".$contextJson;

        $isPrioritizeIntent = in_array($effectiveIntent, [
            LlmIntent::PrioritizeTasks,
            LlmIntent::PrioritizeEvents,
            LlmIntent::PrioritizeProjects,
            LlmIntent::PrioritizeTasksAndEvents,
            LlmIntent::PrioritizeTasksAndProjects,
            LlmIntent::PrioritizeEventsAndProjects,
            LlmIntent::PrioritizeAll,
        ], true);

        if ($isPrioritizeIntent) {
            $userPrompt .= "\n\nGuidance:\n";
            $userPrompt .= 'Only mention tasks, events, and projects that appear in the Context arrays. Do not mention or compare to any other items from conversation history or outside this Context.'; // explicit narrative guard
        }

        $startedAt = microtime(true);

        $result = $this->inferenceService->infer(
            systemPrompt: $promptResult->systemPrompt,
            userPrompt: $userPrompt,
            intent: $effectiveIntent,
            promptResult: $promptResult,
            user: $user,
        );

        $rawStructured = $result->structured;
        $structured = $this->postProcessor->process(
            user: $user,
            intent: $effectiveIntent,
            entityType: $effectiveEntityType,
            context: $context,
            userMessage: $userMessage,
            userPrompt: $userPrompt,
            promptResult: $promptResult,
            result: $result,
            traceId: $traceId,
        );

        $contextFacts = $this->contextFactsForDisplay($context, $effectiveIntent, $effectiveEntityType);

        $result = new LlmInferenceResult(
            structured: $structured,
            promptVersion: $result->promptVersion,
            promptTokens: $result->promptTokens,
            completionTokens: $result->completionTokens,
            usedFallback: $result->usedFallback,
            fallbackReason: $result->fallbackReason,
            rawStructured: $result->usedFallback ? null : $rawStructured,
            contextFacts: $contextFacts,
        );

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        $this->interactionLogger->logInference(
            user: $user,
            intent: $effectiveIntent,
            entityType: $effectiveEntityType,
            promptResult: $promptResult,
            inferenceResult: $result,
            context: $context,
            durationMs: $durationMs,
            llmReachable: true,
            traceId: $traceId,
        );

        return $result;
    }

    private function operationModeForIntent(LlmIntent $intent): LlmOperationMode
    {
        return match ($intent) {
            LlmIntent::ScheduleTask,
            LlmIntent::ScheduleTasks,
            LlmIntent::ScheduleEvent,
            LlmIntent::ScheduleProject,
            LlmIntent::ScheduleTasksAndEvents,
            LlmIntent::ScheduleTasksAndProjects,
            LlmIntent::ScheduleEventsAndProjects,
            LlmIntent::ScheduleAll,
            LlmIntent::AdjustTaskDeadline,
            LlmIntent::AdjustEventTime,
            LlmIntent::AdjustProjectTimeline,
            LlmIntent::PlanTimeBlock => LlmOperationMode::Schedule,
            LlmIntent::PrioritizeTasks,
            LlmIntent::PrioritizeEvents,
            LlmIntent::PrioritizeProjects,
            LlmIntent::PrioritizeTasksAndEvents,
            LlmIntent::PrioritizeTasksAndProjects,
            LlmIntent::PrioritizeEventsAndProjects,
            LlmIntent::PrioritizeAll => LlmOperationMode::Prioritize,
            LlmIntent::CreateTask,
            LlmIntent::CreateEvent,
            LlmIntent::CreateProject => LlmOperationMode::Create,
            LlmIntent::UpdateTaskProperties,
            LlmIntent::UpdateEventProperties,
            LlmIntent::UpdateProjectProperties => LlmOperationMode::Update,
            LlmIntent::ListFilterSearch => LlmOperationMode::ListFilterSearch,
            LlmIntent::ResolveDependency => LlmOperationMode::ResolveDependency,
            default => LlmOperationMode::General,
        };
    }

    /**
     * @return array<int, LlmEntityType>
     */
    private function entityTargetsForIntent(LlmIntent $intent, LlmEntityType $entityType): array
    {
        if ($entityType !== LlmEntityType::Multiple) {
            return [$entityType];
        }

        return match ($intent) {
            LlmIntent::ScheduleTasksAndEvents,
            LlmIntent::PrioritizeTasksAndEvents => [LlmEntityType::Task, LlmEntityType::Event],
            LlmIntent::ScheduleTasksAndProjects,
            LlmIntent::PrioritizeTasksAndProjects => [LlmEntityType::Task, LlmEntityType::Project],
            LlmIntent::ScheduleEventsAndProjects,
            LlmIntent::PrioritizeEventsAndProjects => [LlmEntityType::Event, LlmEntityType::Project],
            LlmIntent::ScheduleTasks => [LlmEntityType::Task],
            default => [LlmEntityType::Task, LlmEntityType::Event, LlmEntityType::Project],
        };
    }

    /**
     * Minimal, display-focused facts derived from the context we sent to the LLM.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function contextFactsForDisplay(array $context, LlmIntent $intent, LlmEntityType $entityType): ?array
    {
        $isPrioritizeIntent = in_array($intent, [
            LlmIntent::PrioritizeTasks,
            LlmIntent::PrioritizeEvents,
            LlmIntent::PrioritizeProjects,
            LlmIntent::PrioritizeTasksAndEvents,
            LlmIntent::PrioritizeTasksAndProjects,
            LlmIntent::PrioritizeEventsAndProjects,
            LlmIntent::PrioritizeAll,
        ], true);
        $timezone = $context['timezone'] ?? config('app.timezone');

        $facts = [];
        if (is_array($context['filtering_summary'] ?? null)) {
            $facts['filtering_summary'] = $context['filtering_summary'];
        }
        if (is_array($context['response_style'] ?? null)) {
            $facts['response_style'] = $context['response_style'];
        }

        if (! $isPrioritizeIntent) {
            return $facts !== [] ? $facts : null;
        }

        $facts['timezone'] = $timezone;
        $facts['current_time'] = $context['current_time'] ?? null;
        $facts['current_date'] = $context['current_date'] ?? null;

        $tasks = $context['tasks'] ?? null;
        if (is_array($tasks) && $tasks !== []) {
            $byTitle = [];
            foreach ($tasks as $task) {
                if (! is_array($task)) {
                    continue;
                }
                $title = isset($task['title']) && is_string($task['title']) ? trim($task['title']) : '';
                if ($title === '') {
                    continue;
                }

                $byTitle[$title] = [
                    'end_datetime' => isset($task['end_datetime']) && is_string($task['end_datetime']) ? $task['end_datetime'] : null,
                    'duration' => isset($task['duration']) && is_numeric($task['duration']) ? (int) $task['duration'] : null,
                    'priority' => isset($task['priority']) && is_string($task['priority']) ? $task['priority'] : null,
                    'complexity' => isset($task['complexity']) && is_string($task['complexity']) ? $task['complexity'] : null,
                    'due_today' => isset($task['due_today']) ? (bool) $task['due_today'] : null,
                    'is_overdue' => isset($task['is_overdue']) ? (bool) $task['is_overdue'] : null,
                ];
            }
            if ($byTitle !== []) {
                $facts['task_facts_by_title'] = $byTitle;
            }
        }

        $events = $context['events'] ?? null;
        if (is_array($events) && $events !== []) {
            $byTitle = [];
            foreach ($events as $event) {
                if (! is_array($event)) {
                    continue;
                }
                $title = isset($event['title']) && is_string($event['title']) ? trim($event['title']) : '';
                if ($title === '') {
                    continue;
                }

                $byTitle[$title] = [
                    'start_datetime' => isset($event['start_datetime']) && is_string($event['start_datetime']) ? $event['start_datetime'] : null,
                    'end_datetime' => isset($event['end_datetime']) && is_string($event['end_datetime']) ? $event['end_datetime'] : null,
                    'starts_within_24h' => isset($event['starts_within_24h']) ? (bool) $event['starts_within_24h'] : null,
                    'starts_within_7_days' => isset($event['starts_within_7_days']) ? (bool) $event['starts_within_7_days'] : null,
                    'all_day' => isset($event['all_day']) ? (bool) $event['all_day'] : null,
                ];
            }
            if ($byTitle !== []) {
                $facts['event_facts_by_title'] = $byTitle;
            }
        }

        $projects = $context['projects'] ?? null;
        if (is_array($projects) && $projects !== []) {
            $byName = [];
            foreach ($projects as $project) {
                if (! is_array($project)) {
                    continue;
                }
                $name = isset($project['name']) && is_string($project['name']) ? trim($project['name']) : '';
                if ($name === '') {
                    continue;
                }

                $byName[$name] = [
                    'start_datetime' => isset($project['start_datetime']) && is_string($project['start_datetime']) ? $project['start_datetime'] : null,
                    'end_datetime' => isset($project['end_datetime']) && is_string($project['end_datetime']) ? $project['end_datetime'] : null,
                    'is_overdue' => isset($project['is_overdue']) ? (bool) $project['is_overdue'] : null,
                    'starts_soon' => isset($project['starts_soon']) ? (bool) $project['starts_soon'] : null,
                    'has_incomplete_tasks' => isset($project['has_incomplete_tasks']) ? (bool) $project['has_incomplete_tasks'] : null,
                ];
            }
            if ($byName !== []) {
                $facts['project_facts_by_name'] = $byName;
            }
        }

        $hasAny = isset($facts['task_facts_by_title']) || isset($facts['event_facts_by_title']) || isset($facts['project_facts_by_name']);

        return $hasAny ? $facts : null;
    }
}

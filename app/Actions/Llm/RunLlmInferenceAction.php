<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\LlmInferenceResult;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Models\AssistantThread;
use App\Models\User;
use App\Services\Llm\ExplicitUserTimeParser;
use App\Services\Llm\LlmHealthCheck;
use App\Services\Llm\LlmInteractionLogger;
use App\Services\Llm\StructuredOutputSanitizer;
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
        private StructuredOutputSanitizer $sanitizer,
        private ExplicitUserTimeParser $explicitUserTimeParser,
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
        $promptResult = $this->getSystemPrompt->execute($intent);

        if (! $this->healthCheck->isReachable()) {
            $result = $this->inferenceService->fallbackOnly(
                intent: $intent,
                promptVersion: $promptResult->version,
                user: $user,
                fallbackReason: 'health_unreachable',
            );

            $this->interactionLogger->logInference(
                user: $user,
                intent: $intent,
                entityType: $entityType,
                promptResult: $promptResult,
                inferenceResult: $result,
                context: [],
                durationMs: 0,
                llmReachable: false,
                traceId: $traceId,
            );

            return $result;
        }

        $context = $this->buildContext->execute($user, $intent, $entityType, $entityId, $thread, $userMessage);

        $contextJson = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $userPrompt = Str::limit($userMessage, 2000)."\n\nContext:\n".$contextJson;

        $isPrioritizeIntent = in_array($intent, [
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
            intent: $intent,
            promptResult: $promptResult,
            user: $user,
        );

        $rawStructured = $result->structured;

        $structured = in_array($intent, [LlmIntent::ScheduleTask, LlmIntent::AdjustTaskDeadline], true)
            ? $this->taskScheduleStructuredFromRaw($rawStructured, $context)
            : $this->sanitizer->sanitize($rawStructured, $context, $intent, $entityType, $userMessage);

        if ($intent === LlmIntent::PrioritizeAll) {
            $structured = $this->ensurePrioritizeAllHasRankedItems($structured, $context);
        }

        // Backend safety net for explicit user time across all single-entity schedule/adjust intents.
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

        $contextFacts = $this->contextFactsForDisplay($context, $intent, $entityType);

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
            intent: $intent,
            entityType: $entityType,
            promptResult: $promptResult,
            inferenceResult: $result,
            context: $context,
            durationMs: $durationMs,
            llmReachable: true,
            traceId: $traceId,
        );

        return $result;
    }

    /**
     * Backend safety net for PrioritizeAll so we never return an empty ranked_*
     * payload when there are items in context (e.g. tag-filtered Exam items).
     *
     * If sanitization strips all ranked items but Context still has tasks/events/projects,
     * this method synthesizes a deterministic ranking based purely on Context.
     *
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function ensurePrioritizeAllHasRankedItems(array $structured, array $context): array
    {
        $tasksContext = $context['tasks'] ?? [];
        $eventsContext = $context['events'] ?? [];
        $projectsContext = $context['projects'] ?? [];

        $hasAnyContext = (is_array($tasksContext) && $tasksContext !== [])
            || (is_array($eventsContext) && $eventsContext !== [])
            || (is_array($projectsContext) && $projectsContext !== []);

        if (! $hasAnyContext) {
            return $structured;
        }

        $rankedTasks = $structured['ranked_tasks'] ?? [];
        $rankedEvents = $structured['ranked_events'] ?? [];
        $rankedProjects = $structured['ranked_projects'] ?? [];

        $hasAnyRanked = (is_array($rankedTasks) && $rankedTasks !== [])
            || (is_array($rankedEvents) && $rankedEvents !== [])
            || (is_array($rankedProjects) && $rankedProjects !== []);

        if ($hasAnyRanked) {
            return $structured;
        }

        $structured['ranked_tasks'] = $this->buildDeterministicTaskRankingFromContext($tasksContext);
        $structured['ranked_events'] = $this->buildDeterministicEventRankingFromContext($eventsContext);
        $structured['ranked_projects'] = $this->buildDeterministicProjectRankingFromContext($projectsContext);

        $structured['recommended_action'] = __('I ranked your items by due date and priority using the filtered context from your request.');
        $structured['reasoning'] = __(
            'The AI output did not produce a usable ranked list, so I deterministically ordered your current context items by urgency instead.'
        );

        if (! isset($structured['entity_type']) || ! is_string($structured['entity_type']) || trim($structured['entity_type']) === '') {
            $structured['entity_type'] = 'all';
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
        if (! $isPrioritizeIntent) {
            return null;
        }

        $timezone = $context['timezone'] ?? config('app.timezone');

        $facts = [
            'timezone' => $timezone,
            'current_time' => $context['current_time'] ?? null,
            'current_date' => $context['current_date'] ?? null,
        ];

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

    /**
     * Use raw LLM output for task schedule; only override when there are no tasks in context.
     *
     * @param  array<string, mixed>  $rawStructured
     * @param  array<string, mixed>  $context
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
     * When the user explicitly specifies a concrete date/time (e.g. "Friday at 9am"),
     * treat that as the primary source of truth for start_datetime and override any
     * conflicting start time from the model. This mirrors the RESPECT_EXPLICIT_USER_TIME
     * guardrail at the prompt layer with a backend safety net.
     *
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
     * Ensure start_datetime is at least 30 minutes from now so the user has time to get ready.
     *
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

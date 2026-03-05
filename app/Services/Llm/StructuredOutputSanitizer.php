<?php

namespace App\Services\Llm;

use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;

/**
 * Sanitizes LLM structured output so ranked_* and listed_items only contain
 * entities that were actually in the context we sent (current DB state).
 * Prevents the model from copying titles from conversation_history or hallucinating.
 * For GeneralQuery with listed_items, applies server-side date filtering when the
 * user asks for "no set dates", "no due date", or "no start date".
 */
class StructuredOutputSanitizer
{
    /** Phrases that mean "no set dates" (both start and end null). */
    private const PHRASES_NO_SET_DATES = [
        'no set dates', 'no dates', 'without dates', 'has no dates', 'that has no set dates',
        'that have no set dates', 'with no set dates', 'tasks that has no set dates',
    ];

    /** Phrases that mean "no due date" or "no end date" (end_datetime null). */
    private const PHRASES_NO_DUE_DATE = [
        'no due date', 'no due dates', 'without due date', 'without deadline',
        'no end date', 'no end dates', 'without end date',
        'that have no due', 'with no due date', 'that has no due', 'has no due date',
    ];

    /** Phrases that mean "no start date" (start_datetime null). */
    private const PHRASES_NO_START_DATE = [
        'no start date', 'no start dates', 'without start date',
        'that have no start', 'with no start date', 'has no start date',
    ];

    /** Phrases that mean "due within the upcoming week" (end_datetime within next 7 days). */
    private const PHRASES_UPCOMING_WEEK = [
        'upcoming week',
        'next week',
        'next 7 days',
        'coming week',
        'this coming week',
        'in the next week',
        'over the next week',
    ];

    /** Priority values for filter matching. */
    private const PRIORITY_LOW = 'low';

    private const PRIORITY_HIGH = 'high';

    private const PRIORITY_URGENT = 'urgent';

    private const PRIORITY_MEDIUM = 'medium';

    /**
     * Filter ranked_* and listed_items to only include entities present in context.
     * For GeneralQuery with listed_items, applies server-side date filtering.
     *
     * @param  array<string, mixed>  $structured  Raw structured output from the LLM
     * @param  array<string, mixed>  $context  Context payload we sent (tasks, events, projects)
     * @param  LlmEntityType|null  $entityType  Entity type for GeneralQuery date filtering
     * @param  string|null  $userMessage  User message for detecting date filter intent
     * @return array<string, mixed> Sanitized structured output
     */
    public function sanitize(
        array $structured,
        array $context,
        LlmIntent $intent,
        ?LlmEntityType $entityType = null,
        ?string $userMessage = null
    ): array {
        $result = match ($intent) {
            LlmIntent::PrioritizeTasks => $this->sanitizeRankedTasks($structured, $context),
            LlmIntent::PrioritizeEvents => $this->sanitizeRankedEvents($structured, $context),
            LlmIntent::PrioritizeProjects => $this->sanitizeRankedProjects($structured, $context),
            LlmIntent::PrioritizeTasksAndEvents => $this->sanitizeRankedTasksAndEvents($structured, $context),
            LlmIntent::PrioritizeTasksAndProjects => $this->sanitizeRankedTasksAndProjects($structured, $context),
            LlmIntent::PrioritizeEventsAndProjects => $this->sanitizeRankedEventsAndProjects($structured, $context),
            LlmIntent::PrioritizeAll => $this->sanitizeRankedAll($structured, $context),
            LlmIntent::ScheduleTask,
            LlmIntent::AdjustTaskDeadline,
            LlmIntent::ScheduleEvent,
            LlmIntent::AdjustEventTime,
            LlmIntent::ScheduleProject,
            LlmIntent::AdjustProjectTimeline => $this->sanitizeSingleScheduleRecommendation($structured),
            LlmIntent::ScheduleTasksAndEvents => $this->sanitizeScheduledTasksAndEvents($structured, $context),
            LlmIntent::ScheduleTasksAndProjects => $this->sanitizeScheduledTasksAndProjects($structured, $context),
            LlmIntent::ScheduleEventsAndProjects => $this->sanitizeScheduledEventsAndProjects($structured, $context),
            LlmIntent::ScheduleAll => $this->sanitizeScheduledAll($structured, $context),
            LlmIntent::GeneralQuery => $this->sanitizeGeneralQuery($structured, $context, $entityType, $userMessage),
            default => $structured,
        };

        return $result;
    }

    /**
     * Apply schedule-time guards to a single schedule-style recommendation
     * (ScheduleTask, ScheduleEvent, ScheduleProject and their adjust variants).
     * If the suggested time range is wholly in the past, strip the temporal
     * fields so the UI does not present an outdated slot.
     *
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    private function sanitizeSingleScheduleRecommendation(array $structured): array
    {
        $items = [[
            'start_datetime' => $structured['start_datetime'] ?? null,
            'end_datetime' => $structured['end_datetime'] ?? null,
        ]];

        $filtered = $this->applyScheduleTimeGuards($items);

        if ($filtered === []) {
            unset(
                $structured['start_datetime'],
                $structured['end_datetime'],
                $structured['duration'],
                $structured['sessions']
            );

            return $structured;
        }

        $first = $filtered[0];

        if (isset($first['start_datetime'])) {
            $structured['start_datetime'] = $first['start_datetime'];
        }

        if (isset($first['end_datetime'])) {
            $structured['end_datetime'] = $first['end_datetime'];
        }

        return $structured;
    }

    /**
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitizeRankedTasks(array $structured, array $context): array
    {
        $allowedTitles = $this->titlesFromContextItems($context['tasks'] ?? []);

        if ($allowedTitles === []) {
            $structured['ranked_tasks'] = [];
            $structured['recommended_action'] = __('You have no tasks yet. Add tasks to get prioritization suggestions.');
            $structured['reasoning'] = __('No tasks are in your list to rank.');
            $structured['confidence'] = min($structured['confidence'] ?? 0, 0.3);

            return $structured;
        }

        $ranked = $structured['ranked_tasks'] ?? [];
        if (! is_array($ranked)) {
            return $structured;
        }

        $filtered = $this->filterRankedByTitle($ranked, $allowedTitles, 'title');
        $structured['ranked_tasks'] = $this->rerank($filtered);

        return $structured;
    }

    /**
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitizeRankedEvents(array $structured, array $context): array
    {
        $allowedTitles = $this->titlesFromContextItems($context['events'] ?? []);

        if ($allowedTitles === []) {
            $structured['ranked_events'] = [];
            $structured['recommended_action'] = __('You have no events yet. Add events to your calendar to get prioritization suggestions.');
            $structured['reasoning'] = __('No events are in your calendar to rank.');
            $structured['confidence'] = min($structured['confidence'] ?? 0, 0.3);

            return $structured;
        }

        $ranked = $structured['ranked_events'] ?? [];
        if (! is_array($ranked)) {
            return $structured;
        }

        $filtered = $this->filterRankedByTitle($ranked, $allowedTitles, 'title');
        $structured['ranked_events'] = $this->rerank($filtered);

        return $structured;
    }

    /**
     * Sanitize both ranked_tasks and ranked_events for PrioritizeTasksAndEvents.
     *
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitizeRankedTasksAndEvents(array $structured, array $context): array
    {
        $tasksContext = $context['tasks'] ?? [];
        $eventsContext = $context['events'] ?? [];
        $allowedTaskTitles = $this->titlesFromContextItems($tasksContext);
        $allowedEventTitles = $this->titlesFromContextItems($eventsContext);

        $rankedTasks = $structured['ranked_tasks'] ?? [];
        if (is_array($rankedTasks)) {
            if ($allowedTaskTitles === []) {
                $structured['ranked_tasks'] = [];
            } else {
                $filtered = $this->filterRankedByTitle($rankedTasks, $allowedTaskTitles, 'title');
                $structured['ranked_tasks'] = $this->rerank($filtered);
            }
        }

        $rankedEvents = $structured['ranked_events'] ?? [];
        if (is_array($rankedEvents)) {
            if ($allowedEventTitles === []) {
                $structured['ranked_events'] = [];
            } else {
                $filtered = $this->filterRankedByTitle($rankedEvents, $allowedEventTitles, 'title');
                $structured['ranked_events'] = $this->rerank($filtered);
            }
        }

        return $structured;
    }

    /**
     * Sanitize both ranked_tasks and ranked_projects for PrioritizeTasksAndProjects.
     *
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitizeRankedTasksAndProjects(array $structured, array $context): array
    {
        $tasksContext = $context['tasks'] ?? [];
        $projectsContext = $context['projects'] ?? [];
        $allowedTaskTitles = $this->titlesFromContextItems($tasksContext);
        $allowedProjectNames = $this->namesFromContextProjects($projectsContext);

        $rankedTasks = $structured['ranked_tasks'] ?? [];
        if (is_array($rankedTasks)) {
            if ($allowedTaskTitles === []) {
                $structured['ranked_tasks'] = [];
            } else {
                $filtered = $this->filterRankedByTitle($rankedTasks, $allowedTaskTitles, 'title');
                $structured['ranked_tasks'] = $this->rerank($filtered);
            }
        }

        $rankedProjects = $structured['ranked_projects'] ?? [];
        if (is_array($rankedProjects)) {
            if ($allowedProjectNames === []) {
                $structured['ranked_projects'] = [];
            } else {
                $filtered = $this->filterRankedByTitle($rankedProjects, $allowedProjectNames, 'name');
                $structured['ranked_projects'] = $this->rerank($filtered);
            }
        }

        return $structured;
    }

    /**
     * Sanitize both ranked_events and ranked_projects for PrioritizeEventsAndProjects.
     *
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitizeRankedEventsAndProjects(array $structured, array $context): array
    {
        $eventsContext = $context['events'] ?? [];
        $projectsContext = $context['projects'] ?? [];
        $allowedEventTitles = $this->titlesFromContextItems($eventsContext);
        $allowedProjectNames = $this->namesFromContextProjects($projectsContext);

        $rankedEvents = $structured['ranked_events'] ?? [];
        if (is_array($rankedEvents)) {
            if ($allowedEventTitles === []) {
                $structured['ranked_events'] = [];
            } else {
                $filtered = $this->filterRankedByTitle($rankedEvents, $allowedEventTitles, 'title');
                $structured['ranked_events'] = $this->rerank($filtered);
            }
        }

        $rankedProjects = $structured['ranked_projects'] ?? [];
        if (is_array($rankedProjects)) {
            if ($allowedProjectNames === []) {
                $structured['ranked_projects'] = [];
            } else {
                $filtered = $this->filterRankedByTitle($rankedProjects, $allowedProjectNames, 'name');
                $structured['ranked_projects'] = $this->rerank($filtered);
            }
        }

        return $structured;
    }

    /**
     * Sanitize ranked_tasks, ranked_events, and ranked_projects for PrioritizeAll.
     *
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitizeRankedAll(array $structured, array $context): array
    {
        $tasksContext = $context['tasks'] ?? [];
        $eventsContext = $context['events'] ?? [];
        $projectsContext = $context['projects'] ?? [];
        $allowedTaskTitles = $this->titlesFromContextItems($tasksContext);
        $allowedEventTitles = $this->titlesFromContextItems($eventsContext);
        $allowedProjectNames = $this->namesFromContextProjects($projectsContext);

        $rankedTasks = $structured['ranked_tasks'] ?? [];
        if (is_array($rankedTasks)) {
            if ($allowedTaskTitles === []) {
                $structured['ranked_tasks'] = [];
            } else {
                $filtered = $this->filterRankedByTitle($rankedTasks, $allowedTaskTitles, 'title');
                $structured['ranked_tasks'] = $this->rerank($filtered);
            }
        }

        $rankedEvents = $structured['ranked_events'] ?? [];
        if (is_array($rankedEvents)) {
            if ($allowedEventTitles === []) {
                $structured['ranked_events'] = [];
            } else {
                $filtered = $this->filterRankedByTitle($rankedEvents, $allowedEventTitles, 'title');
                $structured['ranked_events'] = $this->rerank($filtered);
            }
        }

        $rankedProjects = $structured['ranked_projects'] ?? [];
        if (is_array($rankedProjects)) {
            if ($allowedProjectNames === []) {
                $structured['ranked_projects'] = [];
            } else {
                $filtered = $this->filterRankedByTitle($rankedProjects, $allowedProjectNames, 'name');
                $structured['ranked_projects'] = $this->rerank($filtered);
            }
        }

        return $structured;
    }

    /**
     * Sanitize scheduled_tasks and scheduled_events for ScheduleTasksAndEvents.
     *
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitizeScheduledTasksAndEvents(array $structured, array $context): array
    {
        $allowedTaskTitles = $this->titlesFromContextItems($context['tasks'] ?? []);
        $allowedEventTitles = $this->titlesFromContextItems($context['events'] ?? []);

        $scheduledTasks = $structured['scheduled_tasks'] ?? [];
        if (is_array($scheduledTasks)) {
            $structured['scheduled_tasks'] = $allowedTaskTitles === []
                ? []
                : $this->applyScheduleTimeGuards(
                    $this->filterRankedByTitle($scheduledTasks, $allowedTaskTitles, 'title')
                );
        }

        $scheduledEvents = $structured['scheduled_events'] ?? [];
        if (is_array($scheduledEvents)) {
            $structured['scheduled_events'] = $allowedEventTitles === []
                ? []
                : $this->applyScheduleTimeGuards(
                    $this->filterRankedByTitle($scheduledEvents, $allowedEventTitles, 'title')
                );
        }

        return $structured;
    }

    /**
     * Sanitize scheduled_tasks and scheduled_projects for ScheduleTasksAndProjects.
     *
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitizeScheduledTasksAndProjects(array $structured, array $context): array
    {
        $allowedTaskTitles = $this->titlesFromContextItems($context['tasks'] ?? []);
        $allowedProjectNames = $this->namesFromContextProjects($context['projects'] ?? []);

        $scheduledTasks = $structured['scheduled_tasks'] ?? [];
        if (is_array($scheduledTasks)) {
            $structured['scheduled_tasks'] = $allowedTaskTitles === []
                ? []
                : $this->applyScheduleTimeGuards(
                    $this->filterRankedByTitle($scheduledTasks, $allowedTaskTitles, 'title')
                );
        }

        $scheduledProjects = $structured['scheduled_projects'] ?? [];
        if (is_array($scheduledProjects)) {
            $structured['scheduled_projects'] = $allowedProjectNames === []
                ? []
                : $this->applyScheduleTimeGuards(
                    $this->filterRankedByTitle($scheduledProjects, $allowedProjectNames, 'name')
                );
        }

        return $structured;
    }

    /**
     * Sanitize scheduled_events and scheduled_projects for ScheduleEventsAndProjects.
     *
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitizeScheduledEventsAndProjects(array $structured, array $context): array
    {
        $allowedEventTitles = $this->titlesFromContextItems($context['events'] ?? []);
        $allowedProjectNames = $this->namesFromContextProjects($context['projects'] ?? []);

        $scheduledEvents = $structured['scheduled_events'] ?? [];
        if (is_array($scheduledEvents)) {
            $structured['scheduled_events'] = $allowedEventTitles === []
                ? []
                : $this->applyScheduleTimeGuards(
                    $this->filterRankedByTitle($scheduledEvents, $allowedEventTitles, 'title')
                );
        }

        $scheduledProjects = $structured['scheduled_projects'] ?? [];
        if (is_array($scheduledProjects)) {
            $structured['scheduled_projects'] = $allowedProjectNames === []
                ? []
                : $this->applyScheduleTimeGuards(
                    $this->filterRankedByTitle($scheduledProjects, $allowedProjectNames, 'name')
                );
        }

        return $structured;
    }

    /**
     * Sanitize scheduled_tasks, scheduled_events, scheduled_projects for ScheduleAll.
     *
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitizeScheduledAll(array $structured, array $context): array
    {
        $allowedTaskTitles = $this->titlesFromContextItems($context['tasks'] ?? []);
        $allowedEventTitles = $this->titlesFromContextItems($context['events'] ?? []);
        $allowedProjectNames = $this->namesFromContextProjects($context['projects'] ?? []);

        $scheduledTasks = $structured['scheduled_tasks'] ?? [];
        if (is_array($scheduledTasks)) {
            $structured['scheduled_tasks'] = $allowedTaskTitles === []
                ? []
                : $this->applyScheduleTimeGuards(
                    $this->filterRankedByTitle($scheduledTasks, $allowedTaskTitles, 'title')
                );
        }

        $scheduledEvents = $structured['scheduled_events'] ?? [];
        if (is_array($scheduledEvents)) {
            $structured['scheduled_events'] = $allowedEventTitles === []
                ? []
                : $this->applyScheduleTimeGuards(
                    $this->filterRankedByTitle($scheduledEvents, $allowedEventTitles, 'title')
                );
        }

        $scheduledProjects = $structured['scheduled_projects'] ?? [];
        if (is_array($scheduledProjects)) {
            $structured['scheduled_projects'] = $allowedProjectNames === []
                ? []
                : $this->applyScheduleTimeGuards(
                    $this->filterRankedByTitle($scheduledProjects, $allowedProjectNames, 'name')
                );
        }

        return $structured;
    }

    /**
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitizeRankedProjects(array $structured, array $context): array
    {
        $allowedNames = $this->namesFromContextProjects($context['projects'] ?? []);

        if ($allowedNames === []) {
            $structured['ranked_projects'] = [];
            $structured['recommended_action'] = __('You have no projects yet. Add projects to get prioritization suggestions.');
            $structured['reasoning'] = __('No projects are in your list to rank.');
            $structured['confidence'] = min($structured['confidence'] ?? 0, 0.3);

            return $structured;
        }

        $ranked = $structured['ranked_projects'] ?? [];
        if (! is_array($ranked)) {
            return $structured;
        }

        $filtered = $this->filterRankedByTitle($ranked, $allowedNames, 'name');
        $structured['ranked_projects'] = $this->rerank($filtered);

        return $structured;
    }

    /**
     * Sanitize GeneralQuery: filter listed_items by context and apply server-side filtering for
     * dates, priority, complexity, recurring, and all-day flags.
     *
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitizeGeneralQuery(
        array $structured,
        array $context,
        ?LlmEntityType $entityType,
        ?string $userMessage
    ): array {
        $userMsg = $userMessage ?? '';
        $dateFilter = $this->detectDateFilterFromMessage($userMsg);
        $priorityFilter = $this->detectPriorityFilterFromMessage($userMsg);
        $complexityFilter = $this->detectComplexityFilterFromMessage($userMsg);
        $recurringFilter = $this->detectRecurringFilterFromMessage($userMsg);
        $allDayFilter = $this->detectAllDayFilterFromMessage($userMsg);
        if ($this->detectDropFilterFromMessage($userMsg) && $entityType === LlmEntityType::Task && $priorityFilter === null) {
            $priorityFilter = self::PRIORITY_LOW;
        }
        $contextItems = $this->getContextItemsForEntity($context, $entityType);

        if ($contextItems === []) {
            $structured['listed_items'] = [];
            $this->applyEmptyListMessageIfNeeded($structured, $dateFilter, $priorityFilter, $recurringFilter, $allDayFilter, $entityType, $complexityFilter);

            return $structured;
        }

        $listedItems = $structured['listed_items'] ?? [];
        $listedItems = is_array($listedItems) ? $listedItems : [];

        $hasFilter = $dateFilter !== null
            || $priorityFilter !== null
            || $complexityFilter !== null
            || $recurringFilter !== null
            || $allDayFilter !== null;
        if ($hasFilter) {
            $filtered = $this->buildListFromContext($contextItems, $dateFilter, $priorityFilter, $recurringFilter, $allDayFilter, $complexityFilter);
        } else {
            $filtered = $this->filterListedItemsByContextAndDate($listedItems, $contextItems, null);
        }

        $structured['listed_items'] = $filtered;
        $this->applyEmptyListMessageIfNeeded($structured, $dateFilter, $priorityFilter, $recurringFilter, $allDayFilter, $entityType, $complexityFilter);

        return $structured;
    }

    /**
     * Build listed_items from context when we have a date/priority/complexity filter (don't trust LLM).
     *
     * @param  array<int, array<string, mixed>>  $contextItems
     * @param  'no_set_dates'|'no_due_date'|'no_start_date'|null  $dateFilter
     * @return array<int, array<string, mixed>>
     */
    private function buildListFromContext(
        array $contextItems,
        ?string $dateFilter,
        ?string $priorityFilter,
        ?string $recurringFilter,
        ?string $allDayFilter,
        ?string $complexityFilter
    ): array {
        $out = [];
        foreach ($contextItems as $ctx) {
            $label = $ctx['title'] ?? $ctx['name'] ?? null;
            if (! is_array($ctx) || ! is_string($label) || trim($label) === '') {
                continue;
            }
            if ($dateFilter !== null && ! $this->contextItemMatchesDateFilter($ctx, $dateFilter)) {
                continue;
            }
            if ($priorityFilter !== null && ! $this->contextItemMatchesPriorityFilter($ctx, $priorityFilter)) {
                continue;
            }
            if ($complexityFilter !== null && ! $this->contextItemMatchesComplexityFilter($ctx, $complexityFilter)) {
                continue;
            }
            if ($recurringFilter !== null && ! $this->contextItemMatchesRecurringFilter($ctx, $recurringFilter)) {
                continue;
            }
            if ($allDayFilter !== null && ! $this->contextItemMatchesAllDayFilter($ctx, $allDayFilter)) {
                continue;
            }
            $out[] = $this->buildCanonicalListedItem($ctx, $dateFilter);
        }

        return $out;
    }

    /**
     * Override recommended_action and reasoning when result is empty for a filter query.
     *
     * @param  'no_set_dates'|'no_due_date'|'no_start_date'|null  $dateFilter
     */
    private function applyEmptyListMessageIfNeeded(
        array &$structured,
        ?string $dateFilter,
        ?string $priorityFilter,
        ?string $recurringFilter,
        ?string $allDayFilter,
        ?LlmEntityType $entityType = null,
        ?string $complexityFilter = null
    ): void {
        $listedItems = $structured['listed_items'] ?? [];
        if (! is_array($listedItems) || count($listedItems) > 0) {
            return;
        }

        $entityLabel = match ($entityType) {
            LlmEntityType::Event => 'events',
            LlmEntityType::Project => 'projects',
            default => 'tasks',
        };

        if ($dateFilter !== null) {
            $structured['recommended_action'] = match ($dateFilter) {
                'no_set_dates' => __('All your :entity have dates set. You don\'t have any :entity without start or end dates.', ['entity' => $entityLabel]),
                'no_due_date' => __('All your :entity have due dates set. You don\'t have any :entity without a due date.', ['entity' => $entityLabel]),
                'no_start_date' => __('All your :entity have start dates set. You don\'t have any :entity without a start date.', ['entity' => $entityLabel]),
                default => $structured['recommended_action'] ?? '',
            };
            $structured['reasoning'] = __('I checked your :entity and every one has the relevant date(s) set.', ['entity' => $entityLabel]);
        } elseif ($priorityFilter !== null) {
            $structured['recommended_action'] = __('You don\'t have any tasks with that priority.');
            $structured['reasoning'] = __('I checked your task list and none match the priority filter.');
        } elseif ($complexityFilter !== null) {
            $structured['recommended_action'] = __('You don\'t have any tasks with that complexity.');
            $structured['reasoning'] = __('I checked your task list and none match the complexity filter.');
        } elseif ($recurringFilter !== null) {
            $structured['recommended_action'] = __('You don\'t have any recurring tasks.');
            $structured['reasoning'] = __('I checked your task list and none are set to repeat.');
        } elseif ($allDayFilter !== null) {
            $structured['recommended_action'] = __('You don\'t have any all-day events.');
            $structured['reasoning'] = __('I checked your events and none are marked as all-day.');
        }
    }

    /**
     * Detect when the user is asking which tasks to drop/delete/remove because there are too many.
     */
    private function detectDropFilterFromMessage(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            return false;
        }

        $phrases = [
            'too many tasks',
            'too many things to do',
            'help me decide what to drop',
            'decide what to drop',
            'what to drop',
            'what can i drop',
            'which can i drop',
            'what should i drop',
            'what should i delete',
            'which can i delete',
            'what can i delete',
            'what to delete',
            'what should i remove',
            'what can i remove',
            'which can i remove',
            'which to remove',
            'let go of',
            'what can i get rid of',
            'which tasks to drop',
            'which tasks can i drop',
            'which ones to drop',
            'which ones should i drop',
            'which ones can i drop',
        ];

        foreach ($phrases as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return 'low'|'high'|'urgent'|'medium'|null
     */
    private function detectPriorityFilterFromMessage(string $message): ?string
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            return null;
        }
        if (str_contains($normalized, 'low prio') || str_contains($normalized, 'low priority') || str_contains($normalized, 'low prioritiy')) {
            return self::PRIORITY_LOW;
        }
        if (str_contains($normalized, 'high prio') || str_contains($normalized, 'high priority')) {
            return self::PRIORITY_HIGH;
        }
        if (str_contains($normalized, 'urgent')) {
            return self::PRIORITY_URGENT;
        }
        if (str_contains($normalized, 'medium prio') || str_contains($normalized, 'medium priority')) {
            return self::PRIORITY_MEDIUM;
        }

        return null;
    }

    /**
     * Detect a complexity filter (simple | moderate | complex) from the user message.
     *
     * @return 'simple'|'moderate'|'complex'|null
     */
    private function detectComplexityFilterFromMessage(string $message): ?string
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, 'simple') || str_contains($normalized, 'easy')) {
            return 'simple';
        }
        if (str_contains($normalized, 'moderate complexity') || str_contains($normalized, 'medium complexity') || str_contains($normalized, 'moderate')) {
            return 'moderate';
        }
        if (str_contains($normalized, 'complex') || str_contains($normalized, 'hard') || str_contains($normalized, 'difficult')) {
            return 'complex';
        }

        return null;
    }

    /** @return 'recurring'|null */
    private function detectRecurringFilterFromMessage(string $message): ?string
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '' || ! str_contains($normalized, 'recurring')) {
            return null;
        }

        return 'recurring';
    }

    /** @return 'all_day'|null */
    private function detectAllDayFilterFromMessage(string $message): ?string
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '' || ! (str_contains($normalized, 'all-day') || str_contains($normalized, 'all day'))) {
            return null;
        }

        return 'all_day';
    }

    /** @param  array<string, mixed>  $ctx  */
    private function contextItemMatchesRecurringFilter(array $ctx, string $recurringFilter): bool
    {
        if ($recurringFilter !== 'recurring') {
            return true;
        }

        return ! empty($ctx['is_recurring'] ?? false);
    }

    /** @param  array<string, mixed>  $ctx  */
    private function contextItemMatchesAllDayFilter(array $ctx, string $allDayFilter): bool
    {
        if ($allDayFilter !== 'all_day') {
            return true;
        }

        return ! empty($ctx['all_day'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function contextItemMatchesPriorityFilter(array $ctx, string $priorityFilter): bool
    {
        $ctxPriority = isset($ctx['priority']) && is_string($ctx['priority'])
            ? mb_strtolower(trim($ctx['priority']))
            : null;

        return $ctxPriority === $priorityFilter;
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function contextItemMatchesComplexityFilter(array $ctx, string $complexityFilter): bool
    {
        $ctxComplexity = isset($ctx['complexity']) && is_string($ctx['complexity'])
            ? mb_strtolower(trim($ctx['complexity']))
            : null;

        if ($ctxComplexity === null) {
            return false;
        }

        return $ctxComplexity === $complexityFilter;
    }

    /**
     * Detect which date filter the user asked for.
     *
     * @return 'no_set_dates'|'no_due_date'|'no_start_date'|null
     */
    private function detectDateFilterFromMessage(string $message): ?string
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            return null;
        }

        foreach (self::PHRASES_UPCOMING_WEEK as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return 'upcoming_week';
            }
        }

        foreach (self::PHRASES_NO_SET_DATES as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return 'no_set_dates';
            }
        }
        foreach (self::PHRASES_NO_DUE_DATE as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return 'no_due_date';
            }
        }
        foreach (self::PHRASES_NO_START_DATE as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return 'no_start_date';
            }
        }

        return null;
    }

    /**
     * Get context items (tasks or events) for the entity type.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getContextItemsForEntity(array $context, ?LlmEntityType $entityType): array
    {
        return match ($entityType) {
            LlmEntityType::Task => $context['tasks'] ?? [],
            LlmEntityType::Event => $context['events'] ?? [],
            LlmEntityType::Project => $context['projects'] ?? [],
            default => $context['tasks'] ?? [],
        };
    }

    /**
     * Filter listed_items: only include items from context that match the date filter.
     * Uses canonical data from context (not LLM output) for dates.
     *
     * @param  array<int, mixed>  $listedItems
     * @param  array<int, array<string, mixed>>  $contextItems
     * @param  'no_set_dates'|'no_due_date'|'no_start_date'|null  $filter
     * @return array<int, array<string, mixed>>
     */
    private function filterListedItemsByContextAndDate(array $listedItems, array $contextItems, ?string $filter): array
    {
        $contextByTitle = $this->buildContextByTitle($contextItems);

        $out = [];
        foreach ($listedItems as $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = isset($item['title']) && is_string($item['title']) ? trim($item['title']) : '';
            if ($title === '') {
                continue;
            }

            $ctx = $this->findContextItemByTitle($title, $contextByTitle);
            if ($ctx === null) {
                continue;
            }

            if ($filter !== null && ! $this->contextItemMatchesDateFilter($ctx, $filter)) {
                continue;
            }

            $out[] = $this->buildCanonicalListedItem($ctx, $filter);
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $contextItems
     * @return array<string, array<string, mixed>>
     */
    private function buildContextByTitle(array $contextItems): array
    {
        $byTitle = [];
        foreach ($contextItems as $item) {
            if (! is_array($item)) {
                continue;
            }
            $label = $item['title'] ?? $item['name'] ?? null;
            if (! is_string($label) || trim($label) === '') {
                continue;
            }
            $byTitle[mb_strtolower(trim($label))] = $item;
        }

        return $byTitle;
    }

    /**
     * @param  array<string, array<string, mixed>>  $contextByTitle
     * @return array<string, mixed>|null
     */
    private function findContextItemByTitle(string $title, array $contextByTitle): ?array
    {
        $key = mb_strtolower(trim($title));
        if (isset($contextByTitle[$key])) {
            return $contextByTitle[$key];
        }
        foreach ($contextByTitle as $ctxTitle => $ctx) {
            similar_text($key, mb_strtolower($ctxTitle), $percent);
            if ($percent >= self::TITLE_SIMILARITY_THRESHOLD) {
                return $ctx;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  'no_set_dates'|'no_due_date'|'no_start_date'  $filter
     */
    private function contextItemMatchesDateFilter(array $ctx, string $filter): bool
    {
        $startNull = empty($ctx['start_datetime'] ?? null);
        $endNull = empty($ctx['end_datetime'] ?? null);

        return match ($filter) {
            'no_set_dates' => $startNull && $endNull,
            'no_due_date' => $endNull,
            'no_start_date' => $startNull,
            'upcoming_week' => $this->contextItemMatchesUpcomingWeek($ctx),
            default => true,
        };
    }

    /**
     * Match tasks/events that have an end date within the next 7 days.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function contextItemMatchesUpcomingWeek(array $ctx): bool
    {
        $end = $ctx['end_datetime'] ?? null;
        if (! is_string($end) || trim($end) === '') {
            return false;
        }

        try {
            $endAt = \Carbon\CarbonImmutable::parse($end);
        } catch (\Throwable) {
            return false;
        }

        $start = \Carbon\CarbonImmutable::now(config('app.timezone'));
        $windowEnd = $start->addDays(7);

        return $endAt->betweenIncluded($start, $windowEnd);
    }

    /**
     * Apply stricter backend guards to scheduled time ranges so we avoid
     * obviously unreasonable times, such as scheduling "today" work in the
     * early-morning hours of the next day in the Asia/Manila timezone.
     *
     * - Drop items whose end time is wholly in the past.
     * - When a start or end falls between 00:00 and 06:00 local time and the
     *   date is strictly after today, prefer to drop the item. This prevents
     *   LLM outputs like 01:00–03:00 tomorrow when the user asked for "today".
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function applyScheduleTimeGuards(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $timezone = config('app.timezone');
        $now = \Carbon\CarbonImmutable::now($timezone);
        $today = $now->toDateString();

        $out = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $startRaw = isset($item['start_datetime']) && is_string($item['start_datetime']) ? trim($item['start_datetime']) : null;
            $endRaw = isset($item['end_datetime']) && is_string($item['end_datetime']) ? trim($item['end_datetime']) : null;

            try {
                $start = $startRaw ? \Carbon\CarbonImmutable::parse($startRaw, $timezone)->setTimezone($timezone) : null;
            } catch (\Throwable) {
                $start = null;
            }

            try {
                $end = $endRaw ? \Carbon\CarbonImmutable::parse($endRaw, $timezone)->setTimezone($timezone) : null;
            } catch (\Throwable) {
                $end = null;
            }

            // Drop items with an end time wholly in the past.
            if ($end !== null && $end->lt($now)) {
                continue;
            }

            // If the model suggested a slot in the early morning (00:00–06:00)
            // of a *future* day, drop it. This protects against answers like
            // "today after lunch" being mapped to 01:00–03:00 tomorrow.
            $candidate = $start ?? $end;
            if ($candidate !== null) {
                $candidateDate = $candidate->toDateString();
                $hour = (int) $candidate->format('G');

                if ($candidateDate > $today && $hour >= 0 && $hour < 6) {
                    continue;
                }
            }

            $out[] = $item;
        }

        return $out;
    }

    /**
     * Build canonical listed item from context. For "no dates" filters, omit date fields.
     *
     * @param  array<string, mixed>  $ctx
     * @param  'no_set_dates'|'no_due_date'|'no_start_date'|null  $filter
     * @return array<string, mixed>
     */
    private function buildCanonicalListedItem(array $ctx, ?string $filter): array
    {
        $label = trim((string) ($ctx['title'] ?? $ctx['name'] ?? ''));
        $item = ['title' => $label];

        if ($filter === 'no_set_dates') {
            return $item;
        }

        if ($filter !== 'no_start_date' && ! empty($ctx['start_datetime']) && is_string($ctx['start_datetime'])) {
            $item['start_datetime'] = $ctx['start_datetime'];
        }
        if ($filter !== 'no_due_date' && ! empty($ctx['end_datetime']) && is_string($ctx['end_datetime'])) {
            $item['end_datetime'] = $ctx['end_datetime'];
        }
        if (isset($ctx['priority']) && is_string($ctx['priority'])) {
            $item['priority'] = $ctx['priority'];
        }

        return $item;
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<string>
     */
    private function titlesFromContextItems(array $items): array
    {
        $titles = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['title']) && is_string($item['title'])) {
                $titles[] = trim($item['title']);
            }
        }

        return array_values(array_unique($titles));
    }

    /**
     * @param  array<int, mixed>  $projects
     * @return array<string>
     */
    private function namesFromContextProjects(array $projects): array
    {
        $names = [];
        foreach ($projects as $p) {
            if (is_array($p) && isset($p['name']) && is_string($p['name'])) {
                $names[] = trim($p['name']);
            }
        }

        return array_values(array_unique($names));
    }

    /** Minimum similarity (0-100) to accept a model title as matching a context title (handles minor typos). */
    private const TITLE_SIMILARITY_THRESHOLD = 85;

    /**
     * @param  array<int, mixed>  $ranked
     * @param  array<string>  $allowedTitles
     * @return array<int, mixed>
     */
    private function filterRankedByTitle(array $ranked, array $allowedTitles, string $titleKey): array
    {
        $out = [];
        foreach ($ranked as $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = $item[$titleKey] ?? null;
            if (! is_string($title)) {
                continue;
            }
            $trimmed = trim($title);
            $match = $this->bestMatchingTitle($trimmed, $allowedTitles);
            if ($match !== null) {
                $item[$titleKey] = $match;
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * Return the allowed title that best matches the given title (exact or fuzzy).
     *
     * @param  array<string>  $allowedTitles
     */
    private function bestMatchingTitle(string $title, array $allowedTitles): ?string
    {
        $lower = mb_strtolower($title);
        foreach ($allowedTitles as $allowed) {
            $allowedTrimmed = trim($allowed);
            if (mb_strtolower($allowedTrimmed) === $lower) {
                return $allowedTrimmed;
            }
        }

        $bestSimilarity = 0;
        $bestTitle = null;
        foreach ($allowedTitles as $allowed) {
            $allowedTrimmed = trim($allowed);
            similar_text($lower, mb_strtolower($allowedTrimmed), $percent);

            if ($percent >= self::TITLE_SIMILARITY_THRESHOLD && $percent > $bestSimilarity) {
                $bestSimilarity = $percent;
                $bestTitle = $allowedTrimmed;
            }
        }

        return $bestTitle;
    }

    /**
     * Re-assign rank 1, 2, 3... after filtering.
     *
     * @param  array<int, mixed>  $items
     * @return array<int, mixed>
     */
    private function rerank(array $items): array
    {
        $out = [];
        foreach ($items as $i => $item) {
            if (is_array($item)) {
                $item['rank'] = $i + 1;
                $out[] = $item;
            }
        }

        return $out;
    }
}

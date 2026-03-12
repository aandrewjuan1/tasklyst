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
        'within 7 days',
        'within the next 7 days',
        'this week',
        'for this week',
        'due this week',
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
            LlmIntent::AdjustProjectTimeline => $this->sanitizeSingleScheduleRecommendationWithContext($structured, $context, $intent, $userMessage),
            LlmIntent::ScheduleTasks => $this->sanitizeScheduledTasksOnly($structured, $context, $userMessage),
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
     * Apply a quick rule-based guard for single-entity schedule/adjust intents:
     * if there are no matching entities in context, avoid presenting a
     * hallucinated schedule and instead return a clear "no items" message.
     *
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @param  string|null  $userMessage  Last user message; used to correct same-day vs tomorrow for task schedule
     * @return array<string, mixed>
     */
    private function sanitizeSingleScheduleRecommendationWithContext(array $structured, array $context, LlmIntent $intent, ?string $userMessage = null): array
    {
        $key = match ($intent) {
            LlmIntent::ScheduleTask,
            LlmIntent::AdjustTaskDeadline => 'tasks',
            LlmIntent::ScheduleEvent,
            LlmIntent::AdjustEventTime => 'events',
            LlmIntent::ScheduleProject,
            LlmIntent::AdjustProjectTimeline => 'projects',
            default => null,
        };

        if ($key !== null) {
            $items = $context[$key] ?? [];
            if (! is_array($items) || $items === []) {
                unset(
                    $structured['start_datetime'],
                    $structured['end_datetime'],
                    $structured['duration'],
                    $structured['sessions']
                );
                unset($structured['proposed_properties']);

                $structured['recommended_action'] = match ($key) {
                    'tasks' => __('You have no tasks yet. Add tasks to your list to get scheduling suggestions.'),
                    'events' => __('You have no events yet. Add events to your calendar to get scheduling suggestions.'),
                    'projects' => __('You have no projects yet. Add projects to get scheduling suggestions.'),
                    default => __('You have no items yet. Add some to get scheduling suggestions.'),
                };

                $structured['reasoning'] = match ($key) {
                    'tasks' => __('I checked your tasks and there are none to schedule right now.'),
                    'events' => __('I checked your events and there are none to schedule right now.'),
                    'projects' => __('I checked your projects and there are none to schedule right now.'),
                    default => __('There are no items available to schedule right now.'),
                };

                if (! isset($structured['entity_type'])) {
                    $structured['entity_type'] = match ($key) {
                        'tasks' => 'task',
                        'events' => 'event',
                        'projects' => 'project',
                        default => $structured['entity_type'] ?? 'task',
                    };
                }

                return $structured;
            }

            if ($intent === LlmIntent::ScheduleEvent || $intent === LlmIntent::AdjustEventTime) {
                $structured = $this->bindSingleEventToContext($structured, $items);
            }

            $structured = $this->normalizeScheduleFromSessions($structured, $intent);
        }

        if ($intent === LlmIntent::ScheduleTask || $intent === LlmIntent::AdjustTaskDeadline) {
            unset($structured['end_datetime']);
            if (isset($structured['proposed_properties']) && is_array($structured['proposed_properties'])) {
                unset($structured['proposed_properties']['end_datetime']);
            }

            return $structured;
        }

        return $this->sanitizeSingleScheduleRecommendation($structured, $intent);
    }

    /**
     * Apply schedule-time guards to a single schedule-style recommendation
     * (ScheduleTask, ScheduleEvent, ScheduleProject and their adjust variants).
     * If the suggested time range is wholly in the past, strip the temporal
     * fields so the UI does not present an outdated slot.
     * For task intents: only start_datetime and duration are kept; end_datetime is stripped.
     *
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    private function sanitizeSingleScheduleRecommendation(array $structured, LlmIntent $intent): array
    {
        $isTaskSchedule = $intent === LlmIntent::ScheduleTask || $intent === LlmIntent::AdjustTaskDeadline;

        $structured = $this->mergeProposedPropertiesForSchedule($structured, $intent);

        if ($isTaskSchedule) {
            unset($structured['end_datetime']);
            if (isset($structured['proposed_properties']) && is_array($structured['proposed_properties'])) {
                unset($structured['proposed_properties']['end_datetime']);
            }
        }

        $start = $structured['start_datetime'] ?? null;
        $end = $structured['end_datetime'] ?? null;
        $duration = isset($structured['duration']) && is_numeric($structured['duration']) ? (int) $structured['duration'] : null;

        if ($isTaskSchedule && $end === null && $start !== null && $duration !== null && $duration > 0) {
            try {
                $startCarbon = \Carbon\CarbonImmutable::parse($start, config('app.timezone'));
                $end = $startCarbon->addMinutes($duration)->toIso8601String();
            } catch (\Throwable) {
                $end = null;
            }
        }

        $items = [[
            'start_datetime' => $start,
            'end_datetime' => $end,
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
            $correctedStart = $first['start_datetime'];
            $structured['start_datetime'] = $correctedStart;
            if (isset($structured['proposed_properties']) && is_array($structured['proposed_properties'])) {
                $structured['proposed_properties']['start_datetime'] = $correctedStart;
            }
        }

        if ($isTaskSchedule) {
            unset($structured['end_datetime']);
            if (isset($structured['proposed_properties']) && is_array($structured['proposed_properties'])) {
                unset($structured['proposed_properties']['end_datetime']);
            }
        } elseif (isset($first['end_datetime'])) {
            $structured['end_datetime'] = $first['end_datetime'];
        }

        return $structured;
    }

    /**
     * Sanitize scheduled_tasks for ScheduleTasks (tasks only, multi-apply).
     *
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitizeScheduledTasksOnly(array $structured, array $context, ?string $userMessage = null): array
    {
        $allowedTaskTitles = $this->titlesFromContextItems($context['tasks'] ?? []);
        if ($allowedTaskTitles === []) {
            $structured['scheduled_tasks'] = [];
            $structured['recommended_action'] = __('You have no tasks yet. Add tasks to your list to get scheduling suggestions.');
            $structured['reasoning'] = __('I checked your tasks and there are none to schedule right now.');

            return $structured;
        }

        $scheduledTasks = $structured['scheduled_tasks'] ?? [];
        if (is_array($scheduledTasks)) {
            $filtered = $this->filterRankedByTitle($scheduledTasks, $allowedTaskTitles, 'title');
            $withIds = $this->injectTaskIdsIntoScheduledTasks($filtered, $context['tasks'] ?? []);
            $guarded = $this->applyScheduleTimeGuardsThenStripEndFromTaskItems($withIds);
            $guarded = $this->enforceRequestedWindowForScheduledTasks($guarded, $context, $userMessage);

            // If we stripped everything due to window mismatch, avoid presenting a wrong Apply payload.
            if ($guarded === [] && $withIds !== []) {
                $structured['recommended_action'] = __(
                    'I couldn’t produce a valid schedule inside your requested time window. Please try again.'
                );
                $structured['reasoning'] = __(
                    'The suggested time(s) were outside your requested window, so they were removed for safety.'
                );
            }

            $structured['scheduled_tasks'] = $guarded;
        }

        return $structured;
    }

    /**
     * Enforce that each scheduled task start_datetime is within the user-requested time window
     * when that window can be parsed from the user message.
     *
     * @param  array<int, array<string, mixed>>  $scheduledTasks
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function enforceRequestedWindowForScheduledTasks(array $scheduledTasks, array $context, ?string $userMessage): array
    {
        $window = $this->parseRequestedTimeWindow($userMessage, $context);
        if ($window === null) {
            return $scheduledTasks;
        }
        [$start, $end] = $window;

        $out = [];
        foreach ($scheduledTasks as $item) {
            if (! is_array($item)) {
                continue;
            }
            $rawStart = $item['start_datetime'] ?? null;
            if (! is_string($rawStart) || trim($rawStart) === '') {
                continue;
            }
            try {
                $candidate = \Carbon\CarbonImmutable::parse($rawStart, $start->getTimezone()->getName());
            } catch (\Throwable) {
                continue;
            }

            if ($candidate->lt($start) || $candidate->gt($end)) {
                continue;
            }

            $out[] = $item;
        }

        return $out;
    }

    /**
     * Parse a user-requested time window from messages like:
     * "From 7pm to 11pm tonight" or "Between 19:00 and 23:00 this evening".
     *
     * Returns [windowStart, windowEnd] in app timezone when parseable; otherwise null.
     *
     * @return array{0:\Carbon\CarbonImmutable,1:\Carbon\CarbonImmutable}|null
     */
    private function parseRequestedTimeWindow(?string $userMessage, array $context): ?array
    {
        $message = is_string($userMessage) ? trim($userMessage) : '';
        if ($message === '') {
            return null;
        }

        $timezone = is_string($context['timezone'] ?? null) && trim((string) $context['timezone']) !== ''
            ? (string) $context['timezone']
            : config('app.timezone', 'Asia/Manila');

        $currentDate = is_string($context['current_date'] ?? null) && trim((string) $context['current_date']) !== ''
            ? (string) $context['current_date']
            : now($timezone)->toDateString();

        $m = mb_strtolower($message);
        $isTonight = str_contains($m, 'tonight') || str_contains($m, 'this evening') || str_contains($m, 'today');
        if (! $isTonight) {
            return null;
        }

        $time = '(2[0-3]|[01]?\d)(?::(\d{2}))?\s*([ap])?\.?\s*m?\.?';
        $fromTo = [];
        if (preg_match('/\bfrom\s+'.$time.'\s+to\s+'.$time.'\b/u', $m, $fromTo) !== 1) {
            $between = [];
            if (preg_match('/\bbetween\s+'.$time.'\s+and\s+'.$time.'\b/u', $m, $between) !== 1) {
                return null;
            }
            $fromTo = $between;
        }

        // groups: 1=fromHour,2=fromMin,3=fromAmPm, 4=toHour,5=toMin,6=toAmPm
        $fromHour = (int) ($fromTo[1] ?? 0);
        $fromMin = isset($fromTo[2]) && $fromTo[2] !== '' ? (int) $fromTo[2] : 0;
        $fromAmPm = $fromTo[3] ?? null;
        $toHour = (int) ($fromTo[4] ?? 0);
        $toMin = isset($fromTo[5]) && $fromTo[5] !== '' ? (int) $fromTo[5] : 0;
        $toAmPm = $fromTo[6] ?? null;

        $fromHour = $this->hourFromOptionalAmPm($fromHour, $fromAmPm);
        $toHour = $this->hourFromOptionalAmPm($toHour, $toAmPm);

        try {
            $start = \Carbon\CarbonImmutable::parse($currentDate.' '.$fromHour.':'.str_pad((string) $fromMin, 2, '0', STR_PAD_LEFT).':00', $timezone);
            $end = \Carbon\CarbonImmutable::parse($currentDate.' '.$toHour.':'.str_pad((string) $toMin, 2, '0', STR_PAD_LEFT).':00', $timezone);
        } catch (\Throwable) {
            return null;
        }

        if ($end->lte($start)) {
            return null;
        }

        return [$start, $end];
    }

    private function hourFromOptionalAmPm(int $hour, ?string $ampm): int
    {
        $hour = max(0, min(23, $hour));
        $a = is_string($ampm) ? trim(mb_strtolower($ampm)) : '';

        if ($a === 'a' || $a === 'p') {
            $h12 = max(0, min(12, $hour));
            if ($a === 'p') {
                return $h12 === 12 ? 12 : $h12 + 12;
            }

            return $h12 === 12 ? 0 : $h12;
        }

        return $hour;
    }

    /**
     * Ensure schedule/adjust event recommendations point to a real event from context.
     * Normalises id/title to the exact context values and avoids letting the model
     * invent arbitrary event titles that do not exist in the current calendar.
     *
     * @param  array<string, mixed>  $structured
     * @param  array<int, mixed>  $eventsContext
     * @return array<string, mixed>
     */
    private function bindSingleEventToContext(array $structured, array $eventsContext): array
    {
        if ($eventsContext === []) {
            return $structured;
        }

        $byId = [];
        foreach ($eventsContext as $event) {
            if (! is_array($event) || ! isset($event['id']) || ! is_numeric($event['id'])) {
                continue;
            }

            $byId[(int) $event['id']] = $event;
        }

        $allowedTitles = $this->titlesFromContextItems($eventsContext);

        $id = null;
        if (isset($structured['id']) && is_numeric($structured['id'])) {
            $id = (int) $structured['id'];
        } elseif (isset($structured['target_event_id']) && is_numeric($structured['target_event_id'])) {
            $id = (int) $structured['target_event_id'];
        }

        $target = null;
        if ($id !== null && isset($byId[$id])) {
            $target = $byId[$id];
        } else {
            $title = null;
            if (isset($structured['title']) && is_string($structured['title'])) {
                $title = trim($structured['title']);
            } elseif (isset($structured['target_event_title']) && is_string($structured['target_event_title'])) {
                $title = trim($structured['target_event_title']);
            }

            if ($title !== null && $title !== '' && $allowedTitles !== []) {
                $matchedTitle = $this->bestMatchingTitle($title, $allowedTitles);
                if ($matchedTitle !== null) {
                    foreach ($eventsContext as $event) {
                        if (! is_array($event) || ! isset($event['title']) || ! is_string($event['title'])) {
                            continue;
                        }

                        if (trim($event['title']) === $matchedTitle) {
                            $target = $event;

                            break;
                        }
                    }
                }
            }
        }

        if ($target === null) {
            unset(
                $structured['id'],
                $structured['target_event_id'],
                $structured['title'],
                $structured['target_event_title']
            );

            return $structured;
        }

        $trueId = isset($target['id']) && is_numeric($target['id']) ? (int) $target['id'] : null;
        $trueTitle = isset($target['title']) && is_string($target['title']) ? trim($target['title']) : null;

        if ($trueId !== null && $trueId > 0) {
            $structured['id'] = $trueId;
            $structured['target_event_id'] = $trueId;
        }

        if ($trueTitle !== null && $trueTitle !== '') {
            $structured['title'] = $trueTitle;
            $structured['target_event_title'] = $trueTitle;
        }

        return $structured;
    }

    /**
     * When the LLM returns schedule in "sessions" only, copy first session's start_datetime, duration,
     * and (for event/project) end_datetime to top-level and proposed_properties so appliable_changes
     * and the builder see them. Used for ScheduleTask, ScheduleEvent, ScheduleProject and adjust variants.
     *
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    private function normalizeScheduleFromSessions(array $structured, LlmIntent $intent): array
    {
        $sessions = $structured['sessions'] ?? null;
        if (! is_array($sessions) || $sessions === [] || ! is_array($sessions[0] ?? null)) {
            return $structured;
        }

        $isTask = $intent === LlmIntent::ScheduleTask || $intent === LlmIntent::AdjustTaskDeadline;
        $first = $sessions[0];
        $start = $first['start_datetime'] ?? null;
        $end = $first['end_datetime'] ?? null;
        if ($end === 'null' || $end === '') {
            $end = null;
        }
        $duration = isset($first['duration']) && is_numeric($first['duration']) ? (int) $first['duration'] : null;
        $priority = isset($first['priority']) && $first['priority'] !== '' ? $first['priority'] : null;

        if ($start === null && $end === null && $duration === null && $priority === null) {
            return $structured;
        }

        if ($start !== null && $start !== '' && (! array_key_exists('start_datetime', $structured) || $structured['start_datetime'] === null || $structured['start_datetime'] === '')) {
            $structured['start_datetime'] = $start;
        }
        if (! $isTask && $end !== null && $end !== '' && (! array_key_exists('end_datetime', $structured) || $structured['end_datetime'] === null || $structured['end_datetime'] === '')) {
            $structured['end_datetime'] = $end;
        }
        if ($duration !== null && $duration > 0 && (! array_key_exists('duration', $structured) || $structured['duration'] === null || $structured['duration'] === '')) {
            $structured['duration'] = $duration;
        }
        if ($priority !== null && (! array_key_exists('priority', $structured) || $structured['priority'] === null || $structured['priority'] === '')) {
            $structured['priority'] = $priority;
        }

        $proposed = $structured['proposed_properties'] ?? [];
        $proposed = is_array($proposed) ? $proposed : [];
        if ($start !== null && $start !== '' && ($proposed['start_datetime'] ?? null) === null) {
            $proposed['start_datetime'] = $start;
        }
        if (! $isTask && $end !== null && $end !== '' && ($proposed['end_datetime'] ?? null) === null) {
            $proposed['end_datetime'] = $end;
        }
        if ($duration !== null && $duration > 0 && ($proposed['duration'] ?? null) === null) {
            $proposed['duration'] = $duration;
        }
        if ($priority !== null && ($proposed['priority'] ?? null) === null) {
            $proposed['priority'] = $priority;
        }
        $structured['proposed_properties'] = $proposed;

        return $structured;
    }

    /**
     * Merge proposed_properties onto top-level structured so the UI and appliable_changes see them.
     * For task schedule intents, only start_datetime, duration, and priority are merged (never end_datetime).
     *
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    private function mergeProposedPropertiesForSchedule(array $structured, ?LlmIntent $intent = null): array
    {
        $proposed = $structured['proposed_properties'] ?? null;
        if (! is_array($proposed) || $proposed === []) {
            return $structured;
        }

        $isTaskSchedule = $intent === LlmIntent::ScheduleTask || $intent === LlmIntent::AdjustTaskDeadline;
        $keys = $isTaskSchedule ? ['start_datetime', 'duration', 'priority'] : ['start_datetime', 'end_datetime', 'duration', 'priority'];

        foreach ($keys as $key) {
            if (array_key_exists($key, $proposed) && $proposed[$key] !== null && $proposed[$key] !== '') {
                if (! array_key_exists($key, $structured) || $structured[$key] === null || $structured[$key] === '') {
                    $structured[$key] = $proposed[$key];
                }
            }
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
            $structured['recommended_action'] = __('I couldn\'t find any tasks that match this request.');
            $structured['reasoning'] = __('I checked the tasks available in this context and none matched the filters for this prompt (for example, date range or school-only vs chores).');
            $structured['confidence'] = min($structured['confidence'] ?? 0, 0.3);

            return $structured;
        }

        $ranked = $structured['ranked_tasks'] ?? [];
        if (! is_array($ranked)) {
            return $structured;
        }

        $filtered = $this->filterRankedByTitle($ranked, $allowedTitles, 'title');

        // Deduplicate by title (LLMs sometimes repeat the same task twice).
        $seen = [];
        $deduped = [];
        foreach ($filtered as $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = isset($item['title']) && is_string($item['title']) ? trim($item['title']) : '';
            if ($title === '' || isset($seen[$title])) {
                continue;
            }
            $seen[$title] = true;
            $deduped[] = $item;
        }
        $filtered = $deduped;

        // Canonicalize datetime fields from context to prevent time drift (AM/PM or timezone mistakes).
        $contextTasks = $context['tasks'] ?? [];
        if (is_array($contextTasks) && $contextTasks !== []) {
            $endByTitle = [];
            foreach ($contextTasks as $t) {
                if (! is_array($t) || ! isset($t['title']) || ! is_string($t['title'])) {
                    continue;
                }
                $title = trim($t['title']);
                if ($title === '') {
                    continue;
                }
                if (isset($t['end_datetime']) && is_string($t['end_datetime']) && trim($t['end_datetime']) !== '') {
                    $endByTitle[$title] = trim($t['end_datetime']);
                }
            }
            if ($endByTitle !== []) {
                foreach ($filtered as &$item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $title = isset($item['title']) && is_string($item['title']) ? trim($item['title']) : '';
                    if ($title !== '' && isset($endByTitle[$title])) {
                        $item['end_datetime'] = $endByTitle[$title];
                    }
                }
                unset($item);
            }
        }

        // Keep LLM ordering as the source of truth, but enforce requested_top_n
        // (or "rank every item") when the context slice contains more tasks than
        // the model returned. This ensures prompts like "top 5" or generic
        // "prioritize my tasks" always yield a complete ranked list.
        $requestedTopN = null;
        if (isset($context['requested_top_n']) && is_numeric($context['requested_top_n'])) {
            $requestedTopN = (int) $context['requested_top_n'];
            if ($requestedTopN <= 0) {
                $requestedTopN = null;
            }
        }

        if ($requestedTopN !== null && is_array($contextTasks) && $contextTasks !== []) {
            $available = min($requestedTopN, count($contextTasks));

            if (count($filtered) < $available) {
                // Titles the model already ranked.
                $rankedTitles = [];
                foreach ($filtered as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    if (isset($item['title']) && is_string($item['title'])) {
                        $rankedTitles[] = trim($item['title']);
                    }
                }

                // Fill in remaining slots from the context slice, in context order.
                foreach ($contextTasks as $taskPayload) {
                    if (! is_array($taskPayload) || ! isset($taskPayload['title']) || ! is_string($taskPayload['title'])) {
                        continue;
                    }
                    $title = trim($taskPayload['title']);
                    if ($title === '' || in_array($title, $rankedTitles, true)) {
                        continue;
                    }

                    $item = [
                        'title' => $title,
                    ];
                    if (isset($taskPayload['end_datetime']) && is_string($taskPayload['end_datetime']) && trim($taskPayload['end_datetime']) !== '') {
                        $item['end_datetime'] = trim($taskPayload['end_datetime']);
                    }

                    $filtered[] = $item;
                    $rankedTitles[] = $title;

                    if (count($filtered) >= $available) {
                        break;
                    }
                }
            }
        }

        $structured['ranked_tasks'] = $this->rerank($this->stripRankedIdentifiers($filtered));

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
            $structured['recommended_action'] = __('I couldn\'t find any events that match this request.');
            $structured['reasoning'] = __('I checked the events available in this context and none matched the filters for this prompt (for example, date range or exam-related only).');
            $structured['confidence'] = min($structured['confidence'] ?? 0, 0.3);

            return $structured;
        }

        $ranked = $structured['ranked_events'] ?? [];
        if (! is_array($ranked)) {
            return $structured;
        }

        $filtered = $this->filterRankedByTitle($ranked, $allowedTitles, 'title');

        // Canonicalize start/end datetimes from context to prevent time drift.
        $eventsContext = $context['events'] ?? [];
        if (is_array($eventsContext) && $eventsContext !== []) {
            $startByTitle = [];
            $endByTitle = [];
            foreach ($eventsContext as $e) {
                if (! is_array($e) || ! isset($e['title']) || ! is_string($e['title'])) {
                    continue;
                }
                $title = trim($e['title']);
                if ($title === '') {
                    continue;
                }
                if (isset($e['start_datetime']) && is_string($e['start_datetime']) && trim($e['start_datetime']) !== '') {
                    $startByTitle[$title] = trim($e['start_datetime']);
                }
                if (isset($e['end_datetime']) && is_string($e['end_datetime']) && trim($e['end_datetime']) !== '') {
                    $endByTitle[$title] = trim($e['end_datetime']);
                }
            }
            foreach ($filtered as &$item) {
                if (! is_array($item)) {
                    continue;
                }
                $title = isset($item['title']) && is_string($item['title']) ? trim($item['title']) : '';
                if ($title === '') {
                    continue;
                }
                if (isset($startByTitle[$title])) {
                    $item['start_datetime'] = $startByTitle[$title];
                }
                if (isset($endByTitle[$title])) {
                    $item['end_datetime'] = $endByTitle[$title];
                }
            }
            unset($item);
        }

        // Enforce requested_top_n for events when possible, similar to tasks.
        $requestedTopN = null;
        if (isset($context['requested_top_n']) && is_numeric($context['requested_top_n'])) {
            $requestedTopN = (int) $context['requested_top_n'];
            if ($requestedTopN <= 0) {
                $requestedTopN = null;
            }
        }

        if ($requestedTopN !== null && is_array($eventsContext) && $eventsContext !== []) {
            $available = min($requestedTopN, count($eventsContext));

            if (count($filtered) < $available) {
                $rankedTitles = [];
                foreach ($filtered as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    if (isset($item['title']) && is_string($item['title'])) {
                        $rankedTitles[] = trim($item['title']);
                    }
                }

                foreach ($eventsContext as $eventPayload) {
                    if (! is_array($eventPayload) || ! isset($eventPayload['title']) || ! is_string($eventPayload['title'])) {
                        continue;
                    }
                    $title = trim($eventPayload['title']);
                    if ($title === '' || in_array($title, $rankedTitles, true)) {
                        continue;
                    }

                    $item = [
                        'title' => $title,
                    ];
                    if (isset($eventPayload['start_datetime']) && is_string($eventPayload['start_datetime']) && trim($eventPayload['start_datetime']) !== '') {
                        $item['start_datetime'] = trim($eventPayload['start_datetime']);
                    }
                    if (isset($eventPayload['end_datetime']) && is_string($eventPayload['end_datetime']) && trim($eventPayload['end_datetime']) !== '') {
                        $item['end_datetime'] = trim($eventPayload['end_datetime']);
                    }

                    $filtered[] = $item;
                    $rankedTitles[] = $title;

                    if (count($filtered) >= $available) {
                        break;
                    }
                }
            }
        }

        $structured['ranked_events'] = $this->rerank($this->stripRankedIdentifiers($filtered));

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
                $structured['ranked_tasks'] = $this->rerank($this->stripRankedIdentifiers($filtered));
            }
        }

        $rankedEvents = $structured['ranked_events'] ?? [];
        if (is_array($rankedEvents)) {
            if ($allowedEventTitles === []) {
                $structured['ranked_events'] = [];
            } else {
                $filtered = $this->filterRankedByTitle($rankedEvents, $allowedEventTitles, 'title');
                $structured['ranked_events'] = $this->rerank($this->stripRankedIdentifiers($filtered));
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
                $structured['ranked_tasks'] = $this->rerank($this->stripRankedIdentifiers($filtered));
            }
        }

        $rankedProjects = $structured['ranked_projects'] ?? [];
        if (is_array($rankedProjects)) {
            if ($allowedProjectNames === []) {
                $structured['ranked_projects'] = [];
            } else {
                $filtered = $this->filterRankedByTitle($rankedProjects, $allowedProjectNames, 'name');
                $structured['ranked_projects'] = $this->rerank($this->stripRankedIdentifiers($filtered));
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
                $structured['ranked_events'] = $this->rerank($this->stripRankedIdentifiers($filtered));
            }
        }

        $rankedProjects = $structured['ranked_projects'] ?? [];
        if (is_array($rankedProjects)) {
            if ($allowedProjectNames === []) {
                $structured['ranked_projects'] = [];
            } else {
                $filtered = $this->filterRankedByTitle($rankedProjects, $allowedProjectNames, 'name');
                $structured['ranked_projects'] = $this->rerank($this->stripRankedIdentifiers($filtered));
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
                $structured['ranked_tasks'] = $this->rerank($this->stripRankedIdentifiers($filtered));
            }
        }

        $rankedEvents = $structured['ranked_events'] ?? [];
        if (is_array($rankedEvents)) {
            if ($allowedEventTitles === []) {
                $structured['ranked_events'] = [];
            } else {
                $filtered = $this->filterRankedByTitle($rankedEvents, $allowedEventTitles, 'title');
                $structured['ranked_events'] = $this->rerank($this->stripRankedIdentifiers($filtered));
            }
        }

        $rankedProjects = $structured['ranked_projects'] ?? [];
        if (is_array($rankedProjects)) {
            if ($allowedProjectNames === []) {
                $structured['ranked_projects'] = [];
            } else {
                $filtered = $this->filterRankedByTitle($rankedProjects, $allowedProjectNames, 'name');
                $structured['ranked_projects'] = $this->rerank($this->stripRankedIdentifiers($filtered));
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

        if ($allowedTaskTitles === [] && $allowedEventTitles === []) {
            $structured['scheduled_tasks'] = [];
            $structured['scheduled_events'] = [];
            $structured['recommended_action'] = __('You have no tasks or events yet. Add some to get scheduling suggestions.');
            $structured['reasoning'] = __('I checked your tasks and events and there are none to schedule right now.');

            return $structured;
        }

        $scheduledTasks = $structured['scheduled_tasks'] ?? [];
        if (is_array($scheduledTasks)) {
            $filtered = $allowedTaskTitles === []
                ? []
                : $this->filterRankedByTitle($scheduledTasks, $allowedTaskTitles, 'title');
            $withIds = $this->injectTaskIdsIntoScheduledTasks($filtered, $context['tasks'] ?? []);
            $structured['scheduled_tasks'] = $this->applyScheduleTimeGuardsThenStripEndFromTaskItems($withIds);
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

        if ($allowedTaskTitles === [] && $allowedProjectNames === []) {
            $structured['scheduled_tasks'] = [];
            $structured['scheduled_projects'] = [];
            $structured['recommended_action'] = __('You have no tasks or projects yet. Add some to get scheduling suggestions.');
            $structured['reasoning'] = __('I checked your tasks and projects and there are none to schedule right now.');

            return $structured;
        }

        $scheduledTasks = $structured['scheduled_tasks'] ?? [];
        if (is_array($scheduledTasks)) {
            $filtered = $allowedTaskTitles === []
                ? []
                : $this->filterRankedByTitle($scheduledTasks, $allowedTaskTitles, 'title');
            $withIds = $this->injectTaskIdsIntoScheduledTasks($filtered, $context['tasks'] ?? []);
            $structured['scheduled_tasks'] = $this->applyScheduleTimeGuardsThenStripEndFromTaskItems($withIds);
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

        if ($allowedEventTitles === [] && $allowedProjectNames === []) {
            $structured['scheduled_events'] = [];
            $structured['scheduled_projects'] = [];
            $structured['recommended_action'] = __('You have no events or projects yet. Add some to get scheduling suggestions.');
            $structured['reasoning'] = __('I checked your events and projects and there are none to schedule right now.');

            return $structured;
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

        if ($allowedTaskTitles === [] && $allowedEventTitles === [] && $allowedProjectNames === []) {
            $structured['scheduled_tasks'] = [];
            $structured['scheduled_events'] = [];
            $structured['scheduled_projects'] = [];
            $structured['recommended_action'] = __('You have no tasks, events, or projects yet. Add some to get scheduling suggestions.');
            $structured['reasoning'] = __('I checked your tasks, events, and projects and there are none to schedule right now.');

            return $structured;
        }

        $scheduledTasks = $structured['scheduled_tasks'] ?? [];
        if (is_array($scheduledTasks)) {
            $filtered = $allowedTaskTitles === []
                ? []
                : $this->filterRankedByTitle($scheduledTasks, $allowedTaskTitles, 'title');
            $withIds = $this->injectTaskIdsIntoScheduledTasks($filtered, $context['tasks'] ?? []);
            $structured['scheduled_tasks'] = $this->applyScheduleTimeGuardsThenStripEndFromTaskItems($withIds);
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
     * Inject task id from context into each scheduled task by matching title, so Apply can update the correct task.
     *
     * @param  array<int, array<string, mixed>>  $scheduledTasks
     * @param  array<int, mixed>  $contextTasks
     * @return array<int, array<string, mixed>>
     */
    private function injectTaskIdsIntoScheduledTasks(array $scheduledTasks, array $contextTasks): array
    {
        $allowedTitles = $this->titlesFromContextItems($contextTasks);
        if ($allowedTitles === []) {
            return $scheduledTasks;
        }

        $titleToId = [];
        $idToTitle = [];
        foreach ($contextTasks as $t) {
            if (! is_array($t) || ! isset($t['title'], $t['id']) || ! is_numeric($t['id'])) {
                continue;
            }
            $title = trim((string) $t['title']);
            if ($title !== '' && ! array_key_exists($title, $titleToId)) {
                $titleToId[$title] = (int) $t['id'];
                $idToTitle[(int) $t['id']] = $title;
            }
        }

        $out = [];
        foreach ($scheduledTasks as $item) {
            if (! is_array($item)) {
                $out[] = $item;

                continue;
            }

            if (isset($item['id']) && is_numeric($item['id']) && isset($idToTitle[(int) $item['id']])) {
                $resolvedTitle = $idToTitle[(int) $item['id']];
                $item['id'] = (int) $item['id'];
                $item['title'] = $resolvedTitle;
                $out[] = $item;

                continue;
            }

            $title = isset($item['title']) && is_string($item['title']) ? trim($item['title']) : '';
            $matchedTitle = $title !== '' ? $this->bestMatchingTitle($title, $allowedTitles) : null;
            if ($matchedTitle !== null && isset($titleToId[$matchedTitle])) {
                $item['id'] = $titleToId[$matchedTitle];
                $item['title'] = $matchedTitle;
            }
            $out[] = $item;
        }

        return $out;
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
            $structured['recommended_action'] = __('I couldn\'t find any projects that match this request.');
            $structured['reasoning'] = __('I checked the projects available in this context and none matched the filters for this prompt (for example, active projects in the requested timeframe).');
            $structured['confidence'] = min($structured['confidence'] ?? 0, 0.3);

            return $structured;
        }

        $ranked = $structured['ranked_projects'] ?? [];
        if (! is_array($ranked)) {
            return $structured;
        }

        $filtered = $this->filterRankedByTitle($ranked, $allowedNames, 'name');

        // Canonicalize start/end datetimes from context to prevent time drift.
        $projectsContext = $context['projects'] ?? [];
        if (is_array($projectsContext) && $projectsContext !== []) {
            $startByName = [];
            $endByName = [];
            foreach ($projectsContext as $p) {
                if (! is_array($p) || ! isset($p['name']) || ! is_string($p['name'])) {
                    continue;
                }
                $name = trim($p['name']);
                if ($name === '') {
                    continue;
                }
                if (isset($p['start_datetime']) && is_string($p['start_datetime']) && trim($p['start_datetime']) !== '') {
                    $startByName[$name] = trim($p['start_datetime']);
                }
                if (isset($p['end_datetime']) && is_string($p['end_datetime']) && trim($p['end_datetime']) !== '') {
                    $endByName[$name] = trim($p['end_datetime']);
                }
            }
            foreach ($filtered as &$item) {
                if (! is_array($item)) {
                    continue;
                }
                $name = isset($item['name']) && is_string($item['name']) ? trim($item['name']) : '';
                if ($name === '') {
                    continue;
                }
                if (isset($startByName[$name])) {
                    $item['start_datetime'] = $startByName[$name];
                }
                if (isset($endByName[$name])) {
                    $item['end_datetime'] = $endByName[$name];
                }
            }
            unset($item);
        }

        // Enforce requested_top_n for projects when possible.
        $requestedTopN = null;
        if (isset($context['requested_top_n']) && is_numeric($context['requested_top_n'])) {
            $requestedTopN = (int) $context['requested_top_n'];
            if ($requestedTopN <= 0) {
                $requestedTopN = null;
            }
        }

        if ($requestedTopN !== null && is_array($projectsContext) && $projectsContext !== []) {
            $available = min($requestedTopN, count($projectsContext));

            if (count($filtered) < $available) {
                $rankedNames = [];
                foreach ($filtered as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    if (isset($item['name']) && is_string($item['name'])) {
                        $rankedNames[] = trim($item['name']);
                    }
                }

                foreach ($projectsContext as $projectPayload) {
                    if (! is_array($projectPayload) || ! isset($projectPayload['name']) || ! is_string($projectPayload['name'])) {
                        continue;
                    }
                    $name = trim($projectPayload['name']);
                    if ($name === '' || in_array($name, $rankedNames, true)) {
                        continue;
                    }

                    $item = [
                        'name' => $name,
                    ];
                    if (isset($projectPayload['start_datetime']) && is_string($projectPayload['start_datetime']) && trim($projectPayload['start_datetime']) !== '') {
                        $item['start_datetime'] = trim($projectPayload['start_datetime']);
                    }
                    if (isset($projectPayload['end_datetime']) && is_string($projectPayload['end_datetime']) && trim($projectPayload['end_datetime']) !== '') {
                        $item['end_datetime'] = trim($projectPayload['end_datetime']);
                    }

                    $filtered[] = $item;
                    $rankedNames[] = $name;

                    if (count($filtered) >= $available) {
                        break;
                    }
                }
            }
        }

        $structured['ranked_projects'] = $this->rerank($this->stripRankedIdentifiers($filtered));

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
        $listingRequest = $this->detectListingRequestFromMessage($userMsg);
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
        if ($hasFilter || $listingRequest) {
            $filtered = $this->buildListFromContext($contextItems, $dateFilter, $priorityFilter, $recurringFilter, $allDayFilter, $complexityFilter);
            $structured['reasoning'] = $hasFilter
                ? $this->humanReasoningForFilters($entityType, $dateFilter, $priorityFilter, $complexityFilter, $recurringFilter, $allDayFilter)
                : $this->humanReasoningForListing($entityType);
        } else {
            $filtered = $this->filterListedItemsByContextAndDate($listedItems, $contextItems, null);
        }

        $structured['listed_items'] = $filtered;
        $this->applyEmptyListMessageIfNeeded($structured, $dateFilter, $priorityFilter, $recurringFilter, $allDayFilter, $entityType, $complexityFilter);

        return $structured;
    }

    private function humanReasoningForFilters(
        ?LlmEntityType $entityType,
        ?string $dateFilter,
        ?string $priorityFilter,
        ?string $complexityFilter,
        ?string $recurringFilter,
        ?string $allDayFilter
    ): string {
        $entityLabel = match ($entityType) {
            LlmEntityType::Event => __('events'),
            LlmEntityType::Project => __('projects'),
            LlmEntityType::Multiple => __('items'),
            default => __('tasks'),
        };

        $parts = [];
        if ($recurringFilter !== null) {
            $parts[] = __('recurring');
        }
        if ($allDayFilter !== null) {
            $parts[] = __('all-day');
        }
        if ($priorityFilter !== null && ($entityType === null || $entityType === LlmEntityType::Task)) {
            $parts[] = match ($priorityFilter) {
                self::PRIORITY_LOW => __('low priority'),
                self::PRIORITY_MEDIUM => __('medium priority'),
                self::PRIORITY_HIGH => __('high priority'),
                self::PRIORITY_URGENT => __('urgent priority'),
                default => $priorityFilter,
            };
        }
        if ($complexityFilter !== null && ($entityType === null || $entityType === LlmEntityType::Task)) {
            $parts[] = __(':complexity complexity', ['complexity' => $complexityFilter]);
        }
        if ($dateFilter !== null) {
            $parts[] = match ($dateFilter) {
                'no_set_dates' => __('with no start or end dates'),
                'no_due_date' => __('with no due date'),
                'no_start_date' => __('with no start date'),
                'upcoming_week' => __('due within the next 7 days'),
                default => __('matching your date filter'),
            };
        }

        if ($parts === []) {
            return (string) __('I filtered your :entity to match your request.', ['entity' => $entityLabel]);
        }

        return (string) __('I filtered your :entity to those that are :filters.', [
            'entity' => $entityLabel,
            'filters' => implode(' '.__('and').' ', $parts),
        ]);
    }

    private function humanReasoningForListing(?LlmEntityType $entityType): string
    {
        $entityLabel = match ($entityType) {
            LlmEntityType::Event => __('events'),
            LlmEntityType::Project => __('projects'),
            LlmEntityType::Multiple => __('items'),
            default => __('tasks'),
        };

        return (string) __('I listed your current :entity from the latest context.', [
            'entity' => $entityLabel,
        ]);
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

        // Combined filters: avoid misleading "All your tasks have due dates" when the intersection is empty.
        if ($dateFilter !== null && ($priorityFilter !== null || $complexityFilter !== null || $recurringFilter !== null || $allDayFilter !== null)) {
            $parts = [];
            if ($priorityFilter !== null && $entityType === LlmEntityType::Task) {
                $parts[] = match ($priorityFilter) {
                    'low' => __('low priority'),
                    'medium' => __('medium priority'),
                    'high' => __('high priority'),
                    'urgent' => __('urgent priority'),
                    default => $priorityFilter,
                };
            }
            if ($complexityFilter !== null && $entityType === LlmEntityType::Task) {
                $parts[] = __(':complexity complexity', ['complexity' => $complexityFilter]);
            }
            if ($recurringFilter !== null && $entityType === LlmEntityType::Task) {
                $parts[] = __('recurring');
            }
            if ($allDayFilter !== null && $entityType === LlmEntityType::Event) {
                $parts[] = __('all-day');
            }

            $dateLabel = match ($dateFilter) {
                'no_set_dates' => __('without start or end dates'),
                'no_due_date' => __('without a due date'),
                'no_start_date' => __('without a start date'),
                'upcoming_week' => __('due within the next 7 days'),
                default => __('matching that date filter'),
            };

            $filterLabel = $parts !== [] ? implode(' '.__('and').' ', $parts).' '.__('items').' '.$dateLabel : $dateLabel;

            $structured['recommended_action'] = __('You don\'t have any :entity :filter.', [
                'entity' => $entityLabel,
                'filter' => $filterLabel,
            ]);
            $structured['reasoning'] = __('I checked your :entity and none match all of the requested filters.', [
                'entity' => $entityLabel,
            ]);

            return;
        }

        if ($dateFilter !== null) {
            $structured['recommended_action'] = match ($dateFilter) {
                'no_set_dates' => __('All your :entity have dates set. You don\'t have any :entity without start or end dates.', ['entity' => $entityLabel]),
                'no_due_date' => __('All your :entity have due dates set. You don\'t have any :entity without a due date.', ['entity' => $entityLabel]),
                'no_start_date' => __('All your :entity have start dates set. You don\'t have any :entity without a start date.', ['entity' => $entityLabel]),
                'upcoming_week' => __('You don\'t have any :entity due within the next 7 days.', ['entity' => $entityLabel]),
                default => $structured['recommended_action'] ?? '',
            };
            $structured['reasoning'] = $dateFilter === 'upcoming_week'
                ? __('I checked your :entity and none are due in the upcoming week window.', ['entity' => $entityLabel])
                : __('I checked your :entity and every one has the relevant date(s) set.', ['entity' => $entityLabel]);
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
            LlmEntityType::Multiple => $this->mergeContextItemsForMultiple(
                $context['tasks'] ?? [],
                $context['events'] ?? [],
                $context['projects'] ?? []
            ),
            default => $context['tasks'] ?? [],
        };
    }

    /**
     * @param  array<int, mixed>  $tasks
     * @param  array<int, mixed>  $events
     * @param  array<int, mixed>  $projects
     * @return array<int, array<string, mixed>>
     */
    private function mergeContextItemsForMultiple(array $tasks, array $events, array $projects): array
    {
        $out = [];

        foreach ($tasks as $task) {
            if (is_array($task)) {
                $task['__entity_type'] = 'task';
                $out[] = $task;
            }
        }
        foreach ($events as $event) {
            if (is_array($event)) {
                $event['__entity_type'] = 'event';
                $out[] = $event;
            }
        }
        foreach ($projects as $project) {
            if (! is_array($project)) {
                continue;
            }
            if (! isset($project['title']) && isset($project['name']) && is_string($project['name'])) {
                $project['title'] = $project['name'];
            }
            $project['__entity_type'] = 'project';
            $out[] = $project;
        }

        return $out;
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
        $matchedKey = $this->bestMatchingTitle($title, array_keys($contextByTitle));

        return $matchedKey !== null ? ($contextByTitle[$matchedKey] ?? null) : null;
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

            // If start is in the past, push to the next 30-minute boundary from now so we never show a past time.
            if ($start !== null && $start->lt($now)) {
                $min = (int) $now->format('i');
                $earliestStart = $min < 30
                    ? $now->setMinute(30)->setSecond(0)->setMicrosecond(0)
                    : $now->addHour()->setMinute(0)->setSecond(0)->setMicrosecond(0);
                $item['start_datetime'] = $earliestStart->toIso8601String();
                if ($end !== null && $end->gt($earliestStart)) {
                    $durationMinutes = (int) $start->diffInMinutes($end, false);
                    if ($durationMinutes > 0) {
                        $item['end_datetime'] = $earliestStart->addMinutes($durationMinutes)->toIso8601String();
                    }
                }
                $start = $earliestStart;
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
     * For scheduled_tasks (start + duration only): add temporary end_datetime for guard, run guard, then strip end.
     *
     * @param  array<int, array<string, mixed>>  $scheduledTasks
     * @return array<int, array<string, mixed>>
     */
    private function applyScheduleTimeGuardsThenStripEndFromTaskItems(array $scheduledTasks): array
    {
        if ($scheduledTasks === []) {
            return [];
        }

        $timezone = config('app.timezone');

        $withSyntheticEnd = [];
        foreach ($scheduledTasks as $item) {
            if (! is_array($item)) {
                $withSyntheticEnd[] = $item;

                continue;
            }
            $start = $item['start_datetime'] ?? null;
            $end = $item['end_datetime'] ?? null;
            $duration = isset($item['duration']) && is_numeric($item['duration']) ? (int) $item['duration'] : null;
            if ($end === null && is_string($start) && $duration !== null && $duration > 0) {
                try {
                    $item['end_datetime'] = \Carbon\CarbonImmutable::parse($start, $timezone)->addMinutes($duration)->toIso8601String();
                } catch (\Throwable) {
                    // leave end_datetime unset
                }
            }
            $withSyntheticEnd[] = $item;
        }

        $filtered = $this->applyScheduleTimeGuards($withSyntheticEnd);

        foreach ($filtered as &$item) {
            if (is_array($item)) {
                unset($item['end_datetime']);
            }
        }
        unset($item);

        return $filtered;
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
        if (isset($ctx['__entity_type']) && is_string($ctx['__entity_type'])) {
            $item['entity_type'] = $ctx['__entity_type'];
        }

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

    private function detectListingRequestFromMessage(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            return false;
        }

        $phrases = [
            'list',
            'show me',
            'show all',
            'what are my',
            'which are my',
            'what do i have',
            'give me my',
            'display my',
        ];

        foreach ($phrases as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }

        return false;
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
     * Return the allowed title that best matches the given title:
     * exact case-insensitive match first, then a unique normalized fallback.
     *
     * @param  array<string>  $allowedTitles
     */
    private function bestMatchingTitle(string $title, array $allowedTitles): ?string
    {
        $normalizedInput = $this->normalizeTitleForExactMatch($title);
        if ($normalizedInput === '') {
            return null;
        }

        foreach ($allowedTitles as $allowed) {
            $allowedTrimmed = trim((string) preg_replace('/\s+/', ' ', (string) $allowed));
            if ($this->normalizeTitleForExactMatch($allowedTrimmed) === $normalizedInput) {
                return $allowedTrimmed;
            }
        }

        $normalizedFallback = $this->normalizeTitleForUniqueFallback($title);
        if ($normalizedFallback === '') {
            return null;
        }

        $matches = [];
        foreach ($allowedTitles as $allowed) {
            $allowedTrimmed = trim((string) preg_replace('/\s+/', ' ', (string) $allowed));
            if ($allowedTrimmed === '') {
                continue;
            }

            if ($this->normalizeTitleForUniqueFallback($allowedTrimmed) === $normalizedFallback) {
                $matches[] = $allowedTrimmed;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function normalizeTitleForExactMatch(string $title): string
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $title));

        return mb_strtolower($normalized);
    }

    private function normalizeTitleForUniqueFallback(string $title): string
    {
        $lower = mb_strtolower(trim($title));
        $alnumSpace = (string) preg_replace('/[^\p{L}\p{N}\s]/u', '', $lower);
        $collapsed = trim((string) preg_replace('/\s+/', ' ', $alnumSpace));

        return $collapsed;
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, mixed>
     */
    private function stripRankedIdentifiers(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            unset(
                $item['id'],
                $item['task_id'],
                $item['event_id'],
                $item['project_id']
            );

            $out[] = $item;
        }

        return $out;
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

<?php

namespace App\Services;

use App\DataTransferObjects\Llm\LlmIntentClassificationResult;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;

class LlmIntentClassificationService
{
    private const ENTITY_TASK = [
        'task',
        'todo',
        'work item',
        'action item',
        'tasks',
        'todos',
        'assignment',
        'homework',
        'essay',
        'paper',
        'study session',
        'revision',
        'exam',
        'quiz',
        'test',
        // References to "first in list" or "top task" (e.g. "schedule the top 1 for later", "schedule my top task").
        'top 1',
        'top one',
        'first one',
        'the first',
        'top task',
        'first task',
        'most important task',
        'main task',
    ];

    /** Do not include "schedule" here: as a verb it signals intent, not entity. Otherwise "schedule the top 1" is misclassified as event. */
    private const ENTITY_EVENT = [
        'event',
        'meeting',
        'appointment',
        'calendar',
        'events',
        'meetings',
        'class',
        'lecture',
    ];

    private const ENTITY_PROJECT = [
        'project',
        'initiative',
        'milestone',
        'deliverable',
        'projects',
        'thesis',
        'capstone',
    ];

    /** Event keywords for schedule multi-entity detection only; excludes "schedule" so "schedule tasks and projects" is not treated as tasks+events. */
    private const ENTITY_EVENT_FOR_SCHEDULE_MULTI = [
        'event', 'events', 'meeting', 'meetings', 'appointment', 'calendar', 'class', 'lecture',
    ];

    private const INTENT_RESOLVE_DEPENDENCY = ['blocked', 'waiting', 'depends', 'after', 'blocking', 'dependency'];

    private const INTENT_ADJUST = ['extend', 'move', 'delay', 'push', 'earlier', 'reschedule', 'change time', 'shift', 'timeline', 'postpone', 'push back'];

    private const INTENT_SCHEDULE = [
        'finish',
        'by',
        'deadline',
        'schedule',
        'when',
        'book',
        'set up',
        'plan',
        'start',
        'slot',
        'time',
        'organize',
        'planning',
        'due',
        'remind',
    ];

    /** Phrases for "schedule all" (tasks + events + projects). Checked first under hasSchedule. */
    private const INTENT_SCHEDULE_ALL = [
        'schedule all',
        'schedule everything',
        'schedule all my items',
        'when should i do everything',
        'schedule my tasks events and projects',
        'schedule my tasks events projects',
    ];

    private const INTENT_PRIORITIZE = [
        'priority',
        'prioritize',
        'important',
        'urgent',
        'rank',
        'order',
        'focus',
        'first',
        'next',
        'today',
        'attend',
        'how about',
        'what about',
        'help',
        'help me',
        'assist',
        'support',
    ];

    public function classify(string $userMessage): LlmIntentClassificationResult
    {
        $normalized = $this->normalize($userMessage);
        $entityType = $this->detectEntityType($normalized);
        $intent = $this->detectIntent($normalized, $entityType);

        $multiEntityIntents = [
            LlmIntent::ScheduleTasksAndEvents,
            LlmIntent::ScheduleTasksAndProjects,
            LlmIntent::ScheduleEventsAndProjects,
            LlmIntent::ScheduleAll,
            LlmIntent::PrioritizeTasksAndEvents,
            LlmIntent::PrioritizeTasksAndProjects,
            LlmIntent::PrioritizeEventsAndProjects,
            LlmIntent::PrioritizeAll,
        ];
        if (in_array($intent, $multiEntityIntents, true)) {
            $entityType = LlmEntityType::Multiple;
        }

        $confidence = $this->computeConfidence($normalized, $intent, $entityType);

        return new LlmIntentClassificationResult($intent, $entityType, $confidence);
    }

    private function normalize(string $message): string
    {
        $trimmed = trim($message);
        $lower = mb_strtolower($trimmed);
        $withoutPunctuation = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $lower) ?? $lower;

        return preg_replace('/\s+/', ' ', $withoutPunctuation) ?? $withoutPunctuation;
    }

    private function detectEntityType(string $normalized): LlmEntityType
    {
        $taskScore = $this->countKeywordHits($normalized, self::ENTITY_TASK);
        $eventScore = $this->countKeywordHits($normalized, self::ENTITY_EVENT);
        $projectScore = $this->countKeywordHits($normalized, self::ENTITY_PROJECT);

        if ($projectScore > $taskScore && $projectScore >= $eventScore) {
            return LlmEntityType::Project;
        }
        if ($eventScore > $taskScore && $eventScore >= $projectScore) {
            return LlmEntityType::Event;
        }
        if ($taskScore > 0 || $eventScore > 0 || $projectScore > 0) {
            if ($taskScore >= $eventScore && $taskScore >= $projectScore) {
                return LlmEntityType::Task;
            }
        }

        return LlmEntityType::Task;
    }

    private const INTENT_DELETE_OR_REMOVE = ['delete', 'remove', 'drop', 'get rid of'];

    /** Phrases that ask for a list/filter (e.g. "tasks with low priority", "no due date") → general_query so LLM can use listed_items. */
    private const INTENT_LIST_OR_FILTER = [
        'low prio', 'low priority',
        'no due date', 'no due dates', 'without due date', 'without deadline', 'that have no due', 'with no due date', 'that has no due', 'has no due date',
        'no set dates', 'no dates', 'without dates', 'has no dates', 'that has no set dates', 'that have no set dates', 'with no set dates', 'tasks that has no set dates',
        'list the tasks', 'which tasks have', 'which events have',
        'show me my tasks', 'show me all my tasks', 'show all my tasks', 'show my tasks', 'show tasks', 'show my events', 'show all my events',
    ];

    /**
     * Requests that *sound like list/filter* but are actually prioritization, e.g.
     * "list my top 5 tasks ASAP", "show me the most urgent tasks".
     */
    private const INTENT_LIST_TOP_OR_ASAP = [
        'top',
        'asap',
        'urgent',
        'most urgent',
        'most important',
        'highest priority',
        'need to do asap',
    ];

    /** Meta-questions or complaints about the assistant → general_query so the model can respond conversationally. */
    private const INTENT_META_OR_COMPLAINT = ['why did you not', 'why did you not answer', 'are you hallucinating', 'too complex', 'too hard for you', 'repeat it twice', 'answer it the first time', 'could not produce', 'unavailable'];

    /** Phrases for "prioritize all" (tasks + events + projects). Checked first under hasPrioritize. */
    private const INTENT_PRIORITIZE_ALL = [
        'all my items',
        'all items',
        'all of my',
        'tasks events and projects',
        'tasks events projects',
        'tasks and events and projects',
        'what to do first in all',
        'across all',
        'everything',
    ];

    private function detectIntent(string $normalized, LlmEntityType $entityType): LlmIntent
    {
        if ($this->hasAnyKeyword($normalized, self::INTENT_RESOLVE_DEPENDENCY)) {
            return LlmIntent::ResolveDependency;
        }

        if ($this->hasAnyKeyword($normalized, self::INTENT_META_OR_COMPLAINT)) {
            return LlmIntent::GeneralQuery;
        }

        if ($this->hasAnyKeyword($normalized, self::INTENT_DELETE_OR_REMOVE)
            && $this->hasAnyKeyword($normalized, ['which', 'what', 'should i', 'can i'])) {
            return LlmIntent::GeneralQuery;
        }

        // Whole-prompt scoring: consider all signals, then choose the best intent group.
        // Tie-breaker priority: adjust > schedule > prioritize > general.
        $adjustHits = $this->countKeywordHits($normalized, self::INTENT_ADJUST);
        $scheduleHits = $this->countKeywordHits($normalized, self::INTENT_SCHEDULE);
        $prioritizeHits = $this->countKeywordHits($normalized, self::INTENT_PRIORITIZE);
        $listFilterHits = $this->countKeywordHits($normalized, self::INTENT_LIST_OR_FILTER);
        $isTopAsapList = $this->isTopOrAsapListRequest($normalized);

        $adjustScore = $adjustHits * 10;
        $scheduleScore = ($this->hasAnyKeyword($normalized, ['schedule']) ? 12 : 0) + ($scheduleHits * 8);
        $prioritizeScore = ($prioritizeHits * 6) + ($isTopAsapList ? 14 : 0);
        // List/filter requests should remain GeneralQuery unless schedule/adjust signals are stronger,
        // or the user explicitly asks for a "top/urgent/asap" prioritised list (handled by prioritizeScore).
        $generalScore = $listFilterHits > 0 ? 10 : 0;

        $max = max($adjustScore, $scheduleScore, $prioritizeScore, $generalScore);

        if ($max <= 0) {
            return LlmIntent::GeneralQuery;
        }

        if ($adjustScore === $max) {
            return match ($entityType) {
                LlmEntityType::Task => LlmIntent::AdjustTaskDeadline,
                LlmEntityType::Event => LlmIntent::AdjustEventTime,
                LlmEntityType::Project => LlmIntent::AdjustProjectTimeline,
            };
        }

        if ($scheduleScore === $max) {
            if ($this->hasAnyKeyword($normalized, self::INTENT_SCHEDULE_ALL)) {
                return LlmIntent::ScheduleAll;
            }

            $taskHits = $this->countKeywordHits($normalized, self::ENTITY_TASK);
            $eventHits = $this->countKeywordHits($normalized, self::ENTITY_EVENT_FOR_SCHEDULE_MULTI);
            $projectHits = $this->countKeywordHits($normalized, self::ENTITY_PROJECT);
            $hasBothOrAnd = str_contains($normalized, ' both ') || str_contains($normalized, ' and ');

            if ($taskHits > 0 && $eventHits > 0 && $hasBothOrAnd) {
                return LlmIntent::ScheduleTasksAndEvents;
            }
            if ($taskHits > 0 && $projectHits > 0 && $hasBothOrAnd) {
                return LlmIntent::ScheduleTasksAndProjects;
            }
            if ($eventHits > 0 && $projectHits > 0 && $hasBothOrAnd) {
                return LlmIntent::ScheduleEventsAndProjects;
            }

            return match ($entityType) {
                LlmEntityType::Task => LlmIntent::ScheduleTask,
                LlmEntityType::Event => LlmIntent::ScheduleEvent,
                LlmEntityType::Project => LlmIntent::ScheduleProject,
            };
        }

        if ($prioritizeScore === $max) {
            // "List my top/ASAP/urgent ..." should follow prioritization flow for consistency.
            if ($entityType === LlmEntityType::Task && $isTopAsapList) {
                return LlmIntent::PrioritizeTasks;
            }

            if ($this->hasAnyKeyword($normalized, self::INTENT_PRIORITIZE_ALL)) {
                return LlmIntent::PrioritizeAll;
            }

            $taskHits = $this->countKeywordHits($normalized, self::ENTITY_TASK);
            $eventHits = $this->countKeywordHits($normalized, self::ENTITY_EVENT);
            $projectHits = $this->countKeywordHits($normalized, self::ENTITY_PROJECT);
            $hasBothOrAnd = str_contains($normalized, ' both ') || str_contains($normalized, ' and ');

            if ($taskHits > 0 && $eventHits > 0 && $hasBothOrAnd) {
                return LlmIntent::PrioritizeTasksAndEvents;
            }
            if ($taskHits > 0 && $projectHits > 0 && $hasBothOrAnd) {
                return LlmIntent::PrioritizeTasksAndProjects;
            }
            if ($eventHits > 0 && $projectHits > 0 && $hasBothOrAnd) {
                return LlmIntent::PrioritizeEventsAndProjects;
            }

            return match ($entityType) {
                LlmEntityType::Task => LlmIntent::PrioritizeTasks,
                LlmEntityType::Event => LlmIntent::PrioritizeEvents,
                LlmEntityType::Project => LlmIntent::PrioritizeProjects,
            };
        }

        return LlmIntent::GeneralQuery;
    }

    private function isTopOrAsapListRequest(string $normalized): bool
    {
        // Require task + a "top/urgent/asap" signal + a list/show verb.
        $hasTopSignal = $this->hasAnyKeyword($normalized, self::INTENT_LIST_TOP_OR_ASAP)
            || (bool) preg_match('/\btop\s+\d+\b/', $normalized);

        if (! $hasTopSignal) {
            return false;
        }

        $hasListVerb = $this->hasAnyKeyword($normalized, [
            'list',
            'show',
            'give me',
            'what are',
            'which are',
            'tell me',
        ]);

        // Also treat "tasks i need to do asap" (no explicit list verb) as prioritize.
        $hasNeedToDo = $this->hasAnyKeyword($normalized, ['need to do', 'should i do', 'do next']);

        return $hasListVerb || $hasNeedToDo;
    }

    private function computeConfidence(string $normalized, LlmIntent $intent, LlmEntityType $entityType): float
    {
        $adjustHits = $this->countKeywordHits($normalized, self::INTENT_ADJUST);
        $scheduleHits = $this->countKeywordHits($normalized, self::INTENT_SCHEDULE);
        $prioritizeHits = $this->countKeywordHits($normalized, self::INTENT_PRIORITIZE);

        if (in_array($intent, [LlmIntent::AdjustTaskDeadline, LlmIntent::AdjustEventTime, LlmIntent::AdjustProjectTimeline], true) && $adjustHits > 0) {
            return 0.85;
        }
        if (in_array($intent, [LlmIntent::ScheduleTask, LlmIntent::ScheduleEvent, LlmIntent::ScheduleProject], true)
            && ($this->hasAnyKeyword($normalized, ['schedule']) || $scheduleHits > 0)) {
            return 0.85;
        }
        if (in_array($intent, [LlmIntent::PrioritizeTasks, LlmIntent::PrioritizeEvents, LlmIntent::PrioritizeProjects], true) && $prioritizeHits > 0) {
            return 0.85;
        }

        if ($intent === LlmIntent::PrioritizeTasks && $entityType === LlmEntityType::Task && $this->isTopOrAsapListRequest($normalized)) {
            // Treat "top/ASAP list" as a strong prioritization signal.
            return 0.75;
        }
        if ($intent === LlmIntent::PrioritizeEvents && $entityType === LlmEntityType::Event && $this->isTopOrAsapListRequest($normalized)) {
            return 0.75;
        }
        if ($intent === LlmIntent::PrioritizeProjects && $entityType === LlmEntityType::Project && $this->isTopOrAsapListRequest($normalized)) {
            return 0.75;
        }

        if ($intent === LlmIntent::PrioritizeTasksAndEvents) {
            $taskHits = $this->countKeywordHits($normalized, self::ENTITY_TASK);
            $eventHits = $this->countKeywordHits($normalized, self::ENTITY_EVENT);

            return $taskHits >= 1 && $eventHits >= 1 ? 0.85 : 0.7;
        }
        if ($intent === LlmIntent::PrioritizeTasksAndProjects) {
            $taskHits = $this->countKeywordHits($normalized, self::ENTITY_TASK);
            $projectHits = $this->countKeywordHits($normalized, self::ENTITY_PROJECT);

            return $taskHits >= 1 && $projectHits >= 1 ? 0.85 : 0.7;
        }
        if ($intent === LlmIntent::PrioritizeEventsAndProjects) {
            $eventHits = $this->countKeywordHits($normalized, self::ENTITY_EVENT);
            $projectHits = $this->countKeywordHits($normalized, self::ENTITY_PROJECT);

            return $eventHits >= 1 && $projectHits >= 1 ? 0.85 : 0.7;
        }
        if ($intent === LlmIntent::PrioritizeAll) {
            return $this->hasAnyKeyword($normalized, self::INTENT_PRIORITIZE_ALL) ? 0.85 : 0.7;
        }

        if ($intent === LlmIntent::ScheduleTasksAndEvents) {
            $taskHits = $this->countKeywordHits($normalized, self::ENTITY_TASK);
            $eventHits = $this->countKeywordHits($normalized, self::ENTITY_EVENT);

            return $taskHits >= 1 && $eventHits >= 1 ? 0.85 : 0.7;
        }
        if ($intent === LlmIntent::ScheduleTasksAndProjects) {
            $taskHits = $this->countKeywordHits($normalized, self::ENTITY_TASK);
            $projectHits = $this->countKeywordHits($normalized, self::ENTITY_PROJECT);

            return $taskHits >= 1 && $projectHits >= 1 ? 0.85 : 0.7;
        }
        if ($intent === LlmIntent::ScheduleEventsAndProjects) {
            $eventHits = $this->countKeywordHits($normalized, self::ENTITY_EVENT);
            $projectHits = $this->countKeywordHits($normalized, self::ENTITY_PROJECT);

            return $eventHits >= 1 && $projectHits >= 1 ? 0.85 : 0.7;
        }
        if ($intent === LlmIntent::ScheduleAll) {
            return $this->hasAnyKeyword($normalized, self::INTENT_SCHEDULE_ALL) ? 0.85 : 0.7;
        }

        if ($intent === LlmIntent::GeneralQuery) {
            // List/filter and delete/remove queries are intentionally routed to GeneralQuery and
            // should not trigger LLM fallback, so give them high confidence.
            if ($this->hasAnyKeyword($normalized, self::INTENT_LIST_OR_FILTER)
                || $this->hasAnyKeyword($normalized, self::INTENT_DELETE_OR_REMOVE)) {
                return 0.9;
            }

            return 0.5;
        }

        $tokens = $normalized === '' ? [] : explode(' ', $normalized);
        $tokenCount = count($tokens);

        $entityKeywords = match ($entityType) {
            LlmEntityType::Task => self::ENTITY_TASK,
            LlmEntityType::Event => self::ENTITY_EVENT,
            LlmEntityType::Project => self::ENTITY_PROJECT,
        };
        $entityHits = $this->countKeywordHits($normalized, $entityKeywords);
        $intentKeywords = $this->getIntentKeywords($intent);
        $intentHits = $this->countKeywordHits($normalized, $intentKeywords);

        $base = 0.35;
        $entityScore = min(0.35, $entityHits * 0.1);
        $intentScore = min(0.3, $intentHits * 0.15);

        $score = $base + $entityScore + $intentScore;

        // Penalise conflicting intent signals (e.g. both schedule and adjust language present).
        $adjustHits = $this->countKeywordHits($normalized, self::INTENT_ADJUST);
        $scheduleHits = $this->countKeywordHits($normalized, self::INTENT_SCHEDULE);
        $prioritizeHits = $this->countKeywordHits($normalized, self::INTENT_PRIORITIZE);
        $intentGroupsWithHits = count(array_filter([$adjustHits, $scheduleHits, $prioritizeHits], static fn (int $hits): bool => $hits > 0));

        if ($intentGroupsWithHits > 1) {
            $score -= 0.1;
        }

        // Downweight extremely short messages, which tend to be more ambiguous.
        if ($tokenCount > 0 && $tokenCount < 3) {
            $score -= 0.15;
        }

        // When both entity and intent keywords clearly match, ensure high confidence so LLM fallback is not triggered.
        if ($entityHits >= 1 && $intentHits >= 1 && $score >= 0.5) {
            $score = max($score, 0.85);
        }

        $score = max(0.0, min(1.0, $score));

        return round($score, 2);
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private function hasAnyKeyword(string $normalized, array $keywords): bool
    {
        $tokens = $normalized === '' ? [] : explode(' ', $normalized);

        foreach ($keywords as $keyword) {
            if (str_contains($keyword, ' ')) {
                if (str_contains($normalized, $keyword)) {
                    return true;
                }
            } elseif (in_array($keyword, $tokens, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private function countKeywordHits(string $normalized, array $keywords): int
    {
        $count = 0;
        $tokens = $normalized === '' ? [] : explode(' ', $normalized);

        foreach ($keywords as $keyword) {
            if (str_contains($keyword, ' ')) {
                if (str_contains($normalized, $keyword)) {
                    $count++;
                }
            } elseif (in_array($keyword, $tokens, true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array<int, string>
     */
    private function getIntentKeywords(LlmIntent $intent): array
    {
        return match ($intent) {
            LlmIntent::ResolveDependency => self::INTENT_RESOLVE_DEPENDENCY,
            LlmIntent::AdjustTaskDeadline, LlmIntent::AdjustEventTime, LlmIntent::AdjustProjectTimeline => self::INTENT_ADJUST,
            LlmIntent::ScheduleTask, LlmIntent::ScheduleEvent, LlmIntent::ScheduleProject,
            LlmIntent::ScheduleTasksAndEvents, LlmIntent::ScheduleTasksAndProjects,
            LlmIntent::ScheduleEventsAndProjects, LlmIntent::ScheduleAll => self::INTENT_SCHEDULE,
            LlmIntent::PrioritizeTasks, LlmIntent::PrioritizeEvents, LlmIntent::PrioritizeProjects,
            LlmIntent::PrioritizeTasksAndEvents, LlmIntent::PrioritizeTasksAndProjects,
            LlmIntent::PrioritizeEventsAndProjects, LlmIntent::PrioritizeAll => self::INTENT_PRIORITIZE,
            LlmIntent::GeneralQuery => [],
        };
    }
}

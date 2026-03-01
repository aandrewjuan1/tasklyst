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
    ];

    private const ENTITY_EVENT = [
        'event',
        'meeting',
        'appointment',
        'calendar',
        'schedule',
        'events',
        'meetings',
        'class',
        'lecture',
        'exam',
        'quiz',
        'test',
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
    private const INTENT_LIST_OR_FILTER = ['low prio', 'low priority', 'no due date', 'no due dates', 'without due date', 'without deadline', 'that have no due', 'with no due date', 'that has no due', 'has no due date', 'list the tasks', 'which tasks have', 'which events have'];

    private function detectIntent(string $normalized, LlmEntityType $entityType): LlmIntent
    {
        if ($this->hasAnyKeyword($normalized, self::INTENT_RESOLVE_DEPENDENCY)) {
            return LlmIntent::ResolveDependency;
        }

        if ($this->hasAnyKeyword($normalized, self::INTENT_DELETE_OR_REMOVE)
            && $this->hasAnyKeyword($normalized, ['which', 'what', 'should i', 'can i'])) {
            return LlmIntent::GeneralQuery;
        }

        if ($this->hasAnyKeyword($normalized, self::INTENT_LIST_OR_FILTER)) {
            return LlmIntent::GeneralQuery;
        }

        $hasAdjust = $this->hasAnyKeyword($normalized, self::INTENT_ADJUST);
        $hasSchedule = $this->hasAnyKeyword($normalized, self::INTENT_SCHEDULE);
        $hasPrioritize = $this->hasAnyKeyword($normalized, self::INTENT_PRIORITIZE);

        if ($hasAdjust) {
            return match ($entityType) {
                LlmEntityType::Task => LlmIntent::AdjustTaskDeadline,
                LlmEntityType::Event => LlmIntent::AdjustEventTime,
                LlmEntityType::Project => LlmIntent::AdjustProjectTimeline,
            };
        }

        if ($hasSchedule) {
            return match ($entityType) {
                LlmEntityType::Task => LlmIntent::ScheduleTask,
                LlmEntityType::Event => LlmIntent::ScheduleEvent,
                LlmEntityType::Project => LlmIntent::ScheduleProject,
            };
        }

        if ($hasPrioritize) {
            return match ($entityType) {
                LlmEntityType::Task => LlmIntent::PrioritizeTasks,
                LlmEntityType::Event => LlmIntent::PrioritizeEvents,
                LlmEntityType::Project => LlmIntent::PrioritizeProjects,
            };
        }

        return LlmIntent::GeneralQuery;
    }

    private function computeConfidence(string $normalized, LlmIntent $intent, LlmEntityType $entityType): float
    {
        if ($intent === LlmIntent::GeneralQuery) {
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
            LlmIntent::ScheduleTask, LlmIntent::ScheduleEvent, LlmIntent::ScheduleProject => self::INTENT_SCHEDULE,
            LlmIntent::PrioritizeTasks, LlmIntent::PrioritizeEvents, LlmIntent::PrioritizeProjects => self::INTENT_PRIORITIZE,
            LlmIntent::GeneralQuery => [],
        };
    }
}

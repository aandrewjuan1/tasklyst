<?php

namespace App\Services;

use App\DataTransferObjects\Llm\LlmIntentClassificationResult;
use App\Enums\LlmEntityType;
use App\Enums\LlmOperationMode;
use App\Services\Llm\LlmIntentAliasResolver;

class LlmIntentClassificationService
{
    private const ENTITY_TASK = [
        'task', 'tasks', 'todo', 'todos', 'assignment', 'homework', 'quiz', 'exam', 'test', 'study',
        'top task', 'first task', 'top 1', 'top one',
    ];

    private const ENTITY_EVENT = [
        'event', 'events', 'meeting', 'meetings', 'calendar', 'appointment', 'class', 'lecture',
    ];

    private const ENTITY_PROJECT = [
        'project', 'projects', 'initiative', 'milestone', 'deliverable', 'thesis', 'capstone',
    ];

    private const INTENT_RESOLVE_DEPENDENCY = ['blocked', 'waiting', 'depends', 'blocking', 'dependency'];

    private const INTENT_CREATE = ['create', 'add', 'new'];

    private const INTENT_UPDATE = ['update', 'change', 'rename', 'edit', 'set', 'make'];

    private const INTENT_ADJUST = ['move', 'push', 'delay', 'postpone', 'earlier', 'reschedule', 'extend', 'timeline'];

    private const INTENT_SCHEDULE = ['schedule', 'reschedule', 'plan', 'time block', 'slot', 'when', 'deadline', 'move', 'push', 'postpone', 'delay'];

    private const INTENT_PRIORITIZE = ['prioritize', 'prioritise', 'priority', 'rank', 'important', 'urgent', 'top', 'asap', 'focus', 'first', 'next', 'how about', 'what about'];

    private const INTENT_DELETE_OR_REMOVE = ['delete', 'remove', 'drop', 'get rid of'];

    private const INTENT_LIST_OR_FILTER = [
        'list', 'show', 'what are', 'search', 'find', 'filter',
        'events only', 'tasks only', 'projects only',
        'related to', 'tagged as', 'tagged',
        'next 7 days', 'within the next 7 days', 'coming up',
        'no due date', 'without due date', 'no set dates', 'without dates', 'low priority',
    ];

    private const INTENT_META_OR_COMPLAINT = ['why did you not', 'why did you not answer', 'are you hallucinating', 'too complex', 'too hard for you', 'repeat it twice', 'answer it the first time', 'could not produce', 'unavailable'];

    public function __construct(
        private LlmIntentAliasResolver $aliasResolver,
    ) {}

    public function classify(string $userMessage): LlmIntentClassificationResult
    {
        $normalized = $this->normalize($userMessage);
        $entityTargets = $this->detectEntityTargets($normalized);
        $operationMode = $this->detectOperationMode($normalized);
        $entityScope = count($entityTargets) > 1 ? LlmEntityType::Multiple : ($entityTargets[0] ?? LlmEntityType::Task);
        if ($operationMode === LlmOperationMode::Schedule
            && $this->isTimeWindowScheduleRequest($normalized)
            && $entityScope === LlmEntityType::Task) {
            $entityScope = LlmEntityType::Multiple;
        }
        if ($operationMode === LlmOperationMode::Schedule
            && $this->isMultiTargetScheduleRequest($normalized)
            && $entityScope === LlmEntityType::Task) {
            $entityScope = LlmEntityType::Multiple;
        }
        $adjustLike = $this->hasAnyKeyword($normalized, self::INTENT_ADJUST);
        $intent = $this->aliasResolver->resolve($operationMode, $entityScope, $entityTargets, $adjustLike);
        $confidence = $this->computeConfidence($normalized, $operationMode, $entityScope, $entityTargets);

        return new LlmIntentClassificationResult(
            intent: $intent,
            entityType: $entityScope,
            confidence: $confidence,
            operationMode: $operationMode,
            entityTargets: $entityTargets,
        );
    }

    private function normalize(string $message): string
    {
        $trimmed = trim($message);
        $lower = mb_strtolower($trimmed);
        $withoutPunctuation = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $lower) ?? $lower;

        return preg_replace('/\s+/', ' ', $withoutPunctuation) ?? $withoutPunctuation;
    }

    /**
     * @return array<int, LlmEntityType>
     */
    private function detectEntityTargets(string $normalized): array
    {
        $taskScore = $this->countKeywordHits($normalized, self::ENTITY_TASK);
        $eventScore = $this->countKeywordHits($normalized, self::ENTITY_EVENT);
        $projectScore = $this->countKeywordHits($normalized, self::ENTITY_PROJECT);

        $targets = [];
        if ($taskScore > 0) {
            $targets[] = LlmEntityType::Task;
        }
        if ($eventScore > 0) {
            $targets[] = LlmEntityType::Event;
        }
        if ($projectScore > 0) {
            $targets[] = LlmEntityType::Project;
        }

        if ($targets === []) {
            $targets[] = LlmEntityType::Task;
        }

        if (str_contains($normalized, 'all my items')
            || str_contains($normalized, 'all items')
            || str_contains($normalized, 'schedule everything')
            || str_contains($normalized, 'prioritize everything')
            || str_contains($normalized, 'tasks events and projects')
            || str_contains($normalized, 'across all')) {
            return [LlmEntityType::Task, LlmEntityType::Event, LlmEntityType::Project];
        }

        return $targets;
    }

    private function detectOperationMode(string $normalized): LlmOperationMode
    {
        if ($this->hasAnyKeyword($normalized, self::INTENT_META_OR_COMPLAINT)) {
            return LlmOperationMode::General;
        }

        if ($this->hasAnyKeyword($normalized, self::INTENT_RESOLVE_DEPENDENCY)) {
            return LlmOperationMode::ResolveDependency;
        }

        if ($this->hasAnyKeyword($normalized, self::INTENT_CREATE)
            && ! $this->hasAnyKeyword($normalized, self::INTENT_SCHEDULE)) {
            return LlmOperationMode::Create;
        }

        if ($this->hasAnyKeyword($normalized, self::INTENT_UPDATE)
            && ! $this->hasAnyKeyword($normalized, self::INTENT_SCHEDULE)) {
            return LlmOperationMode::Update;
        }

        if ($this->isTimeWindowScheduleRequest($normalized)
            || $this->hasAnyKeyword($normalized, self::INTENT_SCHEDULE)
            || $this->hasAnyKeyword($normalized, self::INTENT_ADJUST)) {
            return LlmOperationMode::Schedule;
        }

        if ($this->isTopNPrioritizeRequest($normalized)) {
            return LlmOperationMode::Prioritize;
        }

        if ($this->hasAnyKeyword($normalized, self::INTENT_PRIORITIZE) && ! $this->isListFilterSearchRequest($normalized)) {
            return LlmOperationMode::Prioritize;
        }

        if ($this->isListFilterSearchRequest($normalized)) {
            return LlmOperationMode::ListFilterSearch;
        }

        if ($this->hasAnyKeyword($normalized, self::INTENT_DELETE_OR_REMOVE)
            || $this->hasAnyKeyword($normalized, self::INTENT_LIST_OR_FILTER)) {
            return LlmOperationMode::General;
        }

        return LlmOperationMode::General;
    }

    private function isListFilterSearchRequest(string $normalized): bool
    {
        if ($this->hasAnyKeyword($normalized, self::INTENT_DELETE_OR_REMOVE)) {
            return false;
        }

        return $this->hasAnyKeyword($normalized, self::INTENT_LIST_OR_FILTER);
    }

    private function isTopNPrioritizeRequest(string $normalized): bool
    {
        if ($normalized === '') {
            return false;
        }

        // top 5, top 3, top 1, top one, etc.
        if (preg_match('/\btop\s+(?:\d+|one)\b/u', $normalized) === 1) {
            return true;
        }

        // phrases like \"top tasks\" / \"top task\" combined with a small integer elsewhere
        if (preg_match('/\btop\s+(task|tasks)\b/u', $normalized) === 1
            && preg_match('/\b[2-9]\b/u', $normalized) === 1) {
            return true;
        }

        return false;
    }

    private function isTimeWindowScheduleRequest(string $normalized): bool
    {
        $timeToken = '\d{1,2}(?:(?::|\s)\d{2})?\s*(?:am|pm)?';
        $hasFromTo = (bool) preg_match('/\bfrom\s+'.$timeToken.'\s+to\s+'.$timeToken.'\b/', $normalized);
        $hasBetweenAnd = (bool) preg_match('/\bbetween\s+'.$timeToken.'\s+and\s+'.$timeToken.'\b/', $normalized);

        return ($hasFromTo || $hasBetweenAnd)
            && (str_contains($normalized, 'plan') || str_contains($normalized, 'schedule'));
    }

    private function isMultiTargetScheduleRequest(string $normalized): bool
    {
        if ($normalized === '') {
            return false;
        }

        $hasPronoun = str_contains($normalized, 'those')
            || str_contains($normalized, 'these')
            || str_contains($normalized, 'them all')
            || str_contains($normalized, 'all of them');

        if (! $hasPronoun) {
            return false;
        }

        $hasScheduleLike = str_contains($normalized, 'schedule')
            || str_contains($normalized, 'plan')
            || str_contains($normalized, 'spread')
            || str_contains($normalized, 'across tonight')
            || str_contains($normalized, 'across tomorrow');

        return $hasScheduleLike;
    }

    /**
     * @param  array<int, LlmEntityType>  $targets
     */
    private function computeConfidence(string $normalized, LlmOperationMode $mode, LlmEntityType $scope, array $targets): float
    {
        $tokenCount = count($normalized === '' ? [] : explode(' ', $normalized));
        $entitySignal = $targets === [] ? 0.0 : 0.2;
        $modeSignal = $mode === LlmOperationMode::General ? 0.2 : 0.35;
        $multiSignal = $scope === LlmEntityType::Multiple ? 0.1 : 0.0;
        $score = 0.35 + $entitySignal + $modeSignal + $multiSignal;

        if ($tokenCount < 3) {
            $score -= 0.15;
        }

        if ($this->hasAnyKeyword($normalized, self::INTENT_LIST_OR_FILTER)
            || $this->hasAnyKeyword($normalized, self::INTENT_DELETE_OR_REMOVE)) {
            $score = max($score, 0.9);
        }

        return round(max(0.3, min(0.95, $score)), 2);
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
}

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

        // For prioritize intents, we treat the model's ordering as source-of-truth,
        // but we still apply narrow narrative corrections when Context facts are available
        // (e.g. bind "tomorrow" / ambiguous weekday-only times to the canonical due datetime).
        if ($intent === LlmIntent::PrioritizeTasks && $entityType === LlmEntityType::Task) {
            [$recommendedAction, $reasoning] = $this->enforcePrioritizeTasksNarrativeConsistency(
                $recommendedAction,
                $reasoning,
                $structured,
                $result->contextFacts
            );
        }
        if ($intent === LlmIntent::PrioritizeEvents && $entityType === LlmEntityType::Event) {
            [$recommendedAction, $reasoning] = $this->enforcePrioritizeEventsNarrativeConsistency(
                $recommendedAction,
                $reasoning,
                $structured,
                $result->contextFacts
            );
        }
        if ($intent === LlmIntent::PrioritizeProjects && $entityType === LlmEntityType::Project) {
            [$recommendedAction, $reasoning] = $this->enforcePrioritizeProjectsNarrativeConsistency(
                $recommendedAction,
                $reasoning,
                $structured,
                $result->contextFacts
            );
        }
        if (in_array($intent, [
            LlmIntent::PrioritizeTasksAndEvents,
            LlmIntent::PrioritizeTasksAndProjects,
            LlmIntent::PrioritizeEventsAndProjects,
            LlmIntent::PrioritizeAll,
        ], true) && $entityType === LlmEntityType::Multiple) {
            [$recommendedAction, $reasoning] = $this->enforcePrioritizeMultiNarrativeConsistency(
                $recommendedAction,
                $reasoning,
                $structured,
                $result->contextFacts
            );
        }

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

        [$actionForDisplay, $reasoningForDisplay] = $this->applyFilterFirstNarrativeTone(
            $actionForDisplay,
            $reasoningForDisplay,
            $entityType,
            $result->contextFacts
        );

        $actionForDisplay = $this->sanitizeInternalKeyNamesForUsers($actionForDisplay);
        $reasoningForDisplay = $this->sanitizeInternalKeyNamesForUsers($reasoningForDisplay);

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

        $rankedLines = $this->formatRankedListForMessage($structured, $intent);
        $nextStepsLines = $this->formatNextStepsForMessage($structured);
        $message = $this->buildMessage($actionForDisplay, $reasoningForDisplay, $listedItems, $rankedLines, $nextStepsLines);
        $displayStructured = $this->sanitizeStructuredForDisplay($structured, $intent);

        // Persist the corrected narrative in structured so snapshots remain internally consistent.
        if (in_array($intent, [LlmIntent::PrioritizeTasks, LlmIntent::PrioritizeEvents, LlmIntent::PrioritizeProjects], true)) {
            $displayStructured['recommended_action'] = $actionForDisplay;
            $displayStructured['reasoning'] = $reasoningForDisplay;
        }

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

    /**
     * Inject filter-first acknowledgement when context indicates filters were applied.
     * Keeps responses human while ensuring users understand why results are scoped.
     *
     * @param  array<string, mixed>|null  $contextFacts
     * @return array{0: string, 1: string}
     */
    private function applyFilterFirstNarrativeTone(
        string $recommendedAction,
        string $reasoning,
        LlmEntityType $entityType,
        ?array $contextFacts
    ): array {
        if (! is_array($contextFacts)) {
            return [$recommendedAction, $reasoning];
        }

        $summary = $contextFacts['filtering_summary'] ?? null;
        if (! is_array($summary) || ! (($summary['applied'] ?? false) === true)) {
            return [$recommendedAction, $reasoning];
        }

        $combinedText = mb_strtolower(trim($recommendedAction.' '.$reasoning));
        if (str_contains($combinedText, 'filtered')
            || str_contains($combinedText, 'matching that request')
            || str_contains($combinedText, 'based on your request')
        ) {
            return [$recommendedAction, $reasoning];
        }

        $counts = is_array($summary['counts'] ?? null) ? $summary['counts'] : [];
        $taskCount = is_numeric($counts['tasks'] ?? null) ? (int) $counts['tasks'] : 0;
        $eventCount = is_numeric($counts['events'] ?? null) ? (int) $counts['events'] : 0;
        $projectCount = is_numeric($counts['projects'] ?? null) ? (int) $counts['projects'] : 0;
        $dimensions = is_array($summary['dimensions'] ?? null) ? $summary['dimensions'] : [];
        $dimensionLabel = $this->humanizedFilterDimensions($dimensions);

        $matchSummary = match ($entityType) {
            LlmEntityType::Task => __('I found :count matching tasks.', ['count' => $taskCount]),
            LlmEntityType::Event => __('I found :count matching events.', ['count' => $eventCount]),
            LlmEntityType::Project => __('I found :count matching projects.', ['count' => $projectCount]),
            LlmEntityType::Multiple => __('I found :tasks tasks, :events events, and :projects projects that match.', [
                'tasks' => $taskCount,
                'events' => $eventCount,
                'projects' => $projectCount,
            ]),
        };

        $filterLead = __('Based on your request, I filtered your items using :dimensions. :matches', [
            'dimensions' => $dimensionLabel,
            'matches' => $matchSummary,
        ]);

        $actionOut = trim($filterLead.' '.$recommendedAction);
        if ($reasoning !== '') {
            $reasoningOut = trim(__('I ranked only this filtered set so your next step stays aligned with what you asked for.').' '.$reasoning);
        } else {
            $reasoningOut = __('I ranked only this filtered set so your next step stays aligned with what you asked for.');
        }

        return [$actionOut, $reasoningOut];
    }

    /**
     * @param  array<int, mixed>  $dimensions
     */
    private function humanizedFilterDimensions(array $dimensions): string
    {
        if ($dimensions === []) {
            return 'your criteria';
        }

        $labels = [];
        foreach ($dimensions as $dimension) {
            if (! is_string($dimension) || trim($dimension) === '') {
                continue;
            }

            $normalized = trim(mb_strtolower($dimension));
            $labels[] = match ($normalized) {
                'required_tag', 'excluded_tag' => 'tag',
                'task_priority' => 'priority',
                'task_status' => 'status',
                'task_complexity' => 'complexity',
                'task_recurring' => 'recurrence',
                'task_due_date_presence' => 'due date availability',
                'task_start_date_presence' => 'start date availability',
                'health_or_household_only' => 'health or household category',
                'school_only' => 'school category',
                'time_window' => 'time window',
                'subject' => 'subject',
                default => str_replace('_', ' ', $normalized),
            };
        }

        $labels = array_values(array_unique($labels));

        return $labels !== [] ? implode(', ', $labels) : 'your criteria';
    }

    private function sanitizeInternalKeyNamesForUsers(string $text): string
    {
        if (trim($text) === '') {
            return $text;
        }

        $map = [
            'required_tag' => 'tag',
            'excluded_tag' => 'tag exclusion',
            'task_priority' => 'task priority',
            'task_status' => 'task status',
            'task_complexity' => 'task complexity',
            'task_recurring' => 'task recurrence',
            'task_due_date_presence' => 'task due date availability',
            'task_start_date_presence' => 'task start date availability',
            'school_only' => 'school category',
            'health_or_household_only' => 'health or household category',
            'time_window' => 'time window',
            'filtering_summary' => 'filter summary',
            'response_style' => 'response style',
            'ranked_tasks' => 'ranked tasks',
            'ranked_events' => 'ranked events',
            'ranked_projects' => 'ranked projects',
            'scheduled_tasks' => 'scheduled tasks',
            'scheduled_events' => 'scheduled events',
            'scheduled_projects' => 'scheduled projects',
            'listed_items' => 'matching items',
            'proposed_properties' => 'suggested changes',
            'appliable_changes' => 'suggested changes',
            'validation_confidence' => 'confidence',
            'entity_type' => 'entity type',
            'start_datetime' => 'start time',
            'end_datetime' => 'end time',
            'startDatetime' => 'start time',
            'endDatetime' => 'end time',
            'tagNames' => 'tags',
        ];

        $out = $text;
        foreach ($map as $raw => $label) {
            $out = preg_replace('/\b'.preg_quote($raw, '/').'\b/u', $label, $out) ?? $out;
        }

        // Hide internal IDs like "ID: 9" or "(ID: 9)" from user-facing narrative.
        $out = preg_replace('/\s*\(ID:\s*\d+\)/iu', '', $out) ?? $out;
        $out = preg_replace('/\bID\s*[:#]\s*\d+\b/iu', '', $out) ?? $out;

        // Normalise whitespace after removals.
        $out = preg_replace('/\s{2,}/u', ' ', $out) ?? $out;

        return trim($out);
    }

    /**
     * Ensure prioritize-tasks narrative does not contradict context-derived facts.
     *
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>|null  $contextFacts
     * @return array{0: string, 1: string} [recommended_action, reasoning]
     */
    private function enforcePrioritizeTasksNarrativeConsistency(
        string $recommendedAction,
        string $reasoning,
        array $structured,
        ?array $contextFacts
    ): array {
        if (! is_array($contextFacts)) {
            return [$recommendedAction, $reasoning];
        }

        $taskFactsByTitle = $contextFacts['task_facts_by_title'] ?? null;
        if (! is_array($taskFactsByTitle) || $taskFactsByTitle === []) {
            return [$recommendedAction, $reasoning];
        }

        $ranked = $structured['ranked_tasks'] ?? null;
        if (! is_array($ranked) || $ranked === []) {
            return [$recommendedAction, $reasoning];
        }

        $timezone = is_string($contextFacts['timezone'] ?? null) && trim((string) $contextFacts['timezone']) !== ''
            ? (string) $contextFacts['timezone']
            : config('app.timezone');

        $contradiction = $this->prioritizeTasksNarrativeHasContradiction(
            $recommendedAction.' '.$reasoning,
            $ranked,
            $taskFactsByTitle,
            $timezone,
            isset($contextFacts['current_time']) && is_string($contextFacts['current_time']) ? $contextFacts['current_time'] : null
        );
        if (! $contradiction) {
            return [$recommendedAction, $reasoning];
        }

        return $this->correctPrioritizeTasksNarrativeFacts(
            $recommendedAction,
            $reasoning,
            $ranked,
            $taskFactsByTitle,
            $timezone
        );
    }

    /**
     * Keep the LLM's tone, but correct factual phrases (today/tomorrow/overdue + duration claims).
     *
     * @param  array<int, mixed>  $ranked
     * @param  array<string, mixed>  $taskFactsByTitle
     * @return array{0: string, 1: string}
     */
    private function correctPrioritizeTasksNarrativeFacts(
        string $recommendedAction,
        string $reasoning,
        array $ranked,
        array $taskFactsByTitle,
        string $timezone
    ): array {
        $topTitle = isset($ranked[0]['title']) && is_string($ranked[0]['title']) ? trim($ranked[0]['title']) : '';

        $fullText = $recommendedAction.' '.$reasoning;
        $anchorTitle = $this->firstRankedTaskTitleMentionedInText($ranked, $fullText) ?? $topTitle;

        // Keep the "first focus" narrative aligned with the current top-ranked task,
        // including paraphrased mentions.
        $recommendedAction = $this->alignRecommendedActionWithTopRankedLabel($recommendedAction, $ranked, 'title');

        $anchorEnd = null;
        foreach ($ranked as $item) {
            if (! is_array($item) || ! isset($item['title']) || ! is_string($item['title'])) {
                continue;
            }
            if (trim($item['title']) === $anchorTitle) {
                $anchorEnd = isset($item['end_datetime']) && is_string($item['end_datetime']) ? trim($item['end_datetime']) : null;
                break;
            }
        }

        $anchorFacts = $anchorTitle !== '' ? ($taskFactsByTitle[$anchorTitle] ?? null) : null;
        $topFacts = $topTitle !== '' ? ($taskFactsByTitle[$topTitle] ?? null) : null;

        $anchorDuration = is_array($anchorFacts) && isset($anchorFacts['duration']) && is_numeric($anchorFacts['duration'])
            ? (int) $anchorFacts['duration']
            : null;
        $anchorPriority = is_array($anchorFacts) && isset($anchorFacts['priority']) && is_string($anchorFacts['priority']) && trim($anchorFacts['priority']) !== ''
            ? strtolower(trim($anchorFacts['priority']))
            : null;

        $topPriority = is_array($topFacts) && isset($topFacts['priority']) && is_string($topFacts['priority']) && trim($topFacts['priority']) !== ''
            ? strtolower(trim($topFacts['priority']))
            : null;

        $anchorDueString = null;
        if (is_string($anchorEnd) && $anchorEnd !== '') {
            try {
                $anchorDueString = Carbon::parse($anchorEnd)->setTimezone($timezone)->toDayDateTimeString();
            } catch (\Throwable) {
                $anchorDueString = null;
            }
        }

        $secondDueString = null;
        if (isset($ranked[1]) && is_array($ranked[1]) && isset($ranked[1]['end_datetime']) && is_string($ranked[1]['end_datetime'])) {
            $secondEnd = trim($ranked[1]['end_datetime']);
            if ($secondEnd !== '') {
                try {
                    $secondDueString = Carbon::parse($secondEnd)->setTimezone($timezone)->toDayDateTimeString();
                } catch (\Throwable) {
                    $secondDueString = null;
                }
            }
        }

        $prioritySummary = $this->prioritySummaryForRankedTasks($ranked, $taskFactsByTitle);
        $topIsEarliestDue = $this->topRankedTaskIsEarliestDue($ranked);

        $fix = function (string $text) use ($anchorTitle, $anchorDueString, $secondDueString, $anchorDuration, $prioritySummary, $anchorPriority, $topIsEarliestDue): string {
            $out = $text;

            // If the model states an explicit wrong due date for the anchor task in the same sentence,
            // replace it with the canonical due datetime.
            if ($anchorDueString !== null && $anchorTitle !== '') {
                $quotedTitle = preg_quote($anchorTitle, '/');
                $out = preg_replace(
                    '/('.$quotedTitle.'[^.?!]*?\bdue\s+)([A-Za-z]{3},\s+[A-Za-z]{3}\s+\d{1,2},\s+\d{4}(?:\s+\d{1,2}:\d{2}\s*[AP]M)?)\b/i',
                    '${1}'.$anchorDueString,
                    $out
                ) ?? $out;
            }

            // Replace relative deadline phrasing with explicit due date when we have it.
            if ($anchorDueString !== null) {
                // Phrases like "11:59 PM Friday" (weekday without date) are too ambiguous; bind to canonical due string.
                $out = preg_replace('/\b\d{1,2}:\d{2}\s*[AP]M\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', $anchorDueString, $out) ?? $out;

                // If the model states a bare date without time (e.g., "due Sat, Mar 14, 2026"),
                // bind it to the anchor due datetime to avoid incorrect day/date claims.
                $out = preg_replace(
                    '/\bdue\s+([A-Za-z]{3},\s+[A-Za-z]{3}\s+\d{1,2},\s+\d{4})\b(?!\s+\d{1,2}:\d{2})/i',
                    'due '.$anchorDueString,
                    $out
                ) ?? $out;

                $out = preg_replace('/\bdue\s+today\b/i', 'due '.$anchorDueString, $out) ?? $out;
                $out = preg_replace('/\bdue\s+tomorrow\b/i', 'due '.$anchorDueString, $out) ?? $out;
                $out = preg_replace('/\bdeadline\s+of\s+tomorrow\b/i', 'deadline of '.$anchorDueString, $out) ?? $out;
                $out = preg_replace('/\bby\s+tomorrow\b/i', 'by '.$anchorDueString, $out) ?? $out;
                $out = preg_replace('/\btomorrow\b/i', $anchorDueString, $out) ?? $out;
                $out = preg_replace('/\bend\s+of\s+today\b/i', $anchorDueString, $out) ?? $out;
                $out = preg_replace('/\bbefore\s+the\s+end\s+of\s+today\b/i', 'before '.$anchorDueString, $out) ?? $out;
            } else {
                // If we cannot safely bind to a date, remove relative phrases rather than risk misinformation.
                $out = preg_replace('/\b(due\s+today|due\s+tomorrow|end\s+of\s+today|before\s+the\s+end\s+of\s+today)\b/i', '', $out) ?? $out;
            }

            // If we know the top task is not overdue, strip "overdue" language.
            $out = preg_replace('/\boverdue\b/i', 'due soon', $out) ?? $out;

            // If the LLM says "urgent" but the actual priority isn't urgent, use the real label.
            if ($anchorPriority !== null && $anchorPriority !== 'urgent') {
                $replacement = $anchorPriority === 'high' ? 'high-priority' : ($anchorPriority === 'medium' ? 'medium-priority' : ($anchorPriority === 'low' ? 'low-priority' : 'important'));
                $out = preg_replace('/\burgent\b/i', $replacement, $out) ?? $out;
            }

            // Clean up malformed 24h+AM/PM mixes like "at 23:59 PM" or "23:59 PM".
            $out = preg_replace('/\bat\s*(?:[01]?\d|2[0-3]):[0-5]\d\s*[ap]\.?m\.?\b/i', '', $out) ?? $out;
            $out = preg_replace('/\b(?:[01]?\d|2[0-3]):[0-5]\d\s*[ap]\.?m\.?\b/i', '', $out) ?? $out;

            // If the model used vague future phrasing like "next Friday", bind it to the actual #2 due date when available.
            if ($secondDueString !== null) {
                $out = preg_replace('/\bnext\s+friday\b/i', $secondDueString, $out) ?? $out;
            }

            // If the model says the next item has "no deadline yet", but it does, bind to #2 due date.
            if ($secondDueString !== null) {
                $out = preg_replace('/\b(has\s+)?no\s+(deadline|due\s+date)\s+yet\b/i', 'is due '.$secondDueString, $out) ?? $out;
                $out = preg_replace('/\bno\s+(deadline|due\s+date)\s+yet\b/i', 'due '.$secondDueString, $out) ?? $out;
            }

            // Replace concrete duration mentions with canonical minutes for the top task when present.
            if ($anchorDuration !== null && $anchorDuration > 0) {
                $out = preg_replace('/\babout\s+\d+\s*(hours?|hrs?|minutes?|mins?)\b/i', '~'.$anchorDuration.' min', $out) ?? $out;
                $out = preg_replace('/\b(\d+)\s*(hours?|hrs?)\b/i', '~'.$anchorDuration.' min', $out) ?? $out;
                $out = preg_replace('/\b(\d+)\s*(minutes?|mins?)\b/i', '~'.$anchorDuration.' min', $out) ?? $out;
            }

            // Fix common priority phrasing drift (e.g. "both medium priority") using context-backed facts.
            if ($prioritySummary !== null) {
                $out = preg_replace('/\bboth\s+medium\s+priority\b/i', $prioritySummary, $out) ?? $out;
            }

            if (! $topIsEarliestDue) {
                $out = preg_replace('/\bit\'?s\s+due\s+first\b/i', 'It’s worth doing first because it’s a time-bound assessment and it’s high-impact, even if another item is due sooner.', $out) ?? $out;
                $out = preg_replace(
                    '/\bit\s+is\s+also\s+due\s+first\s+among\s+these\s+tasks\b\.?/i',
                    'It’s worth doing first because it’s a time-bound assessment and it’s high-impact, even if another item is due sooner.',
                    $out
                ) ?? $out;
            }

            // Normalize whitespace and remove spaces before punctuation.
            $out = (string) preg_replace('/\s{2,}/', ' ', $out);
            $out = (string) preg_replace('/\s+([.,;:])/', '${1}', $out);

            return trim($out);
        };

        $actionOut = $fix($recommendedAction);
        $reasonOut = $fix($reasoning);

        // Preserve LLM voice, but add a tiny factual anchor if we had to edit a lot.
        if ($anchorTitle !== '' && $anchorDueString !== null && (mb_stripos($actionOut.' '.$reasonOut, $anchorDueString) === false)) {
            $reasonOut = trim($reasonOut);
            $reasonOut .= ($reasonOut !== '' ? ' ' : '').__('(Quick check: ":title" is due :date.)', [
                'title' => $anchorTitle,
                'date' => $anchorDueString,
            ]);
        }

        return [$actionOut, $reasonOut];
    }

    /**
     * @param  array<int, mixed>  $ranked
     */
    private function firstRankedTaskTitleMentionedInText(array $ranked, string $text): ?string
    {
        $haystack = mb_strtolower($text);
        foreach ($ranked as $item) {
            if (! is_array($item) || ! isset($item['title']) || ! is_string($item['title'])) {
                continue;
            }
            $title = trim($item['title']);
            if ($title === '') {
                continue;
            }
            if (str_contains($haystack, mb_strtolower($title))) {
                return $title;
            }
        }

        return null;
    }

    /**
     * If recommended_action mentions a non-top ranked label and does not mention
     * the top-ranked label, replace the first such mention with the top label.
     *
     * @param  array<int, mixed>  $ranked
     */
    private function alignRecommendedActionWithTopRankedLabel(string $recommendedAction, array $ranked, string $labelKey): string
    {
        $topLabel = isset($ranked[0][$labelKey]) && is_string($ranked[0][$labelKey])
            ? trim($ranked[0][$labelKey])
            : '';
        if ($topLabel === '' || $this->textLikelyMentionsLabel($recommendedAction, $topLabel)) {
            return $recommendedAction;
        }

        foreach ($ranked as $item) {
            if (! is_array($item) || ! isset($item[$labelKey]) || ! is_string($item[$labelKey])) {
                continue;
            }
            $candidate = trim($item[$labelKey]);
            if ($candidate === '' || $candidate === $topLabel) {
                continue;
            }
            if ($this->textLikelyMentionsLabel($recommendedAction, $candidate)) {
                // If the non-top item is mentioned using paraphrased wording
                // (e.g. "Lab 5: Linked Lists for CS 220"), directly enforce
                // first-focus alignment by rewriting the first sentence.
                $rewritten = preg_replace(
                    '/^[^.!?]*(?:[.!?]\s*)?/u',
                    'First, focus on completing '.$topLabel.'. ',
                    trim($recommendedAction),
                    1
                );

                return is_string($rewritten) && trim($rewritten) !== ''
                    ? trim($rewritten)
                    : 'First, focus on completing '.$topLabel.'.';
            }
        }

        return $recommendedAction;
    }

    private function textLikelyMentionsLabel(string $text, string $label): bool
    {
        $normalizedText = $this->normalizeNarrativeTextForMatching($text);
        $normalizedLabel = $this->normalizeNarrativeTextForMatching($label);
        if ($normalizedText === '' || $normalizedLabel === '') {
            return false;
        }

        // Fast exact-ish check first.
        if (str_contains($normalizedText, $normalizedLabel)) {
            return true;
        }

        // Fallback token-overlap match to catch paraphrases like
        // "Lab 5: Linked Lists for CS 220" vs "CS 220 – Lab 5: Linked Lists".
        $textWords = array_values(array_filter(explode(' ', $normalizedText), static fn (string $word): bool => $word !== ''));
        $labelWords = array_values(array_filter(explode(' ', $normalizedLabel), static fn (string $word): bool => $word !== ''));
        if ($textWords === [] || $labelWords === []) {
            return false;
        }

        $textSet = array_fill_keys($textWords, true);
        $matches = 0;
        foreach ($labelWords as $word) {
            if (isset($textSet[$word])) {
                $matches++;
            }
        }

        // Require strong overlap and at least 3 matched words to avoid false positives.
        return $matches >= 3 && ($matches / count($labelWords)) >= 0.6;
    }

    private function normalizeNarrativeTextForMatching(string $value): string
    {
        $lower = mb_strtolower(trim($value));
        $alnumSpace = (string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $lower);

        return trim((string) preg_replace('/\s+/u', ' ', $alnumSpace));
    }

    /**
     * Apply task/event/project narrative corrections for multi-entity prioritize intents.
     *
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>|null  $contextFacts
     * @return array{0: string, 1: string}
     */
    private function enforcePrioritizeMultiNarrativeConsistency(
        string $recommendedAction,
        string $reasoning,
        array $structured,
        ?array $contextFacts
    ): array {
        if (! is_array($contextFacts)) {
            return [$recommendedAction, $reasoning];
        }

        $timezone = is_string($contextFacts['timezone'] ?? null) && trim((string) $contextFacts['timezone']) !== ''
            ? (string) $contextFacts['timezone']
            : config('app.timezone');

        $outAction = $recommendedAction;
        $outReason = $reasoning;

        $rankedTasks = $structured['ranked_tasks'] ?? null;
        $taskFacts = $contextFacts['task_facts_by_title'] ?? null;
        if (is_array($rankedTasks) && $rankedTasks !== [] && is_array($taskFacts) && $taskFacts !== []) {
            [$outAction, $outReason] = $this->correctPrioritizeTasksNarrativeFacts($outAction, $outReason, $rankedTasks, $taskFacts, $timezone);
        }

        $rankedEvents = $structured['ranked_events'] ?? null;
        if (is_array($rankedEvents) && $rankedEvents !== []) {
            [$outAction, $outReason] = $this->enforcePrioritizeEventsNarrativeConsistency(
                $outAction,
                $outReason,
                ['ranked_events' => $rankedEvents],
                $contextFacts
            );
        }

        $rankedProjects = $structured['ranked_projects'] ?? null;
        if (is_array($rankedProjects) && $rankedProjects !== []) {
            [$outAction, $outReason] = $this->enforcePrioritizeProjectsNarrativeConsistency(
                $outAction,
                $outReason,
                ['ranked_projects' => $rankedProjects],
                $contextFacts
            );
        }

        return [$outAction, $outReason];
    }

    /**
     * @param  array<int, mixed>  $ranked
     * @param  array<string, mixed>  $taskFactsByTitle
     */
    private function prioritySummaryForRankedTasks(array $ranked, array $taskFactsByTitle): ?string
    {
        if (count($ranked) < 2) {
            return null;
        }

        $titles = [];
        foreach (array_slice($ranked, 1, 2) as $item) {
            if (is_array($item) && isset($item['title']) && is_string($item['title'])) {
                $titles[] = trim($item['title']);
            }
        }
        if ($titles === []) {
            return null;
        }

        $priorities = [];
        foreach ($titles as $t) {
            $facts = $taskFactsByTitle[$t] ?? null;
            if (is_array($facts) && isset($facts['priority']) && is_string($facts['priority']) && trim($facts['priority']) !== '') {
                $priorities[] = strtolower(trim($facts['priority']));
            }
        }
        $priorities = array_values(array_unique($priorities));
        if ($priorities === []) {
            return null;
        }

        $map = [
            'low' => 'low-priority',
            'medium' => 'medium-priority',
            'high' => 'high-priority',
            'urgent' => 'urgent-priority',
        ];
        $labels = array_values(array_unique(array_map(fn ($p) => $map[$p] ?? $p, $priorities)));

        if (count($labels) === 1) {
            return $labels[0];
        }

        if (count($labels) === 2) {
            return $labels[0].' and '.$labels[1];
        }

        return implode(', ', array_slice($labels, 0, -1)).', and '.end($labels);
    }

    /**
     * Whether the top-ranked task is the earliest due by end_datetime.
     *
     * @param  array<int, mixed>  $ranked
     */
    private function topRankedTaskIsEarliestDue(array $ranked): bool
    {
        $top = $ranked[0] ?? null;
        if (! is_array($top) || ! isset($top['end_datetime']) || ! is_string($top['end_datetime']) || trim($top['end_datetime']) === '') {
            return true;
        }
        $topEnd = trim($top['end_datetime']);

        $min = $topEnd;
        foreach ($ranked as $item) {
            if (! is_array($item) || ! isset($item['end_datetime']) || ! is_string($item['end_datetime'])) {
                continue;
            }
            $end = trim($item['end_datetime']);
            if ($end !== '' && strcmp($end, $min) < 0) {
                $min = $end;
            }
        }

        return $topEnd === $min;
    }

    /**
     * Detect obvious narrative contradictions (relative due phrases or wrong duration claims).
     *
     * @param  array<int, mixed>  $ranked
     * @param  array<string, mixed>  $taskFactsByTitle
     */
    private function prioritizeTasksNarrativeHasContradiction(
        string $text,
        array $ranked,
        array $taskFactsByTitle,
        string $timezone,
        ?string $currentTimeRaw = null
    ): bool {
        $lower = mb_strtolower($text);
        if (trim($lower) === '') {
            return false;
        }

        // If the narrative recommends a ranked task that is not #1, force alignment.
        $topTitle = isset($ranked[0]) && is_array($ranked[0]) && isset($ranked[0]['title']) && is_string($ranked[0]['title'])
            ? trim($ranked[0]['title'])
            : '';
        if ($topTitle !== '') {
            $mentionsTop = $this->textLikelyMentionsLabel($text, $topTitle);
            if (! $mentionsTop) {
                foreach (array_slice($ranked, 1, 4) as $item) {
                    if (! is_array($item) || ! isset($item['title']) || ! is_string($item['title'])) {
                        continue;
                    }
                    $title = trim($item['title']);
                    if ($title !== '' && $this->textLikelyMentionsLabel($text, $title)) {
                        return true;
                    }
                }
            }
        }

        // If the model provides a bare "due Day, Mon DD, YYYY" without time,
        // we treat it as contradictory-risky and force canonical binding.
        if (preg_match('/\bdue\s+[A-Za-z]{3},\s+[A-Za-z]{3}\s+\d{1,2},\s+\d{4}\b(?!\s+\d{1,2}:\d{2})/i', $text) === 1) {
            return true;
        }

        // Weekday-only time phrases like "11:59 PM Friday" are ambiguous and often wrong—force canonical binding.
        if (preg_match('/\b\d{1,2}:\d{2}\s*[AP]M\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', $text) === 1) {
            return true;
        }

        $mentionsRelative = str_contains($lower, 'due today')
            || str_contains($lower, 'due tomorrow')
            || str_contains($lower, 'tomorrow')
            || str_contains($lower, 'end of today')
            || str_contains($lower, 'before the end of today')
            || str_contains($lower, 'no deadline yet')
            || str_contains($lower, 'no due date yet')
            || str_contains($lower, 'has no deadline yet')
            || str_contains($lower, 'has no due date yet');

        if ($mentionsRelative) {
            foreach (array_slice($ranked, 0, 3) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $title = isset($item['title']) && is_string($item['title']) ? trim($item['title']) : '';
                if ($title === '') {
                    continue;
                }
                $facts = $taskFactsByTitle[$title] ?? null;

                $dueTodayFlag = is_array($facts) && isset($facts['due_today']) ? (bool) $facts['due_today'] : null;
                if (str_contains($lower, 'due today') && $dueTodayFlag === false) {
                    return true;
                }
                $overdueFlag = is_array($facts) && isset($facts['is_overdue']) ? (bool) $facts['is_overdue'] : null;
                if (str_contains($lower, 'overdue') && $overdueFlag === false) {
                    return true;
                }

                if (str_contains($lower, 'tomorrow')) {
                    $endRaw = isset($item['end_datetime']) && is_string($item['end_datetime']) ? trim($item['end_datetime']) : '';
                    if ($endRaw === '' && is_array($facts) && isset($facts['end_datetime']) && is_string($facts['end_datetime'])) {
                        $endRaw = trim($facts['end_datetime']);
                    }
                    if ($endRaw !== '') {
                        try {
                            $end = Carbon::parse($endRaw)->setTimezone($timezone);
                            $now = $currentTimeRaw !== null && trim($currentTimeRaw) !== ''
                                ? Carbon::parse($currentTimeRaw)->setTimezone($timezone)
                                : Carbon::now($timezone);
                            $isTomorrow = $end->isSameDay($now->copy()->addDay());
                            if (! $isTomorrow && str_contains($lower, 'tomorrow')) {
                                return true;
                            }
                        } catch (\Throwable) {
                            // if we can't parse, don't treat as contradiction here
                        }
                    }
                }
            }
        }

        // If the narrative claims a ranked follow-up item has no deadline yet, but it has an end_datetime, treat as contradiction.
        if (str_contains($lower, 'no deadline yet') || str_contains($lower, 'no due date yet')) {
            $second = $ranked[1] ?? null;
            if (is_array($second) && isset($second['end_datetime']) && is_string($second['end_datetime']) && trim($second['end_datetime']) !== '') {
                return true;
            }
        }

        // Duration contradictions: if the text mentions a specific number of hours/minutes and we have a duration, prefer to rewrite.
        if (preg_match('/\b(\d+)\s*(hours?|hrs?|minutes?|mins?)\b/i', $text)) {
            foreach (array_slice($ranked, 0, 2) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $title = isset($item['title']) && is_string($item['title']) ? trim($item['title']) : '';
                if ($title === '') {
                    continue;
                }
                $facts = $taskFactsByTitle[$title] ?? null;
                if (! is_array($facts)) {
                    continue;
                }
                $duration = isset($facts['duration']) && is_numeric($facts['duration']) ? (int) $facts['duration'] : null;
                if ($duration !== null && $duration > 0) {
                    // If model mentions any concrete duration number, we treat as risky and rewrite to canonical minutes.
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Ensure prioritize-events narrative does not contradict context-derived facts.
     *
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>|null  $contextFacts
     * @return array{0: string, 1: string} [recommended_action, reasoning]
     */
    private function enforcePrioritizeEventsNarrativeConsistency(
        string $recommendedAction,
        string $reasoning,
        array $structured,
        ?array $contextFacts
    ): array {
        if (! is_array($contextFacts)) {
            return [$recommendedAction, $reasoning];
        }

        $eventFactsByTitle = $contextFacts['event_facts_by_title'] ?? null;
        if (! is_array($eventFactsByTitle) || $eventFactsByTitle === []) {
            return [$recommendedAction, $reasoning];
        }

        $ranked = $structured['ranked_events'] ?? null;
        if (! is_array($ranked) || $ranked === []) {
            return [$recommendedAction, $reasoning];
        }

        $recommendedAction = $this->alignRecommendedActionWithTopRankedLabel($recommendedAction, $ranked, 'title');

        $timezone = is_string($contextFacts['timezone'] ?? null) && trim((string) $contextFacts['timezone']) !== ''
            ? (string) $contextFacts['timezone']
            : config('app.timezone');

        $text = $recommendedAction.' '.$reasoning;
        $lower = mb_strtolower($text);
        $mentionsRelative = str_contains($lower, 'today') || str_contains($lower, 'tomorrow') || str_contains($lower, 'overdue');
        if (! $mentionsRelative) {
            return [$recommendedAction, $reasoning];
        }

        $topTitle = isset($ranked[0]['title']) && is_string($ranked[0]['title']) ? trim($ranked[0]['title']) : '';
        $topStart = isset($ranked[0]['start_datetime']) && is_string($ranked[0]['start_datetime']) ? trim($ranked[0]['start_datetime']) : '';
        $topEnd = isset($ranked[0]['end_datetime']) && is_string($ranked[0]['end_datetime']) ? trim($ranked[0]['end_datetime']) : '';
        $topFacts = $topTitle !== '' ? ($eventFactsByTitle[$topTitle] ?? null) : null;

        if ($topStart === '' && is_array($topFacts) && isset($topFacts['start_datetime']) && is_string($topFacts['start_datetime'])) {
            $topStart = trim($topFacts['start_datetime']);
        }
        if ($topEnd === '' && is_array($topFacts) && isset($topFacts['end_datetime']) && is_string($topFacts['end_datetime'])) {
            $topEnd = trim($topFacts['end_datetime']);
        }

        $anchorRaw = $topStart !== '' ? $topStart : $topEnd;
        $anchorHuman = null;
        if ($anchorRaw !== '') {
            try {
                $anchorHuman = Carbon::parse($anchorRaw)->setTimezone($timezone)->toDayDateTimeString();
            } catch (\Throwable) {
                $anchorHuman = null;
            }
        }

        $fix = function (string $text) use ($anchorHuman): string {
            $out = $text;
            if ($anchorHuman !== null) {
                $out = preg_replace('/\btoday\b/i', $anchorHuman, $out) ?? $out;
                $out = preg_replace('/\btomorrow\b/i', $anchorHuman, $out) ?? $out;
                $out = preg_replace('/\boverdue\b/i', 'upcoming', $out) ?? $out;
            } else {
                $out = preg_replace('/\b(today|tomorrow|overdue)\b/i', '', $out) ?? $out;
            }

            return trim((string) preg_replace('/\s{2,}/', ' ', $out));
        };

        return [$fix($recommendedAction), $fix($reasoning)];
    }

    /**
     * Ensure prioritize-projects narrative does not contradict context-derived facts.
     *
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>|null  $contextFacts
     * @return array{0: string, 1: string} [recommended_action, reasoning]
     */
    private function enforcePrioritizeProjectsNarrativeConsistency(
        string $recommendedAction,
        string $reasoning,
        array $structured,
        ?array $contextFacts
    ): array {
        if (! is_array($contextFacts)) {
            return [$recommendedAction, $reasoning];
        }

        $projectFactsByName = $contextFacts['project_facts_by_name'] ?? null;
        if (! is_array($projectFactsByName) || $projectFactsByName === []) {
            return [$recommendedAction, $reasoning];
        }

        $ranked = $structured['ranked_projects'] ?? null;
        if (! is_array($ranked) || $ranked === []) {
            return [$recommendedAction, $reasoning];
        }

        $recommendedAction = $this->alignRecommendedActionWithTopRankedLabel($recommendedAction, $ranked, 'name');

        $timezone = is_string($contextFacts['timezone'] ?? null) && trim((string) $contextFacts['timezone']) !== ''
            ? (string) $contextFacts['timezone']
            : config('app.timezone');

        $text = $recommendedAction.' '.$reasoning;
        $lower = mb_strtolower($text);
        $mentionsRelative = str_contains($lower, 'due today')
            || str_contains($lower, 'due tomorrow')
            || str_contains($lower, 'tomorrow')
            || str_contains($lower, 'overdue')
            || str_contains($lower, 'end of today');
        if (! $mentionsRelative) {
            return [$recommendedAction, $reasoning];
        }

        $topName = isset($ranked[0]['name']) && is_string($ranked[0]['name']) ? trim($ranked[0]['name']) : '';
        $topEnd = isset($ranked[0]['end_datetime']) && is_string($ranked[0]['end_datetime']) ? trim($ranked[0]['end_datetime']) : '';
        $topFacts = $topName !== '' ? ($projectFactsByName[$topName] ?? null) : null;
        if ($topEnd === '' && is_array($topFacts) && isset($topFacts['end_datetime']) && is_string($topFacts['end_datetime'])) {
            $topEnd = trim($topFacts['end_datetime']);
        }

        $dueHuman = null;
        if ($topEnd !== '') {
            try {
                $dueHuman = Carbon::parse($topEnd)->setTimezone($timezone)->toDayDateTimeString();
            } catch (\Throwable) {
                $dueHuman = null;
            }
        }

        $fix = function (string $text) use ($dueHuman): string {
            $out = $text;
            if ($dueHuman !== null) {
                $out = preg_replace('/\bdue\s+today\b/i', 'due '.$dueHuman, $out) ?? $out;
                $out = preg_replace('/\bdue\s+tomorrow\b/i', 'due '.$dueHuman, $out) ?? $out;
                $out = preg_replace('/\btomorrow\b/i', $dueHuman, $out) ?? $out;
                $out = preg_replace('/\bend\s+of\s+today\b/i', $dueHuman, $out) ?? $out;
                $out = preg_replace('/\boverdue\b/i', 'due', $out) ?? $out;
            } else {
                $out = preg_replace('/\b(due\s+today|due\s+tomorrow|tomorrow|end\s+of\s+today|overdue)\b/i', '', $out) ?? $out;
            }

            return trim((string) preg_replace('/\s{2,}/', ' ', $out));
        };

        return [$fix($recommendedAction), $fix($reasoning)];
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
            LlmIntent::ScheduleTasks,
        ], true)) {
            return [];
        }

        if ($entityType === LlmEntityType::Multiple) {
            if ($intent === LlmIntent::ScheduleTasks) {
                return $this->buildAppliableChangesFromScheduledTasks($structured);
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
     * Build multi-task appliable changes for ScheduleTasks intent.
     *
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    private function buildAppliableChangesFromScheduledTasks(array $structured): array
    {
        $scheduledTasks = $structured['scheduled_tasks'] ?? null;
        if (! is_array($scheduledTasks) || $scheduledTasks === []) {
            return [];
        }

        $updates = [];
        foreach ($scheduledTasks as $item) {
            if (! is_array($item) || ! isset($item['id']) || ! is_numeric($item['id'])) {
                continue;
            }

            $startRaw = $item['start_datetime'] ?? null;
            $duration = isset($item['duration']) && is_numeric($item['duration']) ? (int) $item['duration'] : null;
            $start = $startRaw !== null && $startRaw !== '' ? $this->parseDateTime($startRaw) : null;

            if ($start === null && ($duration === null || $duration <= 0)) {
                continue;
            }
            if ($start !== null && $start->lt(Carbon::now())) {
                continue;
            }

            $properties = [];
            if ($start !== null) {
                $properties['startDatetime'] = $start->toIso8601String();
            }
            if ($duration !== null && $duration > 0) {
                $properties['duration'] = $duration;
            }
            if ($properties === []) {
                continue;
            }

            $updates[] = [
                'id' => (int) $item['id'],
                'properties' => $properties,
            ];
        }

        return $updates !== []
            ? [
                'entity_type' => 'task',
                'updates' => $updates,
            ]
            : [];
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

<?php

namespace App\Services\Llm;

use App\DataTransferObjects\Llm\LlmInferenceResult;
use App\DataTransferObjects\Llm\RecommendationDisplayDto;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use Carbon\Carbon;

/**
 * Builds validated RecommendationDisplayDto from Phase 5 inference result.
 * Computes validation-based confidence (required fields, date parse, enums) for UI; do not use model self-reported confidence.
 */
class RecommendationDisplayBuilder
{
    private const PRIORITY_VALUES = ['low', 'medium', 'high', 'urgent'];

    public function build(LlmInferenceResult $result, LlmIntent $intent, LlmEntityType $entityType): RecommendationDisplayDto
    {
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

        $rankedLines = $this->formatRankedListForMessage($structured, $intent);
        $nextStepsLines = $this->formatNextStepsForMessage($structured);
        $message = $this->buildMessage($actionForDisplay, $reasoningForDisplay, $listedItems, $rankedLines, $nextStepsLines);
        $displayStructured = $this->sanitizeStructuredForDisplay($structured, $intent);
        $followupSuggestions = $this->defaultFollowupSuggestionsForIntent($intent, $entityType);

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
            followupSuggestions: $followupSuggestions,
        );
    }

    /**
     * Validation-based confidence: 0–1 from required fields, date parse, enum validity.
     */
    private function computeValidationConfidence(array $structured, LlmIntent $intent, LlmEntityType $entityType): float
    {
        $checks = 0;
        $passed = 0;

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
            if (! empty($structured['end_datetime'])) {
                $checks++;
                $passed += $this->parseDateTime($structured['end_datetime']) !== null ? 1 : 0;
            }
            if (isset($structured['priority']) && $structured['priority'] !== '') {
                $checks++;
                $passed += in_array(strtolower((string) $structured['priority']), self::PRIORITY_VALUES, true) ? 1 : 0;
            }
        }

        if (in_array($intent, [LlmIntent::ScheduleEvent, LlmIntent::AdjustEventTime], true)) {
            if (! empty($structured['start_datetime'])) {
                $checks++;
                $passed += $this->parseDateTime($structured['start_datetime']) !== null ? 1 : 0;
            }
            if (! empty($structured['end_datetime'])) {
                $checks++;
                $passed += $this->parseDateTime($structured['end_datetime']) !== null ? 1 : 0;
            }
        }

        if (in_array($intent, [LlmIntent::ScheduleProject, LlmIntent::AdjustProjectTimeline], true)) {
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
     * Format ranked_tasks, ranked_events, or ranked_projects into lines for the message body.
     *
     * @param  array<string, mixed>  $structured
     * @return array<int, string>
     */
    private function formatRankedListForMessage(array $structured, LlmIntent $intent): array
    {
        $ranked = $structured['ranked_tasks'] ?? $structured['ranked_events'] ?? $structured['ranked_projects'] ?? null;
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
                    $suffix[] = __('due :date', ['date' => $date->toDayDateTimeString()]);
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
     * @return array<string, mixed>
     */
    private function sanitizeStructuredForDisplay(array $structured, LlmIntent $intent): array
    {
        $out = [];

        $allowedKeys = ['ranked_tasks', 'ranked_events', 'ranked_projects', 'listed_items', 'start_datetime', 'end_datetime', 'priority', 'duration', 'timezone', 'location', 'blockers', 'next_steps'];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $structured) && $structured[$key] !== null) {
                $out[$key] = $structured[$key];
            }
        }

        return $out;
    }

    /**
     * Default follow-up prompt suggestions for the assistant UI, based on intent/entity.
     *
     * @return list<string>
     */
    private function defaultFollowupSuggestionsForIntent(LlmIntent $intent, LlmEntityType $entityType): array
    {
        return match ($intent) {
            LlmIntent::PrioritizeTasks => [
                __('Schedule the top task for today.'),
                __('Show my tasks with no due date.'),
            ],
            LlmIntent::PrioritizeEvents => [
                __('Which events should I focus on this week?'),
            ],
            LlmIntent::PrioritizeProjects => [
                __('Help me break my top project into smaller steps.'),
            ],
            LlmIntent::ScheduleTask, LlmIntent::AdjustTaskDeadline => [
                __('Can you suggest another time slot for this task?'),
            ],
            LlmIntent::ScheduleEvent, LlmIntent::AdjustEventTime => [
                __('Suggest a different time for this event.'),
            ],
            LlmIntent::ScheduleProject, LlmIntent::AdjustProjectTimeline => [
                __('Help me plan milestones for this project.'),
            ],
            LlmIntent::ResolveDependency => [
                __('Show me which tasks are still blocked.'),
            ],
            LlmIntent::GeneralQuery => [
                __('What should I focus on next?'),
            ],
            default => [],
        };
    }
}

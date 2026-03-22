<?php

namespace App\Services\LLM\Browse;

use App\Services\LLM\Prioritization\TaskAssistantTaskChoiceConstraintsExtractor;
use App\Services\LLM\Prioritization\TaskPrioritizationService;

/**
 * Deterministic listing: applies the same constraint + ranking pipeline as prioritization,
 * scoped to tasks only, with an optional "ambiguous list" shortcut (top N by rank).
 */
final class TaskAssistantBrowseListingService
{
    public function __construct(
        private readonly TaskAssistantTaskChoiceConstraintsExtractor $constraintsExtractor,
        private readonly TaskPrioritizationService $prioritizationService,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{
     *   items: list<array{entity_type:string,entity_id:int,title:string,reason:string}>,
     *   deterministic_summary: string,
     *   filter_description: string,
     *   ambiguous: bool
     * }
     */
    public function build(string $userMessage, array $snapshot): array
    {
        $context = $this->constraintsExtractor->extract($userMessage);
        $ambiguous = $this->isAmbiguousBrowseListRequest($userMessage, $context);

        if ($ambiguous) {
            $context = [
                'priority_filters' => [],
                'task_keywords' => [],
                'time_constraint' => null,
                'recurring_requested' => false,
                'comparison_focus' => null,
            ];
        }

        $ranked = $this->prioritizationService->prioritizeFocus($snapshot, $context);
        $tasksOnly = array_values(array_filter(
            $ranked,
            static fn (array $row): bool => ($row['type'] ?? '') === 'task'
        ));

        $ambiguousLimit = max(1, min(10, (int) config('task-assistant.browse.ambiguous_top_limit', 5)));
        $maxItems = max(1, min(50, (int) config('task-assistant.browse.max_items', 50)));
        $take = $ambiguous ? $ambiguousLimit : $maxItems;

        $tasksOnly = array_slice($tasksOnly, 0, $take);

        $items = [];
        foreach ($tasksOnly as $row) {
            $items[] = [
                'entity_type' => 'task',
                'entity_id' => (int) ($row['id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'reason' => trim((string) ($row['reasoning'] ?? '')),
            ];
        }

        $filterDescription = $this->describeFilters($ambiguous, $context);
        $deterministicSummary = $this->buildDeterministicSummary(count($items), $ambiguous, $filterDescription);

        return [
            'items' => $items,
            'deterministic_summary' => $deterministicSummary,
            'filter_description' => $filterDescription,
            'ambiguous' => $ambiguous,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function isAmbiguousBrowseListRequest(string $message, array $context): bool
    {
        if (($context['recurring_requested'] ?? false)
            || ($context['time_constraint'] ?? null) !== null
            || (! empty($context['task_keywords'] ?? []))
            || (! empty($context['priority_filters'] ?? []))) {
            return false;
        }

        $msg = mb_strtolower(trim($message));

        return (bool) preg_match('/\b(list|show|display|give me|what)\s+(all\s+)?(my\s+)?tasks?\b/i', $msg)
            || (bool) preg_match('/\b(list|show)\s+(everything|them)\b/i', $msg)
            || (bool) preg_match('/\b(show|list)\s+me\s+(my\s+)?tasks?\b/i', $msg);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function describeFilters(bool $ambiguous, array $context): string
    {
        if ($ambiguous) {
            return 'no strong filters; showing top-ranked tasks for now';
        }

        $parts = [];
        if (! empty($context['recurring_requested'])) {
            $parts[] = 'recurring tasks only';
        }
        if (($context['time_constraint'] ?? null) !== null) {
            $parts[] = 'time: '.(string) $context['time_constraint'];
        }
        if (! empty($context['priority_filters'] ?? [])) {
            $parts[] = 'priority: '.implode(',', $context['priority_filters']);
        }
        if (! empty($context['task_keywords'] ?? [])) {
            $parts[] = 'keywords/tags/title: '.implode(', ', $context['task_keywords']);
        }

        return $parts === [] ? 'all matching tasks in the current snapshot' : implode('; ', $parts);
    }

    private function buildDeterministicSummary(int $count, bool $ambiguous, string $filterDescription): string
    {
        if ($count === 0) {
            return 'No tasks matched your request ('.$filterDescription.').';
        }

        if ($ambiguous) {
            return 'Here are your top '.$count.' tasks to focus on right now:';
        }

        return 'Found '.$count.' task(s) matching '.$filterDescription.'.';
    }
}

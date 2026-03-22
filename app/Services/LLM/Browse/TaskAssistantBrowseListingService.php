<?php

namespace App\Services\LLM\Browse;

use App\Services\LLM\Prioritization\TaskAssistantTaskChoiceConstraintsExtractor;
use App\Services\LLM\Prioritization\TaskPrioritizationService;
use Carbon\CarbonImmutable;

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
     *   items: list<array{entity_type:string,entity_id:int,title:string,reason:string,due_bucket:string}>,
     *   deterministic_summary: string,
     *   filter_description: string,
     *   ambiguous: bool
     * }
     */
    public function build(string $userMessage, array $snapshot): array
    {
        $timezone = (string) ($snapshot['timezone'] ?? config('app.timezone', 'UTC'));
        $now = CarbonImmutable::now($timezone);

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
            $rawTask = is_array($row['raw'] ?? null) ? $row['raw'] : [];
            $dueBucket = $this->classifyDueBucket($rawTask, $now, $timezone);
            $reason = trim((string) ($row['reasoning'] ?? ''));

            $items[] = [
                'entity_type' => 'task',
                'entity_id' => (int) ($row['id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'reason' => $this->humanizeBrowseReason($reason),
                'due_bucket' => $dueBucket,
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
     * Short, grounded lines for browse responses (replaces free-form LLM assumptions).
     *
     * @param  list<array{entity_type?:string, due_bucket?:string}>  $items
     * @return list<string>
     */
    public function buildDeterministicAssumptions(bool $ambiguous, string $filterDescription, array $items): array
    {
        $lines = [
            'Tasks and order come from your data and the active filter, using the same ranking rules as the rest of the assistant.',
        ];

        if ($ambiguous) {
            $lines[] = 'This is a short ranked slice of your tasks (no strict filter). Ask for a narrower search if you need more.';
        } else {
            $lines[] = 'Active filter: '.$filterDescription.'.';
        }

        $counts = [];
        foreach ($items as $item) {
            $b = (string) ($item['due_bucket'] ?? 'unknown');
            $counts[$b] = ($counts[$b] ?? 0) + 1;
        }

        $parts = [];
        foreach ($counts as $bucket => $count) {
            if ($count <= 0) {
                continue;
            }
            $parts[] = $count.' '.$this->dueBucketLabel($bucket);
        }

        if ($parts !== []) {
            $lines[] = 'In this list: '.implode(', ', $parts).'.';
        }

        return array_values(array_filter($lines, static fn (string $s): bool => $s !== ''));
    }

    private function dueBucketLabel(string $bucket): string
    {
        return match ($bucket) {
            'overdue' => 'overdue',
            'due_today' => 'due today',
            'due_tomorrow' => 'due tomorrow',
            'due_this_week' => 'due this week',
            'due_later' => 'due later',
            'no_deadline' => 'with no deadline',
            default => 'tasks',
        };
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function classifyDueBucket(array $task, CarbonImmutable $now, string $timezone): string
    {
        if (! isset($task['ends_at']) || $task['ends_at'] === null || $task['ends_at'] === '') {
            return 'no_deadline';
        }

        try {
            $deadline = CarbonImmutable::parse((string) $task['ends_at'], $timezone);
        } catch (\Throwable) {
            return 'no_deadline';
        }

        if ($deadline->lt($now)) {
            return 'overdue';
        }

        if ($deadline->isSameDay($now)) {
            return 'due_today';
        }

        if ($deadline->isSameDay($now->addDay())) {
            return 'due_tomorrow';
        }

        if ($deadline->lte($now->addDays(7))) {
            return 'due_this_week';
        }

        return 'due_later';
    }

    private function humanizeBrowseReason(string $reason): string
    {
        $r = trim($reason);
        if ($r === '') {
            return $r;
        }

        if (preg_match('/^Selected as\s+(.+?)\s+priority task\s+(.+)$/iu', $r, $m) === 1) {
            return trim($m[1]).' priority · '.trim($m[2]);
        }

        return $r;
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
            return 'Here are '.$count.' tasks from your list, ordered by urgency and due dates:';
        }

        return 'Found '.$count.' task(s) matching '.$filterDescription.'.';
    }
}

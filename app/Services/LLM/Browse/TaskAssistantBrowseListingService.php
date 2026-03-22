<?php

namespace App\Services\LLM\Browse;

use App\Enums\TaskComplexity;
use App\Services\LLM\Prioritization\TaskAssistantTaskChoiceConstraintsExtractor;
use App\Services\LLM\Prioritization\TaskPrioritizationService;
use App\Support\LLM\TaskAssistantBrowseDefaults;
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
     *   items: list<array<string, mixed>>,
     *   deterministic_summary: string,
     *   filter_context_for_prompt: string,
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
                'browse_domain' => null,
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
            $priority = strtolower(trim((string) ($rawTask['priority'] ?? 'medium')));
            $complexityRaw = $rawTask['complexity'] ?? null;
            $complexityLabel = TaskAssistantBrowseDefaults::complexityNotSetLabel();
            if (is_string($complexityRaw) && $complexityRaw !== '') {
                $complexityEnum = TaskComplexity::tryFrom($complexityRaw);
                if ($complexityEnum !== null) {
                    $complexityLabel = $complexityEnum->label();
                }
            }

            $deadline = $this->resolveDeadline($rawTask, $timezone);
            $dueOn = $this->formatDueOn($deadline, $timezone);
            $duePhrase = $this->buildDuePhrase($dueBucket);

            $items[] = [
                'entity_type' => 'task',
                'entity_id' => (int) ($row['id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'priority' => $priority,
                'due_bucket' => $dueBucket,
                'due_phrase' => $duePhrase,
                'due_on' => $dueOn,
                'complexity_label' => $complexityLabel,
            ];
        }

        $filterContextForPrompt = $this->describeFilters($ambiguous, $context);
        $deterministicSummary = $this->buildDeterministicSummary(count($items), $ambiguous);

        return [
            'items' => $items,
            'deterministic_summary' => $deterministicSummary,
            'filter_context_for_prompt' => $filterContextForPrompt,
            'ambiguous' => $ambiguous,
        ];
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function resolveDeadline(array $task, string $timezone): ?CarbonImmutable
    {
        if (! isset($task['ends_at']) || $task['ends_at'] === null || $task['ends_at'] === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $task['ends_at'], $timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatDueOn(?CarbonImmutable $deadline, string $timezone): string
    {
        if ($deadline === null) {
            return '—';
        }

        return $deadline->locale((string) config('app.locale', 'en'))->translatedFormat('M j, Y');
    }

    private function buildDuePhrase(string $dueBucket): string
    {
        return match ($dueBucket) {
            'overdue' => 'overdue',
            'due_today' => 'due today',
            'due_tomorrow' => 'due tomorrow',
            'due_this_week' => 'due this week',
            'due_later' => 'due later',
            'no_deadline' => 'no due date',
            default => 'scheduled',
        };
    }

    /**
     * Buckets by calendar date in the user timezone so a deadline later "today" is never labeled overdue
     * just because its clock time is earlier than the current instant (e.g. midnight due time).
     *
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

        $startOfToday = $now->startOfDay();

        if ($deadline->lt($startOfToday)) {
            return 'overdue';
        }

        if ($deadline->isSameDay($now)) {
            return 'due_today';
        }

        $tomorrow = $now->addDay();
        if ($deadline->isSameDay($tomorrow)) {
            return 'due_tomorrow';
        }

        if ($deadline->lte($now->addDays(7))) {
            return 'due_this_week';
        }

        return 'due_later';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function isAmbiguousBrowseListRequest(string $message, array $context): bool
    {
        if (($context['browse_domain'] ?? null) !== null) {
            return false;
        }

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

        if (($context['browse_domain'] ?? null) === 'school') {
            $parts = [];
            if (($context['time_constraint'] ?? null) !== null) {
                $parts[] = 'time: '.(string) $context['time_constraint'];
            }
            if (! empty($context['task_keywords'] ?? [])) {
                $parts[] = 'keywords/tags/title: '.implode(', ', $context['task_keywords']);
            }
            $domainLine = 'domain: school (subjects, teachers, or academic tags — not generic errands)';
            if ($parts === []) {
                return $domainLine;
            }

            return $domainLine.'; '.implode('; ', $parts);
        }

        if (($context['browse_domain'] ?? null) === 'chores') {
            $parts = [];
            if (($context['time_constraint'] ?? null) !== null) {
                $parts[] = 'time: '.(string) $context['time_constraint'];
            }
            if (! empty($context['task_keywords'] ?? [])) {
                $parts[] = 'keywords/tags/title: '.implode(', ', $context['task_keywords']);
            }
            $domainLine = 'domain: chores / household (prefers recurring when available)';
            if ($parts === []) {
                return $domainLine;
            }

            return $domainLine.'; '.implode('; ', $parts);
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

        return $parts === [] ? 'all matching tasks in your list (ranked by urgency)' : implode('; ', $parts);
    }

    private function buildDeterministicSummary(int $count, bool $ambiguous): string
    {
        if ($count === 0) {
            return 'No tasks matched your request.';
        }

        if ($ambiguous) {
            return 'Here are '.$count.' tasks from your list, ordered by urgency and due dates:';
        }

        return 'Found '.$count.' task(s).';
    }
}

<?php

namespace App\Services\Llm;

use App\Enums\LlmIntent;

/**
 * Sanitizes LLM structured output so ranked_* and listed_items only contain
 * entities that were actually in the context we sent (current DB state).
 * Prevents the model from copying titles from conversation_history or hallucinating.
 */
class StructuredOutputSanitizer
{
    /**
     * Filter ranked_* to only include titles/names present in context (current DB state).
     *
     * @param  array<string, mixed>  $structured  Raw structured output from the LLM
     * @param  array<string, mixed>  $context  Context payload we sent (tasks, events, projects)
     * @return array<string, mixed> Sanitized structured output
     */
    public function sanitize(array $structured, array $context, LlmIntent $intent): array
    {
        return match ($intent) {
            LlmIntent::PrioritizeTasks => $this->sanitizeRankedTasks($structured, $context),
            LlmIntent::PrioritizeEvents => $this->sanitizeRankedEvents($structured, $context),
            LlmIntent::PrioritizeProjects => $this->sanitizeRankedProjects($structured, $context),
            default => $structured,
        };
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

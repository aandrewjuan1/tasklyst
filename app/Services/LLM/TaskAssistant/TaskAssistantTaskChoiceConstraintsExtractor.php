<?php

namespace App\Services\LLM\TaskAssistant;

final class TaskAssistantTaskChoiceConstraintsExtractor
{
    /**
     * Extract prioritization constraints deterministically from user text.
     *
     * @return array{
     *   priority_filters: array<int, string>,
     *   task_keywords: array<int, string>,
     *   time_constraint: string|null,
     *   comparison_focus: string|null
     * }
     */
    public function extract(string $userMessageContent): array
    {
        $content = strtolower($userMessageContent);

        $priorityFilters = [];
        foreach (['urgent', 'high', 'medium', 'low'] as $priority) {
            if (preg_match('/\b'.preg_quote($priority, '/').'\b/i', $content) === 1) {
                $priorityFilters[] = $priority;
            }
        }

        $timeConstraint = null;
        if (preg_match('/\btoday\b/i', $content) === 1) {
            $timeConstraint = 'today';
        } elseif (preg_match('/\bthis\s+week\b/i', $content) === 1) {
            $timeConstraint = 'this_week';
        }

        // Only allow subject keywords when the user explicitly mentions them.
        $subjectAllowList = [
            'coding',
            'code',
            'programming',
            'math',
            'study',
            'reading',
            'review',
            'writing',
            'essay',
            'report',
            'slides',
            'lab',
            'interview',
        ];

        $taskKeywords = [];
        foreach ($subjectAllowList as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $content) === 1) {
                $taskKeywords[] = $keyword;
            }
        }

        return [
            'priority_filters' => $priorityFilters,
            'task_keywords' => $taskKeywords,
            'time_constraint' => $timeConstraint,
            'comparison_focus' => null,
        ];
    }
}

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
     *   recurring_requested: bool,
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

        // Detect intent for recurring tasks (instance/occurrence-level selection).
        $recurringRequested = preg_match('/\b(recurring|recurrence|repeat(e?d)?|every)\b/i', $userMessageContent) === 1;

        // Only allow subject keywords when the user explicitly mentions them.
        $subjectAllowList = [
            'coding',
            'code',
            'programming',
            // School-related
            'math',
            'science',
            'biology',
            'chemistry',
            'physics',
            'study',
            'reading',
            'review',
            'writing',
            'essay',
            'report',
            'slides',
            'lab',
            'interview',
            'homework',
            'assignment',
            'assignments',
            'schoolwork',
            'school',
            'coursework',
            'classwork',
            // Chores-related
            'chores',
            'housework',
            'cleaning',
            'laundry',
            'dishes',
            'trash',
            'vacuum',
        ];

        $taskKeywords = [];
        foreach ($subjectAllowList as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $content) === 1) {
                $taskKeywords[] = $keyword;
            }
        }

        // Map generic "chores" intent to your tag vocabulary, since titles often won't contain the word "chores".
        // Your seed tags include: Household, Health.
        $explicitChoresIntent = preg_match('/\bchores\b/i', $userMessageContent) === 1
            || preg_match('/\b(housework|cleaning)\b/i', $userMessageContent) === 1;

        if ($explicitChoresIntent) {
            foreach (['household', 'health'] as $mappedTagKeyword) {
                if (! in_array($mappedTagKeyword, $taskKeywords, true)) {
                    $taskKeywords[] = $mappedTagKeyword;
                }
            }
        }

        return [
            'priority_filters' => $priorityFilters,
            'task_keywords' => $taskKeywords,
            'time_constraint' => $timeConstraint,
            'recurring_requested' => $recurringRequested,
            'comparison_focus' => null,
        ];
    }
}

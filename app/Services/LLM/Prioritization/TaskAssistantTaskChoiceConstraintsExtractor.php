<?php

namespace App\Services\LLM\Prioritization;

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
     *   comparison_focus: string|null,
     *   browse_domain: 'school'|'chores'|null
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

        $recurringRequested = preg_match('/\b(recurring|recurrence|repeat(e?d)?|every)\b/i', $userMessageContent) === 1;

        $explicitChoresIntent = preg_match('/\bchores\b/i', $userMessageContent) === 1
            || preg_match('/\b(housework|cleaning)\b/i', $userMessageContent) === 1;

        $browseDomainChores = $explicitChoresIntent
            || preg_match('/\b(chores?|household|laundry|dishes|vacuum)\b/i', $userMessageContent) === 1;

        $browseDomainSchool = ! $browseDomainChores && $this->detectSchoolBrowseDomain($userMessageContent);

        $browseDomain = null;
        if ($browseDomainChores) {
            $browseDomain = 'chores';
        } elseif ($browseDomainSchool) {
            $browseDomain = 'school';
        }

        $subjectAllowList = [
            'coding',
            'code',
            'programming',
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
            if ($keyword === 'school' && $browseDomain === 'school') {
                continue;
            }
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $content) === 1) {
                $taskKeywords[] = $keyword;
            }
        }

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
            'browse_domain' => $browseDomain,
        ];
    }

    /**
     * School-related browse intent: coursework and classes, not the substring "school" in arbitrary titles.
     */
    private function detectSchoolBrowseDomain(string $message): bool
    {
        if (preg_match('/school\s*related|school[-\s]related/i', $message) === 1) {
            return true;
        }
        if (preg_match('/\bfor\s+school\b|\bat\s+school\b|\bschoolwork\b|\bschool\s+work\b/i', $message) === 1) {
            return true;
        }
        if (preg_match('/\b(homework|assignments?|coursework|classwork|schoolwork)\b/i', $message) === 1) {
            return true;
        }
        if (preg_match('/\b(my\s+)?(classes|subjects|courses)\b/i', $message) === 1) {
            return true;
        }
        if (preg_match('/\b(exams?|quizzes?|midterm|finals?)\b/i', $message) === 1) {
            return true;
        }
        if (preg_match('/\bschool\s+(tasks?|stuff|things|work)\b/i', $message) === 1) {
            return true;
        }
        if (preg_match('/\bacademic\b/i', $message) === 1) {
            return true;
        }

        return false;
    }
}

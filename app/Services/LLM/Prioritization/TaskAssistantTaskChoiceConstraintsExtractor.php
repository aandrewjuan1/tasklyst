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
     *   strict_filtering: bool,
     *   comparison_focus: string|null,
     *   domain_focus: 'school'|'chores'|null,
     *   entity_type_preference: 'task'|'event'|'project'|'mixed'
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

        $domainFocusChores = $explicitChoresIntent
            || preg_match('/\b(chores?|household|laundry|dishes|vacuum)\b/i', $userMessageContent) === 1;

        $domainFocusSchool = ! $domainFocusChores && $this->detectSchoolDomain($userMessageContent);

        $domainFocus = null;
        if ($domainFocusChores) {
            $domainFocus = 'chores';
        } elseif ($domainFocusSchool) {
            $domainFocus = 'school';
        }

        $subjectAllowList = [
            'coding',
            'code',
            'programming',
            'math',
            'mathematics',
            'science',
            'biology',
            'chemistry',
            'physics',
            'study',
            'studying',
            'revision',
            'revise',
            'reading',
            'review',
            'writing',
            'essay',
            'thesis',
            'capstone',
            'report',
            'slides',
            'lab',
            'lesson',
            'module',
            'syllabus',
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
            if ($keyword === 'school' && $domainFocus === 'school') {
                continue;
            }
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $content) === 1) {
                $taskKeywords[] = $keyword;
            }
        }

        foreach ($this->extractDynamicTaskKeywords($userMessageContent) as $keyword) {
            if (! in_array($keyword, $taskKeywords, true)) {
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

        $entityTypePreference = $this->inferEntityTypePreference($userMessageContent);

        return [
            'priority_filters' => $priorityFilters,
            'task_keywords' => $taskKeywords,
            'time_constraint' => $timeConstraint,
            'recurring_requested' => $recurringRequested,
            'strict_filtering' => $this->detectStrictFilteringIntent($userMessageContent, $taskKeywords),
            'comparison_focus' => null,
            'domain_focus' => $domainFocus,
            'entity_type_preference' => $entityTypePreference,
        ];
    }

    /**
     * @return list<string>
     */
    private function extractDynamicTaskKeywords(string $message): array
    {
        $keywords = [];

        if (preg_match('/\brelated\s+to\s+([a-z0-9][a-z0-9\s\-_]{1,40})\b/iu', $message, $matches) === 1) {
            $keywords = array_merge($keywords, $this->normalizeKeywordPhrase((string) ($matches[1] ?? '')));
        }

        if (preg_match('/\b([a-z0-9][a-z0-9\s\-_]{1,40})\s+related\b/iu', $message, $matches) === 1) {
            $keywords = array_merge($keywords, $this->normalizeKeywordPhrase((string) ($matches[1] ?? '')));
        }

        if (preg_match('/\b(?:about|for)\s+([a-z0-9][a-z0-9\s\-_]{1,40})\s+(?:tasks?|items?|priorities)\b/iu', $message, $matches) === 1) {
            $keywords = array_merge($keywords, $this->normalizeKeywordPhrase((string) ($matches[1] ?? '')));
        }

        return array_values(array_unique($keywords));
    }

    /**
     * @param  list<string>  $taskKeywords
     */
    private function detectStrictFilteringIntent(string $message, array $taskKeywords = []): bool
    {
        if (preg_match('/\b(only|just|strictly|exclusively)\b/i', $message) === 1) {
            return true;
        }

        if (preg_match('/\brelated\b/i', $message) === 1) {
            return true;
        }

        $normalized = mb_strtolower($message);
        foreach ($taskKeywords as $keyword) {
            $needle = trim(mb_strtolower((string) $keyword));
            if ($needle === '') {
                continue;
            }
            if (preg_match('/\b'.preg_quote($needle, '/').'\s+tasks?\b/u', $normalized) === 1) {
                return true;
            }
            if (preg_match('/\btasks?\s+.*\b'.preg_quote($needle, '/').'\b/u', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function normalizeKeywordPhrase(string $phrase): array
    {
        $lower = mb_strtolower(trim($phrase));
        if ($lower === '') {
            return [];
        }

        $tokens = preg_split('/[\s\-_]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stopwords = [
            'my', 'me', 'the', 'a', 'an', 'and', 'or', 'to', 'for', 'on', 'in', 'of',
            'task', 'tasks', 'item', 'items', 'priority', 'priorities',
            'related', 'only', 'top', 'what', 'are', 'is', 'with', 'school', 'said', 'about',
        ];

        $filtered = [];
        foreach ($tokens as $token) {
            if (mb_strlen($token) < 3) {
                continue;
            }
            if (in_array($token, $stopwords, true)) {
                continue;
            }
            if (preg_match('/^\d+$/u', $token) === 1) {
                continue;
            }
            $filtered[] = $token;
        }

        return array_values(array_unique($filtered));
    }

    /**
     * If the user explicitly says "tasks" (and doesn't mention events/calendar),
     * prefer ranking tasks only. Same for "events" and "projects".
     */
    private function inferEntityTypePreference(string $message): string
    {
        $normalized = mb_strtolower($message);

        $mentionsTasks = preg_match('/\b(tasks?|to\s*do|todo|to-do)\b/u', $normalized) === 1;
        $mentionsEvents = preg_match('/\b(events?|calendar|meetings?|appointments?)\b/u', $normalized) === 1;
        $mentionsProjects = preg_match('/\bprojects?\b/u', $normalized) === 1;

        $mentionsMultiple = (int) $mentionsTasks + (int) $mentionsEvents + (int) $mentionsProjects >= 2;
        if ($mentionsMultiple) {
            return 'mixed';
        }

        if ($mentionsTasks) {
            return 'task';
        }

        if ($mentionsEvents) {
            return 'event';
        }

        if ($mentionsProjects) {
            return 'project';
        }

        return 'mixed';
    }

    /**
     * School-related domain intent: coursework and classes, not the substring "school" in arbitrary titles.
     */
    private function detectSchoolDomain(string $message): bool
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
        if (preg_match('/\b(study|studying|revision|revise)\b/i', $message) === 1) {
            return true;
        }
        if (preg_match('/\b(my\s+)?(classes|courses)\b/i', $message) === 1) {
            return true;
        }
        if (preg_match('/\b(lesson|module|syllabus)\b/i', $message) === 1) {
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

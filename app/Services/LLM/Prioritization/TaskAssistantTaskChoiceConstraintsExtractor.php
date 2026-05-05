<?php

namespace App\Services\LLM\Prioritization;

final class TaskAssistantTaskChoiceConstraintsExtractor
{
    /**
     * Extract prioritization constraints deterministically from user text.
     *
     * @return array{
     *   priority_filters: array<int, 'high'|'medium'|'low'>,
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
        foreach (['high', 'medium', 'low'] as $priority) {
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

        $taskKeywords = [];

        foreach ($this->extractDynamicTaskKeywords($userMessageContent) as $keyword) {
            if ($keyword === 'school' && $domainFocus === 'school') {
                continue;
            }
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

        $taskKeywords = $this->filterMetaImportanceTokens($taskKeywords, $content);

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

        $patternCaptures = [
            '/\brelated\s+to\s+([a-z0-9][a-z0-9\s\-_]{1,60})\b/iu',
            '/\b([a-z0-9][a-z0-9\s\-_]{1,60})\s+related\b/iu',
            '/\b(?:about|for)\s+([a-z0-9][a-z0-9\s\-_]{1,60})\s+(?:tasks?|items?|priorities|subjects?)\b/iu',
            '/\b(?:in\s+)?my\s+([a-z0-9][a-z0-9\s\-_]{1,60})\s+(?:tasks?|items?|priorities|subjects?)\b/iu',
            '/\b([a-z0-9][a-z0-9\s\-_]{1,40})\s+subject\b/iu',
            '/\b([a-z0-9][a-z0-9\s\-_]{1,30})\s+subjects\b/iu',
            '/\b([a-z0-9][a-z0-9\s\-_]{1,40})\s+tasks?\b/iu',
        ];
        foreach ($patternCaptures as $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                $keywords = array_merge($keywords, $this->normalizeKeywordPhrase((string) ($matches[1] ?? '')));
            }
        }

        if (preg_match('/\bbetween\s+([a-z0-9][a-z0-9\s\-_]{1,30})\s+and\s+([a-z0-9][a-z0-9\s\-_]{1,30})\b/iu', $message, $matches) === 1) {
            $keywords = array_merge($keywords, $this->normalizeKeywordPhrase((string) ($matches[1] ?? '')));
            $keywords = array_merge($keywords, $this->normalizeKeywordPhrase((string) ($matches[2] ?? '')));
        }

        // Fallback: keep extraction dynamic for unseen subjects/filter labels
        // while relying on stopwords to drop instruction words.
        $keywords = array_merge($keywords, $this->normalizeKeywordPhrase($message));

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
            if (preg_match('/\b'.preg_quote($needle, '/').'\s+(tasks?|subjects?|items?|priorities)\b/u', $normalized) === 1) {
                if ($needle === 'important' && $this->isGlobalImportanceTaskQuestion($normalized)) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    /**
     * "What is the most important task …" is a global prioritization ask, not "filter to tasks
     * whose title contains the word important".
     */
    private function isGlobalImportanceTaskQuestion(string $normalized): bool
    {
        return preg_match(
            '/\b(what|which)\s+.{0,48}\b(most\s+)?important\s+(task|thing)\b/u',
            $normalized
        ) === 1;
    }

    /**
     * @param  list<string>  $keywords
     * @return list<string>
     */
    private function filterMetaImportanceTokens(array $keywords, string $originalMessage): array
    {
        $meta = ['most', 'important', 'importance'];
        $normalized = mb_strtolower($originalMessage);
        $isGlobalImportance = $this->isGlobalImportanceTaskQuestion($normalized);

        $out = [];
        foreach ($keywords as $keyword) {
            $k = mb_strtolower(trim((string) $keyword));
            if ($k === '') {
                continue;
            }
            if ($isGlobalImportance && in_array($k, $meta, true)) {
                continue;
            }
            $out[] = $keyword;
        }

        return array_values(array_unique($out));
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
            'subject', 'subjects',
            'should', 'first', 'do', 'need', 'help', 'please', 'could', 'would', 'can',
            'have', 'has', 'had',
            'prioritize', 'schedule', 'later', 'today', 'tomorrow', 'week',
            'urgent', 'high', 'medium', 'low',
            'this', 'that', 'those', 'these', 'them', 'show', 'due', 'next', 'then', 'than',
            'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
            'most', 'important', 'importance', 'thing', 'things', 'tackle',
        ];

        $filtered = [];
        foreach ($tokens as $token) {
            $token = preg_replace('/^[^a-z0-9]+|[^a-z0-9]+$/u', '', $token) ?? '';
            if ($token === '') {
                continue;
            }
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

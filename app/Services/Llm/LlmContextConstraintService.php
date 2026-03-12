<?php

namespace App\Services\Llm;

use App\DataTransferObjects\Llm\LlmContextConstraints;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use Carbon\CarbonImmutable;

class LlmContextConstraintService
{
    public function parse(
        string $userMessage,
        LlmIntent $intent,
        LlmEntityType $entityType,
        CarbonImmutable $now
    ): LlmContextConstraints {
        $normalized = mb_strtolower(trim($userMessage));

        if ($normalized === '') {
            return LlmContextConstraints::none();
        }

        $constraints = new LlmContextConstraints;

        $this->applyCourseConstraints($normalized, $constraints);
        $this->applyDomainAndTagConstraints($normalized, $constraints);
        $this->applyTaskPropertyConstraints($normalized, $constraints);
        $this->applyTimeWindowConstraints($normalized, $constraints, $now);

        return $constraints;
    }

    private function applyCourseConstraints(string $normalized, LlmContextConstraints $constraints): void
    {
        $subjectNames = [];

        if (str_contains($normalized, 'cs 220')) {
            $subjectNames[] = 'CS 220 – Data Structures';
        }
        if (str_contains($normalized, 'math 201')) {
            $subjectNames[] = 'MATH 201 – Discrete Mathematics';
        }
        if (str_contains($normalized, 'itcs 101')) {
            $subjectNames[] = 'ITCS 101 – Intro to Programming';
        }
        if (str_contains($normalized, 'itel 210')) {
            $subjectNames[] = 'ITEL 210 – Web Development';
        }
        if (str_contains($normalized, 'eng 105')) {
            $subjectNames[] = 'ENG 105 – Academic Writing';
        }

        if ($subjectNames !== []) {
            $constraints->subjectNames = array_values(array_unique($subjectNames));
        }
    }

    private function applyDomainAndTagConstraints(string $normalized, LlmContextConstraints $constraints): void
    {
        if (str_contains($normalized, 'exam')) {
            $constraints->examRelatedOnly = true;
            $constraints->requiredTagNames[] = 'Exam';
        }

        // Generic "tagged as X" support so prompts like
        // "everything tagged as \"Homework\"" or "with the \"Health\" tag"
        // correctly drive tag-based filtering beyond the special-cased Exam tag.
        $mentionsTagging = str_contains($normalized, 'tagged') || str_contains($normalized, ' tag ');
        if ($mentionsTagging) {
            // Extract quoted tag names, e.g. "Exam", "Homework".
            $matches = [];
            if (preg_match_all('/"([^"]+)"/u', $normalized, $matches) > 0) {
                foreach ($matches[1] as $rawTag) {
                    $tag = trim($rawTag);
                    if ($tag !== '') {
                        // Preserve original capitalization for user-facing tags, but
                        // normalize common variants to match seed data.
                        if (mb_strtolower($tag) === 'exam') {
                            $tag = 'Exam';
                        }

                        $constraints->requiredTagNames[] = $tag;
                    }
                }
            }
        }

        $mentionsHealth = str_contains($normalized, 'health');
        $mentionsHousehold = str_contains($normalized, 'household');
        $mentionsChore = str_contains($normalized, 'chores') || str_contains($normalized, 'chore');

        $negatesHealth = str_contains($normalized, 'ignore health')
            || str_contains($normalized, 'not health')
            || str_contains($normalized, 'without health')
            || str_contains($normalized, 'exclude health');
        $negatesHousehold = str_contains($normalized, 'ignore household')
            || str_contains($normalized, 'not household')
            || str_contains($normalized, 'without household')
            || str_contains($normalized, 'exclude household');
        $negatesChore = str_contains($normalized, 'ignore chores')
            || str_contains($normalized, 'ignore chore')
            || str_contains($normalized, 'not chores')
            || str_contains($normalized, 'not chore')
            || str_contains($normalized, 'without chores')
            || str_contains($normalized, 'without chore')
            || str_contains($normalized, 'exclude chores')
            || str_contains($normalized, 'exclude chore')
            || str_contains($normalized, 'no chores')
            || str_contains($normalized, 'skip chores');

        $wantsHealthOrHousehold = ($mentionsHealth && ! $negatesHealth)
            || ($mentionsHousehold && ! $negatesHousehold)
            || ($mentionsChore && ! $negatesChore);

        if ($wantsHealthOrHousehold) {
            $constraints->healthOrHouseholdOnly = true;
            $constraints->requiredTagNames[] = 'Health';
            $constraints->requiredTagNames[] = 'Household';
        }

        $mentionsSchoolOnly = str_contains($normalized, 'school-related')
            || str_contains($normalized, 'school related')
            || str_contains($normalized, 'school only')
            || str_contains($normalized, 'ignore chores')
            || str_contains($normalized, 'not chores')
            || str_contains($normalized, 'ignore personal')
            || str_contains($normalized, 'ignore health')
            || str_contains($normalized, 'ignore household');

        if ($mentionsSchoolOnly) {
            $constraints->schoolOnly = true;
            $constraints->excludedTagNames[] = 'Health';
            $constraints->excludedTagNames[] = 'Household';
        }

        if ($constraints->requiredTagNames !== []) {
            $constraints->requiredTagNames = array_values(array_unique($constraints->requiredTagNames));
        }

        if ($constraints->excludedTagNames !== []) {
            $constraints->excludedTagNames = array_values(array_unique($constraints->excludedTagNames));
        }
    }

    private function applyTaskPropertyConstraints(string $normalized, LlmContextConstraints $constraints): void
    {
        $this->applyTaskStatusConstraints($normalized, $constraints);
        $this->applyTaskPriorityConstraints($normalized, $constraints);
        $this->applyTaskComplexityConstraints($normalized, $constraints);
        $this->applyTaskRecurringConstraints($normalized, $constraints);
        $this->applyTaskDatePresenceConstraints($normalized, $constraints);
    }

    private function applyTaskStatusConstraints(string $normalized, LlmContextConstraints $constraints): void
    {
        $statuses = [];

        if (str_contains($normalized, 'to do')
            || str_contains($normalized, 'todo')
            || str_contains($normalized, 'to-do')
            || str_contains($normalized, 'not started')
        ) {
            $statuses[] = 'to_do';
        }

        if (str_contains($normalized, 'doing')
            || str_contains($normalized, 'in progress')
            || str_contains($normalized, 'ongoing')
        ) {
            $statuses[] = 'doing';
        }

        if (str_contains($normalized, 'done')
            || str_contains($normalized, 'completed')
            || str_contains($normalized, 'finished')
        ) {
            $statuses[] = 'done';
        }

        if ($statuses !== []) {
            $constraints->taskStatuses = array_values(array_unique($statuses));
        }
    }

    private function applyTaskPriorityConstraints(string $normalized, LlmContextConstraints $constraints): void
    {
        $priorities = [];

        if (str_contains($normalized, 'low priority') || str_contains($normalized, 'low-priority')) {
            $priorities[] = 'low';
        }
        if (str_contains($normalized, 'medium priority') || str_contains($normalized, 'medium-priority')) {
            $priorities[] = 'medium';
        }
        if (str_contains($normalized, 'high priority') || str_contains($normalized, 'high-priority')) {
            $priorities[] = 'high';
        }

        // Be conservative with "urgent" so "most to least urgent" does not become a hard filter.
        $mentionsUrgentRanking = str_contains($normalized, 'most urgent')
            || str_contains($normalized, 'least urgent')
            || str_contains($normalized, 'most to least urgent')
            || str_contains($normalized, 'from most to least urgent');
        $mentionsUrgentFilter = str_contains($normalized, 'urgent priority')
            || str_contains($normalized, 'urgent-priority')
            || str_contains($normalized, 'only urgent')
            || str_contains($normalized, 'urgent tasks')
            || str_contains($normalized, 'urgent task');

        if (! $mentionsUrgentRanking && $mentionsUrgentFilter) {
            $priorities[] = 'urgent';
        }

        if ($priorities !== []) {
            $constraints->taskPriorities = array_values(array_unique($priorities));
        }
    }

    private function applyTaskComplexityConstraints(string $normalized, LlmContextConstraints $constraints): void
    {
        $complexities = [];

        if (str_contains($normalized, 'simple complexity')
            || str_contains($normalized, 'simple tasks')
            || str_contains($normalized, 'easy tasks')
        ) {
            $complexities[] = 'simple';
        }
        if (str_contains($normalized, 'moderate complexity')
            || str_contains($normalized, 'medium complexity')
            || str_contains($normalized, 'moderate tasks')
        ) {
            $complexities[] = 'moderate';
        }
        if (str_contains($normalized, 'complex tasks')
            || str_contains($normalized, 'high complexity')
            || str_contains($normalized, 'hard tasks')
        ) {
            $complexities[] = 'complex';
        }

        if ($complexities !== []) {
            $constraints->taskComplexities = array_values(array_unique($complexities));
        }
    }

    private function applyTaskRecurringConstraints(string $normalized, LlmContextConstraints $constraints): void
    {
        if (str_contains($normalized, 'non-recurring')
            || str_contains($normalized, 'not recurring')
            || str_contains($normalized, 'not repeat')
            || str_contains($normalized, 'does not repeat')
        ) {
            $constraints->taskRecurring = false;

            return;
        }

        if (str_contains($normalized, 'recurring')
            || str_contains($normalized, 'repeating')
            || str_contains($normalized, 'repeat tasks')
            || str_contains($normalized, 'repeat task')
        ) {
            $constraints->taskRecurring = true;
        }
    }

    private function applyTaskDatePresenceConstraints(string $normalized, LlmContextConstraints $constraints): void
    {
        if (str_contains($normalized, 'no due date')
            || str_contains($normalized, 'without due date')
            || str_contains($normalized, 'no due dates')
        ) {
            $constraints->taskHasDueDate = false;
        } elseif (str_contains($normalized, 'with due date')
            || str_contains($normalized, 'has due date')
            || str_contains($normalized, 'with deadlines')
        ) {
            $constraints->taskHasDueDate = true;
        }

        if (str_contains($normalized, 'no start date')
            || str_contains($normalized, 'without start date')
            || str_contains($normalized, 'no start dates')
        ) {
            $constraints->taskHasStartDate = false;
        } elseif (str_contains($normalized, 'with start date')
            || str_contains($normalized, 'has start date')
            || str_contains($normalized, 'with start dates')
        ) {
            $constraints->taskHasStartDate = true;
        }

        if (str_contains($normalized, 'no set dates')
            || str_contains($normalized, 'without dates')
            || str_contains($normalized, 'has no dates')
        ) {
            $constraints->taskHasDueDate = false;
            $constraints->taskHasStartDate = false;
        }
    }

    private function applyTimeWindowConstraints(
        string $normalized,
        LlmContextConstraints $constraints,
        CarbonImmutable $now
    ): void {
        if (str_contains($normalized, 'next 7 days')
            || str_contains($normalized, 'next seven days')
            || str_contains($normalized, 'within the next 7 days')
            || str_contains($normalized, 'coming up in the next 7 days')
        ) {
            $constraints->windowStart = $now;
            $constraints->windowEnd = $now->addHours(168);

            return;
        }

        if (str_contains($normalized, 'next three days')
            || str_contains($normalized, 'next 3 days')
        ) {
            $constraints->windowStart = $now;
            $constraints->windowEnd = $now->addDays(3)->endOfDay();

            return;
        }

        if (str_contains($normalized, 'for today only')
            || str_contains($normalized, 'today only')
            || (str_contains($normalized, 'today') && ! str_contains($normalized, 'next'))
        ) {
            $constraints->windowStart = $now->startOfDay();
            $constraints->windowEnd = $now->endOfDay();
            $constraints->includeOverdueInWindow = true;

            return;
        }

        if (str_contains($normalized, 'this week')
            || str_contains($normalized, 'coming week')
        ) {
            $constraints->windowStart = $now->startOfWeek();
            $constraints->windowEnd = $now->endOfWeek();

            return;
        }

        if (str_contains($normalized, 'next 24 hours')
            || str_contains($normalized, 'next twenty four hours')
        ) {
            $constraints->windowStart = $now;
            $constraints->windowEnd = $now->addDay();

            return;
        }

        if (str_contains($normalized, 'tonight')) {
            $start = $now->copy()->setTime(19, 0);
            if ($start->lt($now)) {
                $start = $now;
            }

            $constraints->windowStart = $start;
            $constraints->windowEnd = $now->copy()->endOfDay();
        }
    }
}

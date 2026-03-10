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
            $constraints->requiredTagNames[] = 'Exam';
        }

        $wantsHealthOrHousehold = str_contains($normalized, 'health')
            || str_contains($normalized, 'household')
            || str_contains($normalized, 'chores')
            || str_contains($normalized, 'chore');

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

    private function applyTimeWindowConstraints(
        string $normalized,
        LlmContextConstraints $constraints,
        CarbonImmutable $now
    ): void {
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

<?php

use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Services\Llm\LlmContextConstraintService;
use Carbon\CarbonImmutable;

it('parses quoted tags from tagged as phrasing', function (): void {
    $service = new LlmContextConstraintService;

    $constraints = $service->parse(
        userMessage: 'Look at everything tagged as "Exam" and prioritize it.',
        intent: LlmIntent::PrioritizeAll,
        entityType: LlmEntityType::Task,
        now: CarbonImmutable::parse('2026-03-11 12:00:00'),
    );

    expect($constraints->requiredTagNames)->toContain('Exam');
});

it('does not add generic tags when no tagging language is present', function (): void {
    $service = new LlmContextConstraintService;

    $constraints = $service->parse(
        userMessage: 'Prioritize my tasks for this week.',
        intent: LlmIntent::PrioritizeTasks,
        entityType: LlmEntityType::Task,
        now: CarbonImmutable::parse('2026-03-11 12:00:00'),
    );

    expect($constraints->requiredTagNames)->toBe([]);
});

it('parses task property filters for prioritize prompts', function (): void {
    $service = new LlmContextConstraintService;

    $constraints = $service->parse(
        userMessage: 'Prioritize only high priority recurring tasks with no due date.',
        intent: LlmIntent::PrioritizeTasks,
        entityType: LlmEntityType::Task,
        now: CarbonImmutable::parse('2026-03-11 12:00:00'),
    );

    expect($constraints->taskPriorities)->toBe(['high'])
        ->and($constraints->taskRecurring)->toBeTrue()
        ->and($constraints->taskHasDueDate)->toBeFalse();
});

it('does not treat urgent ranking language as urgent priority filter', function (): void {
    $service = new LlmContextConstraintService;

    $constraints = $service->parse(
        userMessage: 'Prioritize my tasks from most to least urgent.',
        intent: LlmIntent::PrioritizeTasks,
        entityType: LlmEntityType::Task,
        now: CarbonImmutable::parse('2026-03-11 12:00:00'),
    );

    expect($constraints->taskPriorities)->toBe([]);
});

it('treats ignore chores as school-only and not chores-only', function (): void {
    $service = new LlmContextConstraintService;

    $constraints = $service->parse(
        userMessage: 'For today only, what are the top 5 school-related tasks I should focus on? Ignore chores and personal stuff.',
        intent: LlmIntent::PrioritizeTasks,
        entityType: LlmEntityType::Task,
        now: CarbonImmutable::parse('2026-03-12 10:00:00'),
    );

    expect($constraints->schoolOnly)->toBeTrue()
        ->and($constraints->healthOrHouseholdOnly)->toBeFalse()
        ->and($constraints->requiredTagNames)->toBe([])
        ->and($constraints->excludedTagNames)->toContain('Health', 'Household')
        ->and($constraints->includeOverdueInWindow)->toBeTrue();
});

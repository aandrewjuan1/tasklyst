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

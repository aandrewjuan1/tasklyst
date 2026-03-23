<?php

use App\Services\LLM\Prioritization\TaskAssistantTaskChoiceConstraintsExtractor;

it('extracts urgent + today + math keywords', function (): void {
    $extractor = app(TaskAssistantTaskChoiceConstraintsExtractor::class);

    $context = $extractor->extract('I need to prioritize urgent math tasks for today.');

    expect($context['priority_filters'])->toEqual(['urgent']);
    expect($context['task_keywords'])->toContain('math');
    expect($context['time_constraint'])->toBe('today');
    expect($context['comparison_focus'])->toBeNull();
});

it('extracts chores keywords like chores + dishes', function (): void {
    $extractor = app(TaskAssistantTaskChoiceConstraintsExtractor::class);

    $context = $extractor->extract('Please prioritize my chores today, especially wash dishes and take out trash.');

    expect($context['priority_filters'])->toEqual([]);
    expect($context['task_keywords'])->toContain('chores');
    expect($context['task_keywords'])->toContain('dishes');
    expect($context['task_keywords'])->toContain('trash');
    // "chores" should map to your seeded task tags.
    expect($context['task_keywords'])->toContain('household');
    expect($context['task_keywords'])->toContain('health');
    expect($context['time_constraint'])->toBe('today');
});

it('extracts schoolwork keywords and supports this week time constraint', function (): void {
    $extractor = app(TaskAssistantTaskChoiceConstraintsExtractor::class);

    $context = $extractor->extract('Help me prioritize schoolwork for this week.');

    expect($context['priority_filters'])->toEqual([]);
    expect($context['task_keywords'])->toContain('schoolwork');
    expect($context['time_constraint'])->toBe('this_week');
    expect($context['domain_focus'])->toBe('school');
});

it('sets school domain without using bare school as a substring keyword', function (): void {
    $extractor = app(TaskAssistantTaskChoiceConstraintsExtractor::class);

    $context = $extractor->extract('list my school related tasks only for this week');

    expect($context['domain_focus'])->toBe('school');
    expect($context['task_keywords'])->not->toContain('school');
    expect($context['time_constraint'])->toBe('this_week');
});

it('does not set time constraint for unsupported days', function (): void {
    $extractor = app(TaskAssistantTaskChoiceConstraintsExtractor::class);

    $context = $extractor->extract('Prioritize homework next Tuesday.');

    expect($context['priority_filters'])->toEqual([]);
    expect($context['task_keywords'])->toContain('homework');
    expect($context['time_constraint'])->toBeNull();
});

it('extracts science keywords like science subject', function (): void {
    $extractor = app(TaskAssistantTaskChoiceConstraintsExtractor::class);

    $context = $extractor->extract('In my science subject, what should I do first?');

    expect($context['priority_filters'])->toEqual([]);
    expect($context['task_keywords'])->toContain('science');
});

it('detects recurring intent when user mentions recurring tasks', function (): void {
    $extractor = app(TaskAssistantTaskChoiceConstraintsExtractor::class);

    $context = $extractor->extract('In my recurring tasks, what should I do first?');

    expect($context['recurring_requested'])->toBeTrue();
});

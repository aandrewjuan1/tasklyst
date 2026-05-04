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

it('detects school domain for study and revision language', function (): void {
    $extractor = app(TaskAssistantTaskChoiceConstraintsExtractor::class);

    $context = $extractor->extract('I need to study and revise for my midterm this week.');

    expect($context['domain_focus'])->toBe('school');
    expect($context['task_keywords'])->toContain('study');
    expect($context['task_keywords'])->toContain('revise');
    expect($context['time_constraint'])->toBe('this_week');
});

it('detects coursework cues like syllabus and modules', function (): void {
    $extractor = app(TaskAssistantTaskChoiceConstraintsExtractor::class);

    $context = $extractor->extract('Help me prioritize tasks from my syllabus and module review.');

    expect($context['domain_focus'])->toBe('school');
    expect($context['task_keywords'])->toContain('syllabus');
    expect($context['task_keywords'])->toContain('module');
});

it('extracts dynamic keywords and strict mode for related only prompts', function (): void {
    $extractor = app(TaskAssistantTaskChoiceConstraintsExtractor::class);

    $context = $extractor->extract('prioritize my brightspace related tasks only');

    expect($context['task_keywords'])->toContain('brightspace');
    expect($context['strict_filtering'])->toBeTrue();
});

it('does not force school domain when prompt only mentions subjects as generic scope', function (): void {
    $extractor = app(TaskAssistantTaskChoiceConstraintsExtractor::class);

    $context = $extractor->extract('in my programming subjects only what are my top tasks');

    expect($context['domain_focus'])->toBeNull();
    expect($context['task_keywords'])->toContain('programming');
    expect($context['strict_filtering'])->toBeTrue();
});

it('treats science task phrasing as explicit filter intent', function (): void {
    $extractor = app(TaskAssistantTaskChoiceConstraintsExtractor::class);

    $context = $extractor->extract('what are my top 1 science task');

    expect($context['task_keywords'])->toContain('science');
    expect($context['strict_filtering'])->toBeTrue();
});

it('does not leak conversational filler words into dynamic keywords', function (): void {
    $extractor = app(TaskAssistantTaskChoiceConstraintsExtractor::class);

    $context = $extractor->extract('i said top 1 science related task');

    expect($context['task_keywords'])->toContain('science');
    expect($context['task_keywords'])->not->toContain('said');
});

it('does not force school domain for thesis-only filter wording', function (): void {
    $extractor = app(TaskAssistantTaskChoiceConstraintsExtractor::class);

    $context = $extractor->extract('in my thesis related task what should i do first');

    expect($context['task_keywords'])->toContain('thesis');
    expect($context['domain_focus'])->toBeNull();
    expect($context['strict_filtering'])->toBeTrue();
});

<?php

use App\DataTransferObjects\Llm\LlmInferenceResult;
use App\DataTransferObjects\Llm\RecommendationDisplayDto;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Services\Llm\RecommendationDisplayBuilder;

test('build returns display dto with validation confidence for prioritization', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'Focus on overdue first.',
            'reasoning' => 'Step 1: Check overdue. Step 2: Rank by due date.',
            'ranked_tasks' => [
                ['rank' => 1, 'title' => 'Task A', 'end_datetime' => now()->addDay()->toIso8601String()],
                ['rank' => 2, 'title' => 'Task B', 'end_datetime' => null],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 100,
        completionTokens: 50,
        usedFallback: false
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeTasks, LlmEntityType::Task);

    expect($dto)->toBeInstanceOf(RecommendationDisplayDto::class)
        ->and($dto->intent)->toBe(LlmIntent::PrioritizeTasks)
        ->and($dto->entityType)->toBe(LlmEntityType::Task)
        ->and($dto->recommendedAction)->toBe('Focus on overdue first.')
        ->and($dto->reasoning)->toContain('Step 1')
        ->and($dto->message)->toContain('Focus on overdue first.')
        ->and($dto->message)->toContain('Step 1')
        ->and($dto->message)->toContain('#1')
        ->and($dto->message)->toContain('Task A')
        ->and($dto->message)->toContain('#2')
        ->and($dto->message)->toContain('Task B')
        ->and($dto->validationConfidence)->toBeGreaterThan(0)
        ->and($dto->usedFallback)->toBeFalse()
        ->and($dto->structured)->toHaveKey('ranked_tasks')
        ->and($dto->structured['ranked_tasks'])->toHaveCount(2);
});

test('build computes validation confidence for schedule task with dates and priority', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'Schedule for Friday 2pm.',
            'reasoning' => 'Based on your calendar.',
            'start_datetime' => now()->next('Friday')->setTime(14, 0)->toIso8601String(),
            'end_datetime' => now()->next('Friday')->setTime(15, 0)->toIso8601String(),
            'priority' => 'high',
        ],
        promptVersion: '1.0',
        promptTokens: 80,
        completionTokens: 40,
        usedFallback: false
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::ScheduleTask, LlmEntityType::Task);

    expect($dto->validationConfidence)->toBeGreaterThan(0.5)
        ->and($dto->structured)->toHaveKey('start_datetime')
        ->and($dto->structured)->toHaveKey('end_datetime')
        ->and($dto->structured)->toHaveKey('priority');
});

test('build uses fallback flag from inference result', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'Rule-based order.',
            'reasoning' => 'AI unavailable.',
            'ranked_tasks' => [],
        ],
        promptVersion: '1.0',
        promptTokens: 0,
        completionTokens: 0,
        usedFallback: true
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeTasks, LlmEntityType::Task);

    expect($dto->usedFallback)->toBeTrue();
});

test('build fills default content when recommended action or reasoning empty', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => '',
            'reasoning' => '',
        ],
        promptVersion: '1.0',
        promptTokens: 50,
        completionTokens: 10,
        usedFallback: false
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::GeneralQuery, LlmEntityType::Task);

    expect($dto->recommendedAction)->not->toBeEmpty()
        ->and($dto->reasoning)->not->toBeEmpty()
        ->and($dto->message)->not->toBeEmpty();
});

test('build includes next_steps for resolve_dependency', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'Start with the blocker first.',
            'reasoning' => 'Unblocks everything else.',
            'next_steps' => [
                'Email your tutor for feedback.',
                'Update the outline based on feedback.',
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 100,
        completionTokens: 50,
        usedFallback: false
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::ResolveDependency, LlmEntityType::Task);

    expect($dto->structured)->toHaveKey('next_steps')
        ->and($dto->structured['next_steps'])->toHaveCount(2)
        ->and($dto->validationConfidence)->toBeGreaterThan(0.5)
        ->and($dto->message)->toContain('Start with the blocker first.')
        ->and($dto->message)->toContain('Unblocks everything else.')
        ->and($dto->message)->toContain('1.')
        ->and($dto->message)->toContain('Email your tutor for feedback.')
        ->and($dto->message)->toContain('2.')
        ->and($dto->message)->toContain('Update the outline based on feedback.');
});

test('build includes ranked_events in message for prioritize_events', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'event',
            'recommended_action' => 'Prioritize these upcoming events you have scheduled:',
            'reasoning' => 'To effectively manage your time, focus on the soonest first.',
            'ranked_events' => [
                ['rank' => 1, 'title' => 'Conference call'],
                ['rank' => 2, 'title' => 'Dentist appointment'],
                ['rank' => 3, 'title' => '23 BDAY'],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 100,
        completionTokens: 60,
        usedFallback: false
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeEvents, LlmEntityType::Event);

    expect($dto->message)->toContain('Prioritize these upcoming events you have scheduled:')
        ->and($dto->message)->toContain('#1')
        ->and($dto->message)->toContain('Conference call')
        ->and($dto->message)->toContain('#2')
        ->and($dto->message)->toContain('Dentist appointment')
        ->and($dto->message)->toContain('#3')
        ->and($dto->message)->toContain('23 BDAY')
        ->and($dto->message)->toContain('To effectively manage your time');
});

test('build combined message puts action first then reasoning with natural connector', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'Focus on the essay due tomorrow.',
            'reasoning' => 'It has the nearest deadline and is high priority.',
            'ranked_tasks' => [['rank' => 1, 'title' => 'Essay', 'end_datetime' => null]],
        ],
        promptVersion: '1.0',
        promptTokens: 80,
        completionTokens: 40,
        usedFallback: false
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::PrioritizeTasks, LlmEntityType::Task);

    expect($dto->message)->toContain('Focus on the essay due tomorrow.')
        ->and($dto->message)->toContain('It has the nearest deadline')
        ->and($dto->message)->not->toContain('Recommended action:')
        ->and($dto->message)->not->toContain('Reasoning:');
});

test('RecommendationDisplayDto toArray and fromArray include message', function (): void {
    $dto = RecommendationDisplayDto::fromArray([
        'intent' => 'prioritize_tasks',
        'entity_type' => 'task',
        'recommended_action' => 'Do A first.',
        'reasoning' => 'Because it is urgent.',
        'message' => 'Do A first. Here\'s why: Because it is urgent.',
        'validation_confidence' => 0.9,
        'used_fallback' => false,
        'fallback_reason' => null,
        'structured' => [],
    ]);

    $arr = $dto->toArray();
    expect($arr)->toHaveKey('message')
        ->and($arr['message'])->toBe('Do A first. Here\'s why: Because it is urgent.');

    $restored = RecommendationDisplayDto::fromArray($arr);
    expect($restored->message)->toBe($dto->message);
});

test('build formats message with listed_items as summary then bullet list then reasoning', function (): void {
    $result = new LlmInferenceResult(
        structured: [
            'entity_type' => 'task',
            'recommended_action' => 'Here are your tasks with no due date.',
            'reasoning' => 'These three tasks have end_datetime null in context.',
            'listed_items' => [
                ['title' => 'Task A'],
                ['title' => 'Task B', 'priority' => 'low'],
                ['title' => 'Task C', 'end_datetime' => null],
            ],
        ],
        promptVersion: '1.0',
        promptTokens: 80,
        completionTokens: 60,
        usedFallback: false
    );

    $builder = app(RecommendationDisplayBuilder::class);
    $dto = $builder->build($result, LlmIntent::GeneralQuery, LlmEntityType::Task);

    expect($dto->message)->toContain('Here are your tasks with no due date.')
        ->and($dto->message)->toContain('Task A')
        ->and($dto->message)->toContain('Task B')
        ->and($dto->message)->toContain('Task C')
        ->and($dto->message)->toContain('These three tasks')
        ->and($dto->structured)->toHaveKey('listed_items')
        ->and($dto->structured['listed_items'])->toHaveCount(3);
});

test('RecommendationDisplayDto fromArray builds message from action and reasoning when message missing', function (): void {
    $dto = RecommendationDisplayDto::fromArray([
        'intent' => 'general_query',
        'entity_type' => 'task',
        'recommended_action' => 'Add a due date to the task.',
        'reasoning' => 'That will help you prioritize.',
        'validation_confidence' => 0.8,
        'used_fallback' => false,
        'structured' => [],
    ]);

    expect($dto->message)->toContain('Add a due date to the task.')
        ->and($dto->message)->toContain('That will help you prioritize.')
        ->and($dto->message)->toContain("\n\n");
});

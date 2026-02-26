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
        ->and($dto->reasoning)->not->toBeEmpty();
});

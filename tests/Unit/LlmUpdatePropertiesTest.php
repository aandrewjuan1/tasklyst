<?php

use App\DataTransferObjects\Llm\EventUpdatePropertiesRecommendationDto;
use App\DataTransferObjects\Llm\ProjectUpdatePropertiesRecommendationDto;
use App\DataTransferObjects\Llm\TaskUpdatePropertiesRecommendationDto;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Services\Llm\RecommendationDisplayBuilder;

it('builds appliable changes for task update properties intent', function (): void {
    $builder = app(RecommendationDisplayBuilder::class);

    $structured = [
        'entity_type' => 'task',
        'recommended_action' => 'I recommend lowering the priority and shortening the duration.',
        'reasoning' => 'Because you mentioned this task feels too heavy, reducing duration and priority will make it more manageable.',
        'confidence' => 0.9,
        'properties' => [
            'priority' => 'low',
            'duration' => 30,
        ],
    ];

    $result = $builder->build(
        new \App\DataTransferObjects\Llm\LlmInferenceResult(
            structured: $structured,
            promptVersion: 'test',
            promptTokens: 0,
            completionTokens: 0,
            usedFallback: false,
            fallbackReason: null,
        ),
        LlmIntent::UpdateTaskProperties,
        LlmEntityType::Task
    );

    $changes = $result->appliableChanges;

    expect($changes)->toHaveKey('entity_type', 'task')
        ->and($changes)->toHaveKey('properties')
        ->and($changes['properties'])->toMatchArray([
            'priority' => 'low',
            'duration' => 30,
        ]);
});

it('parses task update properties dto from structured payload', function (): void {
    $structured = [
        'reasoning' => 'Because the task is small, you can mark it as easy and set duration to 15 minutes.',
        'confidence' => 0.8,
        'properties' => [
            'complexity' => 'EASY',
            'duration' => '15',
            'unknown_field' => 'should be ignored',
        ],
    ];

    $dto = TaskUpdatePropertiesRecommendationDto::fromStructured($structured);

    expect($dto)->not->toBeNull()
        ->and($dto->reasoning)->toBeString()
        ->and($dto->proposedProperties())->toMatchArray([
            'complexity' => 'easy',
            'duration' => 15,
        ])
        ->and($dto->proposedProperties())->not->toHaveKey('unknown_field');
});

it('parses event update properties dto from structured payload', function (): void {
    $structured = [
        'reasoning' => 'Because this will take the full day, you should make it all-day.',
        'confidence' => 0.75,
        'properties' => [
            'allDay' => true,
            'title' => 'Updated Event Title',
        ],
    ];

    $dto = EventUpdatePropertiesRecommendationDto::fromStructured($structured);

    expect($dto)->not->toBeNull()
        ->and($dto->proposedProperties())->toMatchArray([
            'allDay' => true,
            'title' => 'Updated Event Title',
        ]);
});

it('parses project update properties dto from structured payload', function (): void {
    $structured = [
        'reasoning' => 'Because the project scope expanded, a new name and end date help clarify it.',
        'confidence' => 0.82,
        'properties' => [
            'name' => 'New Project Name',
            'endDatetime' => '2030-01-01T10:00:00+00:00',
            'ignoredField' => 'should not be included',
        ],
    ];

    $dto = ProjectUpdatePropertiesRecommendationDto::fromStructured($structured);

    expect($dto)->not->toBeNull()
        ->and($dto->proposedProperties())->toHaveKey('name', 'New Project Name')
        ->and($dto->proposedProperties())->toHaveKey('endDatetime', '2030-01-01T10:00:00+00:00')
        ->and($dto->proposedProperties())->not->toHaveKey('ignoredField');
});

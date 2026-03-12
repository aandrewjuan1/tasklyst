<?php

use App\Actions\Llm\ClassifyLlmIntentAction;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Enums\LlmOperationMode;

beforeEach(function (): void {
    config([
        'tasklyst.intent.use_llm_fallback' => false,
    ]);
});

test('classifies schedule task intent', function (): void {
    $result = app(ClassifyLlmIntentAction::class)->execute('Schedule my dashboard task by Friday');

    expect($result->operationMode)->toBe(LlmOperationMode::Schedule)
        ->and($result->intent)->toBe(LlmIntent::ScheduleTask)
        ->and($result->entityType)->toBe(LlmEntityType::Task);
});

test('classifies multi-task time-window planning as schedule_tasks', function (): void {
    $result = app(ClassifyLlmIntentAction::class)->execute('From 7pm to 11pm tonight, create a realistic plan using my existing tasks. Include at least one break and don’t schedule more than 3 hours of focused work.');

    expect($result->operationMode)->toBe(LlmOperationMode::Schedule)
        ->and($result->intent)->toBe(LlmIntent::ScheduleTasks)
        ->and($result->entityType)->toBe(LlmEntityType::Multiple);
});

test('classifies prioritize events intent', function (): void {
    $result = app(ClassifyLlmIntentAction::class)->execute('Which events are most important this week?');

    expect($result->operationMode)->toBe(LlmOperationMode::Prioritize)
        ->and($result->intent)->toBe(LlmIntent::PrioritizeEvents)
        ->and($result->entityType)->toBe(LlmEntityType::Event);
});

test('classifies adjust project timeline intent via schedule mode aliasing', function (): void {
    $result = app(ClassifyLlmIntentAction::class)->execute('Can we extend the website project timeline?');

    expect($result->operationMode)->toBe(LlmOperationMode::Schedule)
        ->and($result->intent)->toBe(LlmIntent::AdjustProjectTimeline)
        ->and($result->entityType)->toBe(LlmEntityType::Project);
});

test('classifies general query and includes canonical fields in toArray', function (): void {
    $result = app(ClassifyLlmIntentAction::class)->execute('What is the weather tomorrow?');
    $arr = $result->toArray();

    expect($result->intent)->toBe(LlmIntent::GeneralQuery)
        ->and($arr)->toHaveKeys(['intent', 'entity_type', 'confidence', 'operation_mode', 'entity_scope', 'entity_targets'])
        ->and($arr['operation_mode'])->toBe('general')
        ->and($arr['entity_scope'])->toBe('task');
});

test('classifies list filter search prompts into dedicated mode', function (): void {
    $result = app(ClassifyLlmIntentAction::class)->execute('Show only my exam-related tasks and events for this week.');
    $eventsOnly = app(ClassifyLlmIntentAction::class)->execute('Filter to events only and show what’s coming up in the next 7 days.');

    expect($result->operationMode)->toBe(LlmOperationMode::ListFilterSearch)
        ->and($result->intent)->toBe(LlmIntent::ListFilterSearch)
        ->and($result->entityType)->toBe(LlmEntityType::Multiple)
        ->and($eventsOnly->operationMode)->toBe(LlmOperationMode::ListFilterSearch)
        ->and($eventsOnly->intent)->toBe(LlmIntent::ListFilterSearch)
        ->and($eventsOnly->entityType)->toBe(LlmEntityType::Event);
});

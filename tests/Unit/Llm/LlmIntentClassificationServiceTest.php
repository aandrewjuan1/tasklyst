<?php

use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Enums\LlmOperationMode;
use App\Services\LlmIntentClassificationService;

it('classifies prioritize task query with canonical mode and scope', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('Can you prioritise my tasks for today?');

    expect($result->operationMode)->toBe(LlmOperationMode::Prioritize)
        ->and($result->entityType)->toBe(LlmEntityType::Task)
        ->and($result->intent)->toBe(LlmIntent::PrioritizeTasks);
});

it('classifies schedule query even when list language exists', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('show me my tasks and schedule the top 1 for later');

    expect($result->operationMode)->toBe(LlmOperationMode::Schedule)
        ->and($result->entityType)->toBe(LlmEntityType::Task)
        ->and($result->intent)->toBe(LlmIntent::ScheduleTask);
});

it('classifies time-window planning as multi schedule tasks alias', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('From 7pm to 11pm tonight, create a realistic plan using my existing tasks. Include at least one break and don’t schedule more than 3 hours of focused work.');

    expect($result->operationMode)->toBe(LlmOperationMode::Schedule)
        ->and($result->entityType)->toBe(LlmEntityType::Multiple)
        ->and($result->intent)->toBe(LlmIntent::ScheduleTasks);
});

it('maps multi-entity schedule requests to task-only multi scheduling intent', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('Schedule all my items for this week');

    expect($result->operationMode)->toBe(LlmOperationMode::Schedule)
        ->and($result->entityType)->toBe(LlmEntityType::Multiple)
        ->and($result->intent)->toBe(LlmIntent::ScheduleTasks);
});

it('classifies adjust-like event query to adjust_event_time alias', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('Can we move the team meeting to Thursday?');

    expect($result->operationMode)->toBe(LlmOperationMode::Schedule)
        ->and($result->entityType)->toBe(LlmEntityType::Event)
        ->and($result->intent)->toBe(LlmIntent::AdjustEventTime);
});

it('classifies list and delete queries as general mode', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $list = $service->classify('Show me all my tasks for this week');
    $delete = $service->classify('if I can delete 1 task, what task should I delete?');

    expect($list->operationMode)->toBe(LlmOperationMode::General)
        ->and($list->intent)->toBe(LlmIntent::GeneralQuery)
        ->and($delete->operationMode)->toBe(LlmOperationMode::General)
        ->and($delete->intent)->toBe(LlmIntent::GeneralQuery);
});

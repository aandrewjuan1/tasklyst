<?php

use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Services\LlmIntentClassificationService;

it('does not treat substrings inside words as intent keywords', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('standby');

    expect($result->intent)->toBe(LlmIntent::GeneralQuery)
        ->and($result->entityType)->toBe(LlmEntityType::Task);
});

it('classifies prioritisation queries with reasonable confidence', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('Can you prioritise my tasks for today?');

    expect($result->intent)->toBe(LlmIntent::PrioritizeTasks)
        ->and($result->entityType)->toBe(LlmEntityType::Task)
        ->and($result->confidence)->toBeGreaterThanOrEqual(0.6);
});

it('classifies follow-up event prioritisation from context keywords', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('how about in events?');

    expect($result->intent)->toBe(LlmIntent::PrioritizeEvents)
        ->and($result->entityType)->toBe(LlmEntityType::Event);
});

it('classifies what to attend first as event prioritisation', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('what to attend first in my events?');

    expect($result->intent)->toBe(LlmIntent::PrioritizeEvents)
        ->and($result->entityType)->toBe(LlmEntityType::Event);
});

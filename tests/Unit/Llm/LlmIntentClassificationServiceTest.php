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

it('classifies which task to delete or remove as general_query', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('if I can delete 1 task, what task should I delete?');

    expect($result->intent)->toBe(LlmIntent::GeneralQuery)
        ->and($result->entityType)->toBe(LlmEntityType::Task);
});

it('classifies meta or complaint questions about the assistant as general_query', function (string $message): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify($message);

    expect($result->intent)->toBe(LlmIntent::GeneralQuery);
})->with([
    'why did you not answer' => ['why did you not answer it the first time? are you hallucinating?'],
    'too complex too hard' => ['when the query is too complex its too hard for you?'],
]);

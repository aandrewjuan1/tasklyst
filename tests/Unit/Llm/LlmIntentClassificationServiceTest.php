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

it('classifies tasks with no set dates as general_query', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('in all of my tasks list me the tasks that has no set dates');

    expect($result->intent)->toBe(LlmIntent::GeneralQuery)
        ->and($result->entityType)->toBe(LlmEntityType::Task);
});

it('classifies show me my tasks for this week as general_query', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('Show me all my tasks for this week');

    expect($result->intent)->toBe(LlmIntent::GeneralQuery)
        ->and($result->entityType)->toBe(LlmEntityType::Task);
});

it('classifies previous list schedule the top 1 for today as schedule_task', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('in previous list schedule the top 1 for today');

    expect($result->intent)->toBe(LlmIntent::ScheduleTask)
        ->and($result->entityType)->toBe(LlmEntityType::Task);
});

it('classifies show me my tasks and schedule top 1 as schedule_task', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('show me my tasks and schedule the top 1 for later');

    expect($result->intent)->toBe(LlmIntent::ScheduleTask)
        ->and($result->entityType)->toBe(LlmEntityType::Task);
});

it('classifies list my top tasks asap as prioritize_tasks', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('list me my top 5 tasks that i need to do asap');

    expect($result->intent)->toBe(LlmIntent::PrioritizeTasks)
        ->and($result->entityType)->toBe(LlmEntityType::Task);
});

it('classifies give me all low priority tasks as general_query with high confidence', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('give me all low priority tasks');

    expect($result->intent)->toBe(LlmIntent::GeneralQuery)
        ->and($result->entityType)->toBe(LlmEntityType::Task)
        ->and($result->confidence)->toBeGreaterThanOrEqual(0.8);
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

it('classifies prioritize both tasks and events as PrioritizeTasksAndEvents with Multiple entity type', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('prioritize both my tasks and events');

    expect($result->intent)->toBe(LlmIntent::PrioritizeTasksAndEvents)
        ->and($result->entityType)->toBe(LlmEntityType::Multiple)
        ->and($result->confidence)->toBeGreaterThanOrEqual(0.7);
});

it('classifies rank my tasks and events as PrioritizeTasksAndEvents when both and connector present', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('rank my tasks and events');

    expect($result->intent)->toBe(LlmIntent::PrioritizeTasksAndEvents)
        ->and($result->entityType)->toBe(LlmEntityType::Multiple);
});

it('classifies prioritize both tasks and projects as PrioritizeTasksAndProjects with Multiple entity type', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('prioritize both my tasks and projects');

    expect($result->intent)->toBe(LlmIntent::PrioritizeTasksAndProjects)
        ->and($result->entityType)->toBe(LlmEntityType::Multiple)
        ->and($result->confidence)->toBeGreaterThanOrEqual(0.7);
});

it('classifies prioritize events and projects as PrioritizeEventsAndProjects with Multiple entity type', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('prioritize events and projects');

    expect($result->intent)->toBe(LlmIntent::PrioritizeEventsAndProjects)
        ->and($result->entityType)->toBe(LlmEntityType::Multiple)
        ->and($result->confidence)->toBeGreaterThanOrEqual(0.7);
});

it('classifies prioritize all my items as PrioritizeAll with Multiple entity type', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('prioritize all my items');

    expect($result->intent)->toBe(LlmIntent::PrioritizeAll)
        ->and($result->entityType)->toBe(LlmEntityType::Multiple)
        ->and($result->confidence)->toBeGreaterThanOrEqual(0.7);
});

it('classifies in my tasks events projects what should I do first as PrioritizeAll with Multiple', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('in my tasks, events, projects what should I do first?');

    expect($result->intent)->toBe(LlmIntent::PrioritizeAll)
        ->and($result->entityType)->toBe(LlmEntityType::Multiple);
});

it('classifies schedule both my tasks and events as ScheduleTasksAndEvents with Multiple', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('schedule both my tasks and events');

    expect($result->intent)->toBe(LlmIntent::ScheduleTasksAndEvents)
        ->and($result->entityType)->toBe(LlmEntityType::Multiple)
        ->and($result->confidence)->toBeGreaterThanOrEqual(0.7);
});

it('classifies schedule tasks and projects as ScheduleTasksAndProjects with Multiple', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('schedule tasks and projects');

    expect($result->intent)->toBe(LlmIntent::ScheduleTasksAndProjects)
        ->and($result->entityType)->toBe(LlmEntityType::Multiple)
        ->and($result->confidence)->toBeGreaterThanOrEqual(0.7);
});

it('classifies schedule all my items as ScheduleAll with Multiple', function (): void {
    /** @var LlmIntentClassificationService $service */
    $service = app(LlmIntentClassificationService::class);

    $result = $service->classify('schedule all my items');

    expect($result->intent)->toBe(LlmIntent::ScheduleAll)
        ->and($result->entityType)->toBe(LlmEntityType::Multiple)
        ->and($result->confidence)->toBeGreaterThanOrEqual(0.7);
});

<?php

use App\Services\LLM\Prioritization\TaskPrioritizationService;
use App\Services\LLM\TaskAssistant\TaskAssistantPromptData;
use App\Services\LLM\TaskAssistant\TaskAssistantResponseValidator;
use App\Services\LLM\TaskAssistant\TaskAssistantSnapshotService;
use App\Services\LLM\TaskAssistant\TaskAssistantTaskChoiceConstraintsExtractor;
use App\Services\LLM\TaskAssistant\TaskAssistantTaskChoiceRunner;
use App\Services\RecurrenceExpander;

it('buckets due dates using the provided timezone-aware now', function (): void {
    $runner = new TaskAssistantTaskChoiceRunner(
        app(TaskAssistantPromptData::class),
        app(TaskAssistantSnapshotService::class),
        app(TaskAssistantResponseValidator::class),
        app(TaskPrioritizationService::class),
        app(TaskAssistantTaskChoiceConstraintsExtractor::class),
        app(RecurrenceExpander::class),
    );

    $method = new ReflectionMethod($runner, 'resolveDueBucket');
    $method->setAccessible(true);

    $now = new DateTimeImmutable('2026-03-20 12:00:00', new DateTimeZone('Asia/Manila')); // UTC+8

    expect($method->invoke($runner, '2026-03-20T23:00:00+08:00', $now))->toBe('today');
    expect($method->invoke($runner, '2026-03-21T10:00:00+08:00', $now))->toBe('tomorrow');
    expect($method->invoke($runner, '2026-03-15T10:00:00+08:00', $now))->toBe('overdue');
});

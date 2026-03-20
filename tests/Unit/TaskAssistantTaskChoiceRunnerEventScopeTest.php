<?php

use App\Services\LLM\Prioritization\TaskPrioritizationService;
use App\Services\LLM\TaskAssistant\TaskAssistantPromptData;
use App\Services\LLM\TaskAssistant\TaskAssistantResponseValidator;
use App\Services\LLM\TaskAssistant\TaskAssistantSnapshotService;
use App\Services\LLM\TaskAssistant\TaskAssistantTaskChoiceConstraintsExtractor;
use App\Services\LLM\TaskAssistant\TaskAssistantTaskChoiceRunner;

it('treats appointment/class/interview language as event scope', function (): void {
    $runner = new TaskAssistantTaskChoiceRunner(
        app(TaskAssistantPromptData::class),
        app(TaskAssistantSnapshotService::class),
        app(TaskAssistantResponseValidator::class),
        app(TaskPrioritizationService::class),
        app(TaskAssistantTaskChoiceConstraintsExtractor::class),
    );

    $method = new ReflectionMethod($runner, 'userExplicitlyRequestsEventsOrCalendar');
    $method->setAccessible(true);

    expect($method->invoke($runner, 'I have an appointment soon—what should I prep for next?'))->toBeTrue();
    expect($method->invoke($runner, 'My class starts tomorrow—what should I do next?'))->toBeTrue();
    expect($method->invoke($runner, 'I have a meeting today—what should I do next?'))->toBeTrue();

    expect($method->invoke($runner, 'What should I do next from my tasks?'))->toBeFalse();
});

<?php

use App\Services\LLM\TaskAssistant\TaskAssistantService;

it('treats "list my most important tasks" as a list request', function (): void {
    $service = app(TaskAssistantService::class);

    $method = new \ReflectionMethod(TaskAssistantService::class, 'isListTasksRequest');
    $method->setAccessible(true);

    $result = $method->invoke($service, 'List my most important tasks');

    expect($result)->toBeTrue();
});

it('does not treat "which task should i do first" as a list request', function (): void {
    $service = app(TaskAssistantService::class);

    $method = new \ReflectionMethod(TaskAssistantService::class, 'isListTasksRequest');
    $method->setAccessible(true);

    $result = $method->invoke($service, 'Which task should i do first?');

    expect($result)->toBeFalse();
});

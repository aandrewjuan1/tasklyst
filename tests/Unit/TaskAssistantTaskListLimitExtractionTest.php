<?php

use App\Services\LLM\TaskAssistant\TaskAssistantService;

it('defaults task listing count to 5', function (): void {
    $service = app(TaskAssistantService::class);

    $method = new \ReflectionMethod(TaskAssistantService::class, 'extractRequestedTaskCount');
    $method->setAccessible(true);

    $count = $method->invoke($service, 'list my tasks', 5);

    expect($count)->toBe(5);
});

it('extracts explicit “top N” counts (top 3)', function (): void {
    $service = app(TaskAssistantService::class);

    $method = new \ReflectionMethod(TaskAssistantService::class, 'extractRequestedTaskCount');
    $method->setAccessible(true);

    $count = $method->invoke($service, 'list my top 3 tasks only', 5);

    expect($count)->toBe(3);
});

it('extracts “at most N” counts', function (): void {
    $service = app(TaskAssistantService::class);

    $method = new \ReflectionMethod(TaskAssistantService::class, 'extractRequestedTaskCount');
    $method->setAccessible(true);

    $count = $method->invoke($service, 'show at most 7 tasks', 5);

    expect($count)->toBe(7);
});

it('caps explicit counts to max 20', function (): void {
    $service = app(TaskAssistantService::class);

    $method = new \ReflectionMethod(TaskAssistantService::class, 'extractRequestedTaskCount');
    $method->setAccessible(true);

    $count = $method->invoke($service, 'show top 999 tasks', 5);

    expect($count)->toBe(20);
});

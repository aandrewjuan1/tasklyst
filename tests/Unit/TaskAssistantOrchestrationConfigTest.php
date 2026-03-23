<?php

use App\Events\TaskAssistantJsonDelta;
use App\Events\TaskAssistantStreamEnd;
use App\Events\TaskAssistantToolCall;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use Illuminate\Broadcasting\PrivateChannel;

test('assistant broadcast events use private channels', function (): void {
    $deltaChannel = (new TaskAssistantJsonDelta(1, 'chunk'))->broadcastOn();
    $endChannel = (new TaskAssistantStreamEnd(1))->broadcastOn();
    $toolChannel = (new TaskAssistantToolCall(1, 'abc', 'list_tasks', []))->broadcastOn();

    expect($deltaChannel)->toBeInstanceOf(PrivateChannel::class);
    expect($endChannel)->toBeInstanceOf(PrivateChannel::class);
    expect($toolChannel)->toBeInstanceOf(PrivateChannel::class);
});

test('service resolves route tool allowlist from config', function (): void {
    config()->set('prism-tools', [
        'list_tasks' => \App\Tools\LLM\TaskAssistant\ListTasksTool::class,
    ]);
    config()->set('task-assistant.tools.routes.chat', ['list_tasks']);
    config()->set('task-assistant.tools.routes.listing', []);
    config()->set('task-assistant.tools.routes.prioritize', []);

    $service = app(TaskAssistantService::class);
    $method = new ReflectionMethod($service, 'resolveToolsForRoute');
    $method->setAccessible(true);
    $user = User::factory()->create();

    $chatTools = $method->invoke($service, $user, 'chat');
    $listingTools = $method->invoke($service, $user, 'listing');
    $prioritizeTools = $method->invoke($service, $user, 'prioritize');

    expect($chatTools)->toHaveCount(1);
    expect($listingTools)->toHaveCount(0);
    expect($prioritizeTools)->toHaveCount(0);
});

test('service reads route-specific generation client options', function (): void {
    config()->set('task-assistant.generation.temperature', 0.3);
    config()->set('task-assistant.generation.max_tokens', 1000);
    config()->set('task-assistant.generation.top_p', 0.9);
    config()->set('task-assistant.generation.chat.temperature', 0.2);
    config()->set('task-assistant.generation.chat.max_tokens', 700);
    config()->set('task-assistant.generation.chat.top_p', 0.85);
    config()->set('prism.request_timeout', 99);

    $service = app(TaskAssistantService::class);
    $method = new ReflectionMethod($service, 'resolveClientOptionsForRoute');
    $method->setAccessible(true);
    $options = $method->invoke($service, 'chat');

    expect($options['timeout'])->toBe(99);
    expect($options['temperature'])->toBe(0.2);
    expect($options['max_tokens'])->toBe(700);
    expect($options['top_p'])->toBe(0.85);

    config()->set('task-assistant.generation.listing.temperature', 0.15);
    config()->set('task-assistant.generation.listing.max_tokens', 800);
    config()->set('task-assistant.generation.listing.top_p', 0.88);

    $listingOptions = $method->invoke($service, 'listing');

    expect($listingOptions['temperature'])->toBe(0.15);
    expect($listingOptions['max_tokens'])->toBe(800);
    expect($listingOptions['top_p'])->toBe(0.88);
});

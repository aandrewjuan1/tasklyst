<?php

use App\Events\TaskAssistantJsonDelta;
use App\Events\TaskAssistantStreamEnd;
use App\Events\TaskAssistantToolCall;
use Illuminate\Broadcasting\PrivateChannel;

test('assistant broadcast events use private channels', function (): void {
    $deltaChannel = (new TaskAssistantJsonDelta(1, 1, 'chunk'))->broadcastOn();
    $endChannel = (new TaskAssistantStreamEnd(1, 1))->broadcastOn();
    $toolChannel = (new TaskAssistantToolCall(1, 'abc', 'list_tasks', []))->broadcastOn();

    expect($deltaChannel)->toBeInstanceOf(PrivateChannel::class);
    expect($endChannel)->toBeInstanceOf(PrivateChannel::class);
    expect($toolChannel)->toBeInstanceOf(PrivateChannel::class);
});

test('legacy chat flow configuration is absent', function (): void {
    $routes = config('task-assistant.tools.routes');
    expect($routes)->toBeArray();
    expect($routes)->not->toHaveKey('chat');

    $generation = config('task-assistant.generation');
    expect($generation)->toBeArray();
    expect($generation)->not->toHaveKey('chat');
});

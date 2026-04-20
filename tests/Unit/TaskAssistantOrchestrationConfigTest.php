<?php

use App\Events\TaskAssistantJsonDelta;
use App\Events\TaskAssistantStreamEnd;
use Illuminate\Broadcasting\PrivateChannel;

test('assistant broadcast events use private channels', function (): void {
    $deltaChannel = (new TaskAssistantJsonDelta(1, 1, 'chunk'))->broadcastOn();
    $endChannel = (new TaskAssistantStreamEnd(1, 1))->broadcastOn();

    expect($deltaChannel)->toBeInstanceOf(PrivateChannel::class);
    expect($endChannel)->toBeInstanceOf(PrivateChannel::class);
});

test('legacy chat flow configuration is absent', function (): void {
    $generation = config('task-assistant.generation');
    expect($generation)->toBeArray();
    expect($generation)->not->toHaveKey('chat');
});

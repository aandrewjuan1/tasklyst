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

test('streaming configuration exposes optimization toggles', function (): void {
    $streaming = config('task-assistant.streaming');

    expect($streaming)->toBeArray();
    expect($streaming)->toHaveKeys([
        'chunk_size',
        'enable_typing_effect',
        'inter_chunk_delay_ms',
        'max_typing_effect_ms',
        'health_timeout_seconds',
        'stop_check_interval_chunks',
        'stop_check_min_interval_ms',
        'log_structured_envelope',
    ]);
});

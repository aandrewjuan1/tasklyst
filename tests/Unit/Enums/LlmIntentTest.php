<?php

use App\Enums\LlmIntent;

test('allowed values includes all expected intents', function (): void {
    expect(LlmIntent::allowedValues())->toBe([
        'schedule',
        'create',
        'update',
        'prioritize',
        'list',
        'general',
        'clarify',
        'error',
    ]);
});

test('can trigger tool call is true only for write intents', function (): void {
    expect(LlmIntent::Schedule->canTriggerToolCall())->toBeTrue()
        ->and(LlmIntent::Create->canTriggerToolCall())->toBeTrue()
        ->and(LlmIntent::Update->canTriggerToolCall())->toBeTrue()
        ->and(LlmIntent::Error->canTriggerToolCall())->toBeFalse()
        ->and(LlmIntent::Clarify->canTriggerToolCall())->toBeFalse()
        ->and(LlmIntent::Prioritize->canTriggerToolCall())->toBeFalse()
        ->and(LlmIntent::List->canTriggerToolCall())->toBeFalse()
        ->and(LlmIntent::General->canTriggerToolCall())->toBeFalse();
});

test('is read only is true for non-mutating intents', function (): void {
    expect(LlmIntent::Prioritize->isReadOnly())->toBeTrue()
        ->and(LlmIntent::List->isReadOnly())->toBeTrue()
        ->and(LlmIntent::General->isReadOnly())->toBeTrue()
        ->and(LlmIntent::Schedule->isReadOnly())->toBeFalse()
        ->and(LlmIntent::Create->isReadOnly())->toBeFalse()
        ->and(LlmIntent::Update->isReadOnly())->toBeFalse()
        ->and(LlmIntent::Clarify->isReadOnly())->toBeFalse()
        ->and(LlmIntent::Error->isReadOnly())->toBeFalse();
});

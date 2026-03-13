<?php

use App\Actions\Llm\CallLlmAction;
use App\DataTransferObjects\Llm\LlmRequestDto;

test('call llm action returns a well formed stub response', function (): void {
    $action = new CallLlmAction;

    config()->set('llm.schema_version', '2026-03-01.v1');
    config()->set('llm.model', 'hermes3:3b');
    config()->set('prism.providers.ollama.url', 'http://localhost:1');

    $request = new LlmRequestDto(
        systemPrompt: 'system',
        userPayloadJson: json_encode(['foo' => 'bar']),
        temperature: 0.1,
        maxTokens: 128,
    );

    $response = $action($request);

    $decoded = json_decode($response->rawText, true);

    expect($response->modelName)->toBe(config('llm.model'))
        ->and($decoded['schema_version'] ?? null)->toBe(config('llm.schema_version'))
        ->and($decoded['intent'] ?? null)->toBe('general')
        ->and($decoded['meta']['confidence'] ?? null)->toBe(0.0);
}
);

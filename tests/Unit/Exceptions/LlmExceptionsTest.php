<?php

use App\Exceptions\Llm\LlmSchemaVersionException;
use App\Exceptions\Llm\LlmValidationException;
use App\Exceptions\Llm\ToolExecutionException;
use App\Exceptions\Llm\UnknownEntityException;

test('llm validation exception stores metadata', function (): void {
    $previous = new \RuntimeException('previous');
    $exception = new LlmValidationException('Invalid JSON', 'PARSE_ERROR', '{"raw":true}', $previous);

    expect($exception->getMessage())->toBe('Invalid JSON')
        ->and($exception->errorCode)->toBe('PARSE_ERROR')
        ->and($exception->rawResponse)->toBe('{"raw":true}')
        ->and($exception->getPrevious())->toBe($previous);
});

test('llm schema version exception sets expected message', function (): void {
    $exception = new LlmSchemaVersionException('2026-02-28.v1', '2026-03-01.v1');

    expect($exception->received)->toBe('2026-02-28.v1')
        ->and($exception->expected)->toBe('2026-03-01.v1')
        ->and($exception->getMessage())->toBe(
            'Schema version mismatch: received [2026-02-28.v1], expected [2026-03-01.v1].'
        );
});

test('tool execution exception stores tool and args', function (): void {
    $exception = new ToolExecutionException('Tool failed', 'create_task', ['title' => 'Read chapter']);

    expect($exception->getMessage())->toBe('Tool failed')
        ->and($exception->tool)->toBe('create_task')
        ->and($exception->args)->toBe(['title' => 'Read chapter']);
});

test('unknown entity exception stores entity context and message', function (): void {
    $exception = new UnknownEntityException('task', 123);

    expect($exception->entityType)->toBe('task')
        ->and($exception->entityId)->toBe(123)
        ->and($exception->getMessage())->toBe(
            'Unknown task with id [123] referenced by model - possible injection.'
        );
});

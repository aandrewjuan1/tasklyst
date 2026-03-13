<?php

use App\DataTransferObjects\Llm\ContextDto;
use App\DataTransferObjects\Llm\LlmResponseDto;
use App\DataTransferObjects\Llm\TaskContextItem;
use App\DataTransferObjects\Llm\ToolResultDto;
use App\Enums\LlmIntent;

test('context dto taskIds returns numeric ids from task context items', function (): void {
    $dto = new ContextDto(
        now: new DateTimeImmutable,
        tasks: [
            new TaskContextItem(1, 'One', null, null, null),
            new TaskContextItem(2, 'Two', null, null, null),
        ],
        events: [],
        recentMessages: [],
    );

    expect($dto->taskIds())->toBe([1, 2]);
});

test('tool result dto toArray and fromStoredPayload round trip', function (): void {
    $result = new ToolResultDto(
        tool: 'create_task',
        success: true,
        payload: ['id' => 123, 'title' => 'Test'],
        errorMessage: null,
    );

    $array = $result->toArray();

    expect($array)->toMatchArray([
        'tool' => 'create_task',
        'success' => true,
        'payload' => ['id' => 123, 'title' => 'Test'],
    ]);

    $restored = ToolResultDto::fromStoredPayload($array);

    expect($restored->tool)->toBe('create_task');
    expect($restored->success)->toBeTrue();
    expect($restored->payload)->toBe(['id' => 123, 'title' => 'Test']);
});

test('llm response dto error factory produces error response', function (): void {
    $dto = LlmResponseDto::error('Sorry, try again.');

    expect($dto->intent)->toBe(LlmIntent::Error);
    expect($dto->isError)->toBeTrue();
    expect($dto->confidence)->toBe(0.0);
    expect($dto->toolCall)->toBeNull();
    expect($dto->schemaVersion)->toBe(config('llm.schema_version'));
});

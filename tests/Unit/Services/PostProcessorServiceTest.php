<?php

use App\Actions\Llm\RetryRepairAction;
use App\DataTransferObjects\Llm\ContextDto;
use App\DataTransferObjects\Llm\LlmRawResponseDto;
use App\DataTransferObjects\Llm\TaskContextItem;
use App\Enums\LlmIntent;
use App\Exceptions\Llm\LlmSchemaVersionException;
use App\Exceptions\Llm\LlmValidationException;
use App\Exceptions\Llm\UnknownEntityException;
use App\Services\Llm\PostProcessorService;

function makeProcessor(?RetryRepairAction $repair = null): PostProcessorService
{
    return new PostProcessorService(
        schemaVersion: '2026-03-01.v1',
        confidenceLow: 0.4,
        repairAction: $repair ?? new RetryRepairAction,
    );
}

function makeContext(array $taskIds = [1, 2, 3]): ContextDto
{
    $tasks = array_map(fn ($id) => new TaskContextItem($id, "Task {$id}", null, null, null), $taskIds);

    return new ContextDto(new DateTimeImmutable, $tasks, [], []);
}

function validEnvelope(array $overrides = []): string
{
    return json_encode(array_merge([
        'schema_version' => '2026-03-01.v1',
        'intent' => 'general',
        'data' => [],
        'tool_call' => null,
        'message' => 'Test message.',
        'meta' => ['confidence' => 0.9],
    ], $overrides));
}

test('post processor processes a valid general intent response', function (): void {
    $raw = new LlmRawResponseDto(validEnvelope(), 100);
    $result = makeProcessor()->process($raw, makeContext());

    expect($result->intent)->toBe(LlmIntent::General);
    expect($result->isError)->toBeFalse();
    expect($result->confidence)->toBe(0.9);
});

test('post processor throws LlmValidationException on unparseable JSON', function (): void {
    $raw = new LlmRawResponseDto('NOT JSON AT ALL', 10);

    expect(fn () => makeProcessor()->process($raw, makeContext()))
        ->toThrow(LlmValidationException::class);
});

test('post processor throws LlmSchemaVersionException on version mismatch', function (): void {
    $raw = new LlmRawResponseDto(validEnvelope(['schema_version' => '1999-01-01.v0']), 10);

    expect(fn () => makeProcessor()->process($raw, makeContext()))
        ->toThrow(LlmSchemaVersionException::class);
});

test('post processor throws LlmValidationException on invalid intent', function (): void {
    $raw = new LlmRawResponseDto(validEnvelope(['intent' => 'fly_to_mars']), 10);

    expect(fn () => makeProcessor()->process($raw, makeContext()))
        ->toThrow(LlmValidationException::class);
});

test('post processor throws UnknownEntityException when model references a task ID not in context', function (): void {
    $raw = new LlmRawResponseDto(validEnvelope([
        'intent' => 'schedule',
        'data' => [
            'scheduled_items' => [[
                'id' => 'task_9999',
                'start_datetime' => '2030-01-01T10:00:00+08:00',
                'end_datetime' => '2030-01-01T10:30:00+08:00',
            ]],
        ],
    ]), 10);

    expect(fn () => makeProcessor()->process($raw, makeContext([1, 2, 3])))
        ->toThrow(UnknownEntityException::class);
});

test('post processor rejects a past start_datetime in tool_call', function (): void {
    $raw = new LlmRawResponseDto(validEnvelope([
        'intent' => 'schedule',
        'data' => [],
        'tool_call' => [
            'tool' => 'create_event',
            'args' => [
                'title' => 'Task block',
                'start_datetime' => '2000-01-01T10:00:00+08:00',
                'end_datetime' => '2000-01-01T10:30:00+08:00',
                'all_day' => false,
            ],
            'client_request_id' => 'req-uuid',
        ],
    ]), 10);

    expect(fn () => makeProcessor()->process($raw, makeContext()))
        ->toThrow(LlmValidationException::class);
});

test('post processor rejects missing message field', function (): void {
    $raw = new LlmRawResponseDto(validEnvelope([
        'message' => '',
    ]), 10);

    expect(fn () => makeProcessor()->process($raw, makeContext()))
        ->toThrow(LlmValidationException::class, 'missing a valid user-facing message');
});

test('post processor rejects out of range confidence values', function (): void {
    $raw = new LlmRawResponseDto(validEnvelope([
        'meta' => ['confidence' => 1.5],
    ]), 10);

    expect(fn () => makeProcessor()->process($raw, makeContext()))
        ->toThrow(LlmValidationException::class, 'between 0 and 1');
});

test('post processor normalizes string null tool_call and numeric confidence string', function (): void {
    $raw = new LlmRawResponseDto(validEnvelope([
        'tool_call' => 'null',
        'meta' => ['confidence' => '0.42'],
    ]), 10);

    $result = makeProcessor()->process($raw, makeContext());

    expect($result->toolCall)->toBeNull()
        ->and($result->confidence)->toBe(0.42);
});

test('post processor applies domain guardrails for political questions', function (): void {
    config()->set('llm.prompt.domain_guardrails', [
        'enabled' => true,
        'block_politics' => true,
        'block_out_of_scope_qa' => true,
        'min_confidence_for_productivity' => 0.2,
    ]);

    $raw = new LlmRawResponseDto(validEnvelope([
        'intent' => 'prioritize',
        'data' => ['ranked_ids' => ['task_1']],
    ]), 10);

    $context = new ContextDto(
        now: new DateTimeImmutable,
        tasks: [new TaskContextItem(1, 'Task 1', null, null, null)],
        events: [],
        recentMessages: [],
        userPreferences: [],
        fingerprint: null,
        isSummaryMode: false,
        taskSummary: [],
        projects: [],
        projectSummary: [],
        lastUserMessage: 'Who is the best president ever?',
    );

    $result = makeProcessor()->process($raw, $context);

    expect($result->intent)->toBe(LlmIntent::General)
        ->and($result->toolCall)->toBeNull()
        ->and($result->message)->toContain('study and task-planning assistant');
});

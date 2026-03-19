<?php

use App\Services\LLM\TaskAssistant\TaskAssistantToolInterpreter;

test('tool interpreter normalizes already-correct envelope', function (): void {
    $interpreter = new TaskAssistantToolInterpreter;

    $result = $interpreter->interpret([
        'tool' => 'list_tasks',
        'arguments' => ['limit' => 10],
    ]);

    expect($result)->toBe([
        'tool' => 'list_tasks',
        'arguments' => ['limit' => 10],
    ]);
});

test('tool interpreter normalizes common name and parameters shapes', function (): void {
    $interpreter = new TaskAssistantToolInterpreter;

    $withName = $interpreter->interpret([
        'name' => 'list_tasks',
        'arguments' => ['limit' => 5],
    ]);

    $withFunction = $interpreter->interpret([
        'function' => 'list_tasks',
        'parameters' => ['limit' => 3],
    ]);

    expect($withName)->toBe([
        'tool' => 'list_tasks',
        'arguments' => ['limit' => 5],
    ]);

    expect($withFunction)->toBe([
        'tool' => 'list_tasks',
        'arguments' => ['limit' => 3],
    ]);
});

test('tool interpreter accepts json string payloads', function (): void {
    $interpreter = new TaskAssistantToolInterpreter;

    $json = json_encode([
        'tool' => 'complete_task',
        'arguments' => ['task_id' => 123],
    ], JSON_THROW_ON_ERROR);

    $result = $interpreter->interpret($json);

    expect($result)->toBe([
        'tool' => 'complete_task',
        'arguments' => ['task_id' => 123],
    ]);
});

test('tool interpreter returns null for unusable shapes', function (): void {
    $interpreter = new TaskAssistantToolInterpreter;

    expect($interpreter->interpret([]))->toBeNull();
    expect($interpreter->interpret(['tool' => '']))->toBeNull();
    expect($interpreter->interpret('not-json'))->toBeNull();
});

test('resolveToolClass returns configured tool class or null', function (): void {
    config()->set('prism-tools', [
        'list_tasks' => TaskAssistantToolInterpreter::class,
        'create_task' => stdClass::class,
    ]);

    $interpreter = new TaskAssistantToolInterpreter;

    expect($interpreter->resolveToolClass('list_tasks'))->toBe(TaskAssistantToolInterpreter::class);
    expect($interpreter->resolveToolClass('missing_tool'))->toBeNull();
});

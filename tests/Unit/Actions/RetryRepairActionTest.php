<?php

use App\Actions\Llm\RetryRepairAction;

test('retry repair action extracts and fixes json wrapped with extra text', function (): void {
    $action = new RetryRepairAction;

    $broken = <<<'TEXT'
Here is the JSON:
```json
{
  "schema_version": "2026-03-01.v1",
  "intent": "general",
  "data": {},
  "tool_call": null,
  "message": "You should start with task_31.",
  "meta": {"confidence": 0.88,},
}
```
TEXT;

    $repaired = $action($broken, 'canonical envelope');

    expect($repaired)->not->toBeNull();

    $decoded = json_decode((string) $repaired, true);

    expect($decoded)->toBeArray()
        ->and($decoded['intent'] ?? null)->toBe('general')
        ->and($decoded['meta']['confidence'] ?? null)->toBe(0.88);
});

test('retry repair action returns null when there is no json to repair', function (): void {
    $action = new RetryRepairAction;

    $repaired = $action('nothing structured here', 'canonical envelope');

    expect($repaired)->toBeNull();
});

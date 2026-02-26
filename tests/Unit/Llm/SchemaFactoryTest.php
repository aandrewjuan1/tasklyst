<?php

use App\Enums\LlmIntent;
use App\Services\Llm\LlmSchemaFactory;
use Prism\Prism\Schema\ObjectSchema;

beforeEach(function (): void {
    $this->factory = app(LlmSchemaFactory::class);
});

test('returns object schema for every intent', function (LlmIntent $intent): void {
    $schema = $this->factory->schemaForIntent($intent);

    expect($schema)->toBeInstanceOf(ObjectSchema::class)
        ->and($schema->name)->not->toBeEmpty()
        ->and($schema->requiredFields)->toContain('entity_type', 'recommended_action', 'reasoning');
})->with(array_map(fn ($case) => [$case], LlmIntent::cases()));

test('task intents get task recommendation schema', function (): void {
    $schema = $this->factory->schemaForIntent(LlmIntent::ScheduleTask);

    expect($schema->name)->toBe('task_recommendation');
});

test('generic intents get generic recommendation schema', function (): void {
    $schema = $this->factory->schemaForIntent(LlmIntent::GeneralQuery);

    expect($schema->name)->toBe('recommendation');
});

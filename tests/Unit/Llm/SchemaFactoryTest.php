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

test('schedule and adjust task intents get task schedule recommendation schema', function (): void {
    $schema = $this->factory->schemaForIntent(LlmIntent::ScheduleTask);

    expect($schema->name)->toBe('task_schedule_recommendation');
});

test('schedule and adjust event intents get event schedule recommendation schema', function (): void {
    $schema = $this->factory->schemaForIntent(LlmIntent::ScheduleEvent);

    expect($schema->name)->toBe('event_schedule_recommendation')
        ->and($schema->requiredFields)->toContain('id', 'title');
});

test('schedule and adjust project intents get project timeline recommendation schema', function (): void {
    $schema = $this->factory->schemaForIntent(LlmIntent::ScheduleProject);

    expect($schema->name)->toBe('project_timeline_recommendation')
        ->and($schema->requiredFields)->toContain('id', 'name');
});

test('create task intent gets task recommendation schema', function (): void {
    $schema = $this->factory->schemaForIntent(LlmIntent::CreateTask);

    expect($schema->name)->toBe('task_recommendation');
});

test('create event intent gets event create recommendation schema', function (): void {
    $schema = $this->factory->schemaForIntent(LlmIntent::CreateEvent);

    expect($schema->name)->toBe('event_create_recommendation')
        ->and($schema->requiredFields)->toContain('title');
});

test('create project intent gets project create recommendation schema', function (): void {
    $schema = $this->factory->schemaForIntent(LlmIntent::CreateProject);

    expect($schema->name)->toBe('project_create_recommendation')
        ->and($schema->requiredFields)->toContain('name');
});

test('generic intents get generic recommendation schema', function (): void {
    $schema = $this->factory->schemaForIntent(LlmIntent::GeneralQuery);

    expect($schema->name)->toBe('recommendation');
});

test('resolve_dependency gets dependency_resolution schema', function (): void {
    $schema = $this->factory->schemaForIntent(LlmIntent::ResolveDependency);

    expect($schema->name)->toBe('dependency_resolution');
});

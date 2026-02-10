<?php

use App\DataTransferObjects\Project\CreateProjectDto;
use App\Models\User;
use App\Support\Validation\ProjectPayloadValidation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('valid project payload passes validation', function (): void {
    $this->actingAs($this->user);
    $payload = array_replace_recursive(ProjectPayloadValidation::defaults(), [
        'name' => 'Valid project',
    ]);

    $validator = Validator::make(
        ['projectPayload' => $payload],
        ProjectPayloadValidation::rules()
    );

    expect($validator->passes())->toBeTrue();
});

test('project payload fails when name is empty', function (): void {
    $this->actingAs($this->user);
    $payload = array_replace_recursive(ProjectPayloadValidation::defaults(), ['name' => '']);

    $validator = Validator::make(
        ['projectPayload' => $payload],
        ProjectPayloadValidation::rules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('projectPayload.name'))->toBeTrue();
});

test('project payload fails when name is whitespace only', function (): void {
    $this->actingAs($this->user);
    $payload = array_replace_recursive(ProjectPayloadValidation::defaults(), ['name' => '   ']);

    $validator = Validator::make(
        ['projectPayload' => $payload],
        ProjectPayloadValidation::rules()
    );

    expect($validator->fails())->toBeTrue();
});

test('project payload fails when name exceeds max length', function (): void {
    $this->actingAs($this->user);
    $payload = array_replace_recursive(ProjectPayloadValidation::defaults(), [
        'name' => str_repeat('a', 256),
    ]);

    $validator = Validator::make(
        ['projectPayload' => $payload],
        ProjectPayloadValidation::rules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('projectPayload.name'))->toBeTrue();
});

test('project payload fails when end date is before start date', function (): void {
    $this->actingAs($this->user);
    $payload = array_replace_recursive(ProjectPayloadValidation::defaults(), [
        'name' => 'Project',
        'startDatetime' => '2025-02-10 17:00',
        'endDatetime' => '2025-02-10 09:00',
    ]);

    $validator = Validator::make(
        ['projectPayload' => $payload],
        ProjectPayloadValidation::rules()
    );

    expect($validator->fails())->toBeTrue();
});

test('project payload passes with valid start and end datetime', function (): void {
    $this->actingAs($this->user);
    $payload = array_replace_recursive(ProjectPayloadValidation::defaults(), [
        'name' => 'Project',
        'startDatetime' => '2025-02-10 09:00',
        'endDatetime' => '2025-02-10 17:00',
    ]);

    $validator = Validator::make(
        ['projectPayload' => $payload],
        ProjectPayloadValidation::rules()
    );

    expect($validator->passes())->toBeTrue();
});

test('rules for property name accepts valid value', function (): void {
    $this->actingAs($this->user);
    $rules = ProjectPayloadValidation::rulesForProperty('name');
    $validator = Validator::make(['value' => 'My project'], $rules);

    expect($validator->passes())->toBeTrue();
});

test('rules for property name rejects empty value', function (): void {
    $this->actingAs($this->user);
    $rules = ProjectPayloadValidation::rulesForProperty('name');
    $validator = Validator::make(['value' => ''], $rules);

    expect($validator->fails())->toBeTrue();
});

test('rules for property description accepts nullable value', function (): void {
    $this->actingAs($this->user);
    $rules = ProjectPayloadValidation::rulesForProperty('description');
    $validator = Validator::make(['value' => null], $rules);

    expect($validator->passes())->toBeTrue();
});

test('rules for property startDatetime and endDatetime accept valid date', function (): void {
    $this->actingAs($this->user);
    $rules = ProjectPayloadValidation::rulesForProperty('startDatetime');
    $validator = Validator::make(['value' => '2025-02-10 09:00'], $rules);
    expect($validator->passes())->toBeTrue();

    $rules = ProjectPayloadValidation::rulesForProperty('endDatetime');
    $validator = Validator::make(['value' => '2025-02-10 17:00'], $rules);
    expect($validator->passes())->toBeTrue();
});

test('create project dto from validated maps fields and to service attributes', function (): void {
    $validated = [
        'name' => 'DTO project',
        'description' => 'Description',
        'startDatetime' => '2025-02-10 09:00',
        'endDatetime' => '2025-02-10 17:00',
    ];

    $dto = CreateProjectDto::fromValidated($validated);

    expect($dto->name)->toBe('DTO project')
        ->and($dto->description)->toBe('Description');

    $serviceAttrs = $dto->toServiceAttributes();
    expect($serviceAttrs['name'])->toBe('DTO project')
        ->and($serviceAttrs['description'])->toBe('Description')
        ->and($serviceAttrs['start_datetime'])->toBeInstanceOf(Carbon::class)
        ->and($serviceAttrs['end_datetime'])->toBeInstanceOf(Carbon::class);
});

test('create project dto from validated with null dates sets null in service attributes', function (): void {
    $validated = [
        'name' => 'Project',
        'description' => null,
        'startDatetime' => null,
        'endDatetime' => null,
    ];

    $dto = CreateProjectDto::fromValidated($validated);

    expect($dto->startDatetime)->toBeNull()
        ->and($dto->endDatetime)->toBeNull();

    $serviceAttrs = $dto->toServiceAttributes();
    expect($serviceAttrs['start_datetime'])->toBeNull()
        ->and($serviceAttrs['end_datetime'])->toBeNull();
});

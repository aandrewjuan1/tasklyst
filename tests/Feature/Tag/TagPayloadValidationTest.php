<?php

use App\DataTransferObjects\Tag\CreateTagDto;
use App\Models\User;
use App\Support\Validation\TagPayloadValidation;
use Illuminate\Support\Facades\Validator;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('valid tag name passes validation', function (): void {
    $payload = array_replace_recursive(TagPayloadValidation::defaults(), ['name' => 'Work']);

    $validator = Validator::make($payload, TagPayloadValidation::rules());

    expect($validator->passes())->toBeTrue();
});

test('tag name required fails when empty', function (): void {
    $payload = array_replace_recursive(TagPayloadValidation::defaults(), ['name' => '']);

    $validator = Validator::make($payload, TagPayloadValidation::rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('name'))->toBeTrue();
});

test('tag name regex fails for whitespace only', function (): void {
    $payload = array_replace_recursive(TagPayloadValidation::defaults(), ['name' => '   ']);

    $validator = Validator::make($payload, TagPayloadValidation::rules());

    expect($validator->fails())->toBeTrue();
});

test('tag name max length 255 fails', function (): void {
    $payload = array_replace_recursive(TagPayloadValidation::defaults(), [
        'name' => str_repeat('a', 256),
    ]);

    $validator = Validator::make($payload, TagPayloadValidation::rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('name'))->toBeTrue();
});

test('create tag dto from validated trims and maps name', function (): void {
    $dto = CreateTagDto::fromValidated('  Work  ');

    expect($dto->name)->toBe('Work');
});

test('create tag dto from validated with single word', function (): void {
    $dto = CreateTagDto::fromValidated('Personal');

    expect($dto->name)->toBe('Personal');
});

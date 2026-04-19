<?php

use App\DataTransferObjects\Teacher\CreateTeacherDto;
use App\DataTransferObjects\Teacher\UpdateTeacherDto;
use App\Support\Validation\TeacherPayloadValidation;
use Illuminate\Support\Facades\Validator;

test('valid teacher name passes validation', function (): void {
    $payload = array_replace_recursive(TeacherPayloadValidation::defaults(), ['name' => 'Dr. Lee']);

    $validator = Validator::make($payload, TeacherPayloadValidation::rules());

    expect($validator->passes())->toBeTrue();
});

test('teacher name required fails when empty', function (): void {
    $payload = array_replace_recursive(TeacherPayloadValidation::defaults(), ['name' => '']);

    $validator = Validator::make($payload, TeacherPayloadValidation::rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('name'))->toBeTrue();
});

test('teacher name regex fails for whitespace only', function (): void {
    $payload = array_replace_recursive(TeacherPayloadValidation::defaults(), ['name' => '   ']);

    $validator = Validator::make($payload, TeacherPayloadValidation::rules());

    expect($validator->fails())->toBeTrue();
});

test('teacher name max length 255 fails', function (): void {
    $payload = array_replace_recursive(TeacherPayloadValidation::defaults(), [
        'name' => str_repeat('a', 256),
    ]);

    $validator = Validator::make($payload, TeacherPayloadValidation::rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('name'))->toBeTrue();
});

test('create teacher dto from validated trims name', function (): void {
    $dto = CreateTeacherDto::fromValidated('  Dr. Lee  ');

    expect($dto->name)->toBe('Dr. Lee');
});

test('update teacher dto from validated trims name', function (): void {
    $dto = UpdateTeacherDto::fromValidated('  Ms. Park  ');

    expect($dto->name)->toBe('Ms. Park');
});

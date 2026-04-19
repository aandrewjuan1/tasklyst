<?php

use App\DataTransferObjects\SchoolClass\CreateSchoolClassDto;
use App\Models\RecurringSchoolClass;
use App\Models\User;
use App\Support\Validation\SchoolClassExceptionPayloadValidation;
use App\Support\Validation\SchoolClassPayloadValidation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('valid school class payload passes validation', function (): void {
    $this->actingAs($this->user);
    $payload = array_replace_recursive(SchoolClassPayloadValidation::defaults(), [
        'subjectName' => 'Biology',
        'teacherName' => 'Dr. Lee',
        'startDatetime' => '2025-02-10 09:00',
        'endDatetime' => '2025-02-10 17:00',
    ]);

    $validator = Validator::make(
        ['schoolClassPayload' => $payload],
        SchoolClassPayloadValidation::rules()
    );

    expect($validator->passes())->toBeTrue();
});

test('school class payload fails when subject name is empty', function (): void {
    $this->actingAs($this->user);
    $payload = array_replace_recursive(SchoolClassPayloadValidation::defaults(), ['subjectName' => '']);

    $validator = Validator::make(
        ['schoolClassPayload' => $payload],
        SchoolClassPayloadValidation::rules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('schoolClassPayload.subjectName'))->toBeTrue();
});

test('school class payload fails when end date is before start date', function (): void {
    $this->actingAs($this->user);
    $payload = array_replace_recursive(SchoolClassPayloadValidation::defaults(), [
        'subjectName' => 'Class',
        'teacherName' => 'Dr. A',
        'startDatetime' => '2025-02-10 17:00',
        'endDatetime' => '2025-02-10 09:00',
    ]);

    $validator = Validator::make(
        ['schoolClassPayload' => $payload],
        SchoolClassPayloadValidation::rules()
    );

    expect($validator->fails())->toBeTrue();
});

test('school class payload passes with valid start and end datetime', function (): void {
    $this->actingAs($this->user);
    $payload = array_replace_recursive(SchoolClassPayloadValidation::defaults(), [
        'subjectName' => 'Class',
        'teacherName' => 'Prof. Kim',
        'startDatetime' => '2025-02-10 09:00',
        'endDatetime' => '2025-02-10 17:00',
    ]);

    $validator = Validator::make(
        ['schoolClassPayload' => $payload],
        SchoolClassPayloadValidation::rules()
    );

    expect($validator->passes())->toBeTrue();
});

test('school class payload fails when teacher name is empty', function (): void {
    $this->actingAs($this->user);
    $payload = array_replace_recursive(SchoolClassPayloadValidation::defaults(), [
        'subjectName' => 'Class',
        'teacherName' => '   ',
        'startDatetime' => '2025-02-10 09:00',
        'endDatetime' => '2025-02-10 17:00',
    ]);

    $validator = Validator::make(
        ['schoolClassPayload' => $payload],
        SchoolClassPayloadValidation::rules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('schoolClassPayload.teacherName'))->toBeTrue();
});

test('rules for property subjectName accepts valid value', function (): void {
    $this->actingAs($this->user);
    $rules = SchoolClassPayloadValidation::rulesForProperty('subjectName');
    $validator = Validator::make(['value' => 'Algebra'], $rules);

    expect($validator->passes())->toBeTrue();
});

test('rules for property recurrence accepts valid structure', function (): void {
    $this->actingAs($this->user);
    $rules = SchoolClassPayloadValidation::rulesForProperty('recurrence');
    $validator = Validator::make([
        'value' => [
            'enabled' => true,
            'type' => 'daily',
            'interval' => 1,
            'daysOfWeek' => [],
        ],
    ], $rules);

    expect($validator->passes())->toBeTrue();
});

test('create school class dto from validated maps fields and to service attributes', function (): void {
    $validated = [
        'subjectName' => 'Physics',
        'teacherName' => 'Dr. Jones',
        'startDatetime' => '2025-02-10 09:00',
        'endDatetime' => '2025-02-10 17:00',
        'recurrence' => ['enabled' => false, 'type' => null, 'interval' => 1, 'daysOfWeek' => []],
    ];

    $dto = CreateSchoolClassDto::fromValidated($validated);

    expect($dto->subjectName)->toBe('Physics')
        ->and($dto->teacherName)->toBe('Dr. Jones');

    $serviceAttrs = $dto->toServiceAttributes();
    expect($serviceAttrs['subject_name'])->toBe('Physics')
        ->and($serviceAttrs['teacher_name'])->toBe('Dr. Jones')
        ->and($serviceAttrs['start_datetime'])->toBeInstanceOf(Carbon::class)
        ->and($serviceAttrs['end_datetime'])->toBeInstanceOf(Carbon::class);
});

test('validate school class date range for update returns message when end before start', function (): void {
    $start = Carbon::parse('2025-02-10 10:00');
    $end = Carbon::parse('2025-02-10 09:00');

    $message = SchoolClassPayloadValidation::validateSchoolClassDateRangeForUpdate($start, $end);

    expect($message)->not->toBeNull();
});

test('validate school class date range for update returns message when start or end missing', function (): void {
    expect(SchoolClassPayloadValidation::validateSchoolClassDateRangeForUpdate(null, Carbon::now()))->not->toBeNull()
        ->and(SchoolClassPayloadValidation::validateSchoolClassDateRangeForUpdate(Carbon::now(), null))->not->toBeNull();
});

test('school class exception create payload passes when recurring exists', function (): void {
    $this->actingAs($this->user);
    $recurring = RecurringSchoolClass::factory()->create();
    $payload = array_replace_recursive(SchoolClassExceptionPayloadValidation::createDefaults(), [
        'recurringSchoolClassId' => $recurring->id,
        'exceptionDate' => '2025-02-10',
        'isDeleted' => true,
    ]);

    $validator = Validator::make(
        ['schoolClassExceptionPayload' => $payload],
        SchoolClassExceptionPayloadValidation::createRules()
    );

    expect($validator->passes())->toBeTrue();
});

test('school class exception update payload passes with nullable fields', function (): void {
    $this->actingAs($this->user);
    $payload = SchoolClassExceptionPayloadValidation::updateDefaults();
    $payload['reason'] = 'Note';

    $validator = Validator::make(
        ['schoolClassExceptionPayload' => $payload],
        SchoolClassExceptionPayloadValidation::updateRules()
    );

    expect($validator->passes())->toBeTrue();
});

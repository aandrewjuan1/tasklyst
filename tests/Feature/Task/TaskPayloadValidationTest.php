<?php

use App\DataTransferObjects\Task\CreateTaskDto;
use App\Enums\TaskComplexity;
use App\Enums\TaskPriority;
use App\Enums\TaskRecurrenceType;
use App\Enums\TaskStatus;
use App\Models\Tag;
use App\Models\User;
use App\Support\Validation\TaskPayloadValidation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('valid task payload passes validation', function (): void {
    $this->actingAs($this->user);
    $payload = array_replace_recursive(TaskPayloadValidation::defaults(), [
        'title' => 'Valid task',
        'status' => TaskStatus::ToDo->value,
        'priority' => TaskPriority::Medium->value,
    ]);

    $validator = Validator::make(
        ['taskPayload' => $payload],
        TaskPayloadValidation::rules()
    );

    expect($validator->passes())->toBeTrue();
});

test('task payload fails when title is empty', function (): void {
    $this->actingAs($this->user);
    $payload = array_replace_recursive(TaskPayloadValidation::defaults(), ['title' => '']);

    $validator = Validator::make(
        ['taskPayload' => $payload],
        TaskPayloadValidation::rules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('taskPayload.title'))->toBeTrue();
});

test('task payload fails when title is whitespace only', function (): void {
    $this->actingAs($this->user);
    $payload = array_replace_recursive(TaskPayloadValidation::defaults(), ['title' => '   ']);

    $validator = Validator::make(
        ['taskPayload' => $payload],
        TaskPayloadValidation::rules()
    );

    expect($validator->fails())->toBeTrue();
});

test('task payload fails when title exceeds max length', function (): void {
    $this->actingAs($this->user);
    $payload = array_replace_recursive(TaskPayloadValidation::defaults(), [
        'title' => str_repeat('a', 256),
    ]);

    $validator = Validator::make(
        ['taskPayload' => $payload],
        TaskPayloadValidation::rules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('taskPayload.title'))->toBeTrue();
});

test('task payload fails when status is invalid', function (): void {
    $this->actingAs($this->user);
    $payload = array_replace_recursive(TaskPayloadValidation::defaults(), [
        'title' => 'Task',
        'status' => 'invalid_status',
    ]);

    $validator = Validator::make(
        ['taskPayload' => $payload],
        TaskPayloadValidation::rules()
    );

    expect($validator->fails())->toBeTrue();
});

test('task payload fails when project id does not exist', function (): void {
    $this->actingAs($this->user);
    $payload = array_replace_recursive(TaskPayloadValidation::defaults(), [
        'title' => 'Task',
        'projectId' => 99999,
    ]);

    $validator = Validator::make(
        ['taskPayload' => $payload],
        TaskPayloadValidation::rules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('taskPayload.projectId'))->toBeTrue();
});

test('task payload fails when tag id belongs to another user', function (): void {
    $this->actingAs($this->user);
    $otherUser = User::factory()->create();
    $tag = Tag::factory()->for($otherUser)->create();
    $payload = array_replace_recursive(TaskPayloadValidation::defaults(), [
        'title' => 'Task',
        'tagIds' => [$tag->id],
    ]);

    $validator = Validator::make(
        ['taskPayload' => $payload],
        TaskPayloadValidation::rules()
    );

    expect($validator->fails())->toBeTrue();
});

test('task payload passes when tag ids belong to user', function (): void {
    $this->actingAs($this->user);
    $tag = Tag::factory()->for($this->user)->create();
    $payload = array_replace_recursive(TaskPayloadValidation::defaults(), [
        'title' => 'Task',
        'tagIds' => [$tag->id],
    ]);

    $validator = Validator::make(
        ['taskPayload' => $payload],
        TaskPayloadValidation::rules()
    );

    expect($validator->passes())->toBeTrue();
});

test('task payload fails when recurrence type is invalid', function (): void {
    $this->actingAs($this->user);
    $payload = array_replace_recursive(TaskPayloadValidation::defaults(), [
        'title' => 'Task',
        'recurrence' => [
            'enabled' => true,
            'type' => 'invalid',
            'interval' => 1,
            'daysOfWeek' => [],
        ],
    ]);

    $validator = Validator::make(
        ['taskPayload' => $payload],
        TaskPayloadValidation::rules()
    );

    expect($validator->fails())->toBeTrue();
});

test('rules for property title accepts valid value', function (): void {
    $this->actingAs($this->user);
    $rules = TaskPayloadValidation::rulesForProperty('title');
    $validator = Validator::make(['value' => 'My task'], $rules);

    expect($validator->passes())->toBeTrue();
});

test('rules for property title rejects empty value', function (): void {
    $this->actingAs($this->user);
    $rules = TaskPayloadValidation::rulesForProperty('title');
    $validator = Validator::make(['value' => ''], $rules);

    expect($validator->fails())->toBeTrue();
});

test('rules for property status accepts valid enum value', function (): void {
    $this->actingAs($this->user);
    $rules = TaskPayloadValidation::rulesForProperty('status');
    $validator = Validator::make(['value' => TaskStatus::Doing->value], $rules);

    expect($validator->passes())->toBeTrue();
});

test('rules for property duration accepts positive integer', function (): void {
    $this->actingAs($this->user);
    $rules = TaskPayloadValidation::rulesForProperty('duration');
    $validator = Validator::make(['value' => 30], $rules);

    expect($validator->passes())->toBeTrue();
});

test('validate task date range for update returns error when end is before start', function (): void {
    $start = Carbon::parse('2025-02-10 10:00');
    $end = Carbon::parse('2025-02-10 09:00');

    $error = TaskPayloadValidation::validateTaskDateRangeForUpdate($start, $end, 60);

    expect($error)->not->toBeNull()
        ->and($error)->toContain('End date');
});

test('validate task date range for update returns error when same day end is before start plus duration', function (): void {
    $start = Carbon::parse('2025-02-10 09:00');
    $end = Carbon::parse('2025-02-10 09:30'); // 30 min later, but duration is 60

    $error = TaskPayloadValidation::validateTaskDateRangeForUpdate($start, $end, 60);

    expect($error)->not->toBeNull()
        ->and($error)->toContain('minutes');
});

test('validate task date range for update returns null when start or end is null', function (): void {
    expect(TaskPayloadValidation::validateTaskDateRangeForUpdate(null, Carbon::now(), 60))->toBeNull()
        ->and(TaskPayloadValidation::validateTaskDateRangeForUpdate(Carbon::now(), null, 60))->toBeNull();
});

test('validate task date range for update returns null for valid range', function (): void {
    $start = Carbon::parse('2025-02-10 09:00');
    $end = Carbon::parse('2025-02-10 10:30');

    $error = TaskPayloadValidation::validateTaskDateRangeForUpdate($start, $end, 60);

    expect($error)->toBeNull();
});

test('create task dto from validated maps fields and to service attributes', function (): void {
    $validated = [
        'title' => 'DTO task',
        'description' => 'Description',
        'status' => TaskStatus::Doing->value,
        'priority' => TaskPriority::High->value,
        'complexity' => TaskComplexity::Moderate->value,
        'duration' => 45,
        'startDatetime' => '2025-02-10 09:00',
        'endDatetime' => '2025-02-10 17:00',
        'projectId' => null,
        'tagIds' => [1, 2],
        'recurrence' => [
            'enabled' => true,
            'type' => TaskRecurrenceType::Weekly->value,
            'interval' => 1,
            'daysOfWeek' => [1, 3],
        ],
    ];

    $dto = CreateTaskDto::fromValidated($validated);

    expect($dto->title)->toBe('DTO task')
        ->and($dto->description)->toBe('Description')
        ->and($dto->status)->toBe(TaskStatus::Doing->value)
        ->and($dto->tagIds)->toEqual([1, 2])
        ->and($dto->recurrence)->not->toBeNull()
        ->and($dto->recurrence['type'])->toBe(TaskRecurrenceType::Weekly->value);

    $serviceAttrs = $dto->toServiceAttributes();
    expect($serviceAttrs['title'])->toBe('DTO task')
        ->and($serviceAttrs['start_datetime'])->toBeInstanceOf(Carbon::class)
        ->and($serviceAttrs['tagIds'])->toEqual([1, 2])
        ->and($serviceAttrs['recurrence'])->not->toBeNull();
});

test('create task dto from validated with recurrence disabled sets recurrence null', function (): void {
    $validated = [
        'title' => 'Task',
        'recurrence' => ['enabled' => false, 'type' => null, 'interval' => 1, 'daysOfWeek' => []],
    ];

    $dto = CreateTaskDto::fromValidated($validated);

    expect($dto->recurrence)->toBeNull();
});

<?php

use App\DataTransferObjects\Event\CreateEventDto;
use App\Enums\EventRecurrenceType;
use App\Enums\EventStatus;
use App\Models\Tag;
use App\Models\User;
use App\Support\Validation\EventPayloadValidation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('valid event payload passes validation', function (): void {
    $this->actingAs($this->user);
    $payload = array_replace_recursive(EventPayloadValidation::defaults(), [
        'title' => 'Valid event',
        'status' => EventStatus::Scheduled->value,
    ]);

    $validator = Validator::make(
        ['eventPayload' => $payload],
        EventPayloadValidation::rules()
    );

    expect($validator->passes())->toBeTrue();
});

test('event payload fails when title is empty', function (): void {
    $this->actingAs($this->user);
    $payload = array_replace_recursive(EventPayloadValidation::defaults(), ['title' => '']);

    $validator = Validator::make(
        ['eventPayload' => $payload],
        EventPayloadValidation::rules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('eventPayload.title'))->toBeTrue();
});

test('event payload fails when title is whitespace only', function (): void {
    $this->actingAs($this->user);
    $payload = array_replace_recursive(EventPayloadValidation::defaults(), ['title' => '   ']);

    $validator = Validator::make(
        ['eventPayload' => $payload],
        EventPayloadValidation::rules()
    );

    expect($validator->fails())->toBeTrue();
});

test('event payload fails when title exceeds max length', function (): void {
    $this->actingAs($this->user);
    $payload = array_replace_recursive(EventPayloadValidation::defaults(), [
        'title' => str_repeat('a', 256),
    ]);

    $validator = Validator::make(
        ['eventPayload' => $payload],
        EventPayloadValidation::rules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('eventPayload.title'))->toBeTrue();
});

test('event payload fails when status is invalid', function (): void {
    $this->actingAs($this->user);
    $payload = array_replace_recursive(EventPayloadValidation::defaults(), [
        'title' => 'Event',
        'status' => 'invalid_status',
    ]);

    $validator = Validator::make(
        ['eventPayload' => $payload],
        EventPayloadValidation::rules()
    );

    expect($validator->fails())->toBeTrue();
});

test('event payload fails when tag id belongs to another user', function (): void {
    $this->actingAs($this->user);
    $otherUser = User::factory()->create();
    $tag = Tag::factory()->for($otherUser)->create();
    $payload = array_replace_recursive(EventPayloadValidation::defaults(), [
        'title' => 'Event',
        'tagIds' => [$tag->id],
    ]);

    $validator = Validator::make(
        ['eventPayload' => $payload],
        EventPayloadValidation::rules()
    );

    expect($validator->fails())->toBeTrue();
});

test('event payload passes when tag ids belong to user', function (): void {
    $this->actingAs($this->user);
    $tag = Tag::factory()->for($this->user)->create();
    $payload = array_replace_recursive(EventPayloadValidation::defaults(), [
        'title' => 'Event',
        'tagIds' => [$tag->id],
    ]);

    $validator = Validator::make(
        ['eventPayload' => $payload],
        EventPayloadValidation::rules()
    );

    expect($validator->passes())->toBeTrue();
});

test('event payload fails when recurrence type is invalid', function (): void {
    $this->actingAs($this->user);
    $payload = array_replace_recursive(EventPayloadValidation::defaults(), [
        'title' => 'Event',
        'recurrence' => [
            'enabled' => true,
            'type' => 'invalid',
            'interval' => 1,
            'daysOfWeek' => [],
        ],
    ]);

    $validator = Validator::make(
        ['eventPayload' => $payload],
        EventPayloadValidation::rules()
    );

    expect($validator->fails())->toBeTrue();
});

test('rules for property title accepts valid value', function (): void {
    $this->actingAs($this->user);
    $rules = EventPayloadValidation::rulesForProperty('title');
    $validator = Validator::make(['value' => 'My event'], $rules);

    expect($validator->passes())->toBeTrue();
});

test('rules for property title rejects empty value', function (): void {
    $this->actingAs($this->user);
    $rules = EventPayloadValidation::rulesForProperty('title');
    $validator = Validator::make(['value' => ''], $rules);

    expect($validator->fails())->toBeTrue();
});

test('rules for property status accepts valid enum value', function (): void {
    $this->actingAs($this->user);
    $rules = EventPayloadValidation::rulesForProperty('status');
    $validator = Validator::make(['value' => EventStatus::Ongoing->value], $rules);

    expect($validator->passes())->toBeTrue();
});

test('rules for property startDatetime and endDatetime accept valid date', function (): void {
    $this->actingAs($this->user);
    $rules = EventPayloadValidation::rulesForProperty('startDatetime');
    $validator = Validator::make(['value' => '2025-02-10 09:00'], $rules);
    expect($validator->passes())->toBeTrue();

    $rules = EventPayloadValidation::rulesForProperty('endDatetime');
    $validator = Validator::make(['value' => '2025-02-10 17:00'], $rules);
    expect($validator->passes())->toBeTrue();
});

test('rules for property allDay accepts boolean', function (): void {
    $this->actingAs($this->user);
    $rules = EventPayloadValidation::rulesForProperty('allDay');
    $validator = Validator::make(['value' => true], $rules);

    expect($validator->passes())->toBeTrue();
});

test('validate event date range for update returns error when end is before start', function (): void {
    $start = Carbon::parse('2025-02-10 10:00');
    $end = Carbon::parse('2025-02-10 09:00');

    $error = EventPayloadValidation::validateEventDateRangeForUpdate($start, $end);

    expect($error)->not->toBeNull()
        ->and($error)->toContain('End date');
});

test('validate event date range for update returns null when start or end is null', function (): void {
    expect(EventPayloadValidation::validateEventDateRangeForUpdate(null, Carbon::now()))->toBeNull()
        ->and(EventPayloadValidation::validateEventDateRangeForUpdate(Carbon::now(), null))->toBeNull();
});

test('validate event date range for update returns null for valid range', function (): void {
    $start = Carbon::parse('2025-02-10 09:00');
    $end = Carbon::parse('2025-02-10 10:30');

    $error = EventPayloadValidation::validateEventDateRangeForUpdate($start, $end);

    expect($error)->toBeNull();
});

test('create event dto from validated maps fields and to service attributes', function (): void {
    $validated = [
        'title' => 'DTO event',
        'description' => 'Description',
        'status' => EventStatus::Ongoing->value,
        'startDatetime' => '2025-02-10 09:00',
        'endDatetime' => '2025-02-10 17:00',
        'allDay' => false,
        'tagIds' => [1, 2],
        'recurrence' => [
            'enabled' => true,
            'type' => EventRecurrenceType::Weekly->value,
            'interval' => 1,
            'daysOfWeek' => [1, 3],
        ],
    ];

    $dto = CreateEventDto::fromValidated($validated);

    expect($dto->title)->toBe('DTO event')
        ->and($dto->description)->toBe('Description')
        ->and($dto->status)->toBe(EventStatus::Ongoing->value)
        ->and($dto->allDay)->toBeFalse()
        ->and($dto->tagIds)->toEqual([1, 2])
        ->and($dto->recurrence)->not->toBeNull()
        ->and($dto->recurrence['type'])->toBe(EventRecurrenceType::Weekly->value);

    $serviceAttrs = $dto->toServiceAttributes();
    expect($serviceAttrs['title'])->toBe('DTO event')
        ->and($serviceAttrs['start_datetime'])->toBeInstanceOf(Carbon::class)
        ->and($serviceAttrs['all_day'])->toBeFalse()
        ->and($serviceAttrs['tagIds'])->toEqual([1, 2])
        ->and($serviceAttrs['recurrence'])->not->toBeNull();
});

test('create event dto from validated with recurrence disabled sets recurrence null', function (): void {
    $validated = [
        'title' => 'Event',
        'recurrence' => ['enabled' => false, 'type' => null, 'interval' => 1, 'daysOfWeek' => []],
    ];

    $dto = CreateEventDto::fromValidated($validated);

    expect($dto->recurrence)->toBeNull();
});

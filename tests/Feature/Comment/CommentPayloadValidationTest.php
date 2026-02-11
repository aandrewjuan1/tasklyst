<?php

use App\DataTransferObjects\Comment\CreateCommentDto;
use App\DataTransferObjects\Comment\UpdateCommentDto;
use App\Models\Task;
use App\Models\User;
use App\Support\Validation\CommentPayloadValidation;
use Illuminate\Support\Facades\Validator;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('valid create comment payload passes validation', function (): void {
    $payload = array_replace_recursive(CommentPayloadValidation::createDefaults(), [
        'commentableType' => Task::class,
        'commentableId' => 1,
        'content' => 'Valid comment content',
    ]);

    $validator = Validator::make(
        ['commentPayload' => $payload],
        CommentPayloadValidation::createRules()
    );

    expect($validator->passes())->toBeTrue();
});

test('create comment payload fails when commentable type is missing', function (): void {
    $payload = array_replace_recursive(CommentPayloadValidation::createDefaults(), [
        'commentableId' => 1,
        'content' => 'Content',
    ]);
    unset($payload['commentableType']);

    $validator = Validator::make(
        ['commentPayload' => $payload],
        CommentPayloadValidation::createRules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('commentPayload.commentableType'))->toBeTrue();
});

test('create comment payload fails when commentable type is invalid', function (): void {
    $payload = array_replace_recursive(CommentPayloadValidation::createDefaults(), [
        'commentableType' => 'Invalid\\Model\\Class',
        'commentableId' => 1,
        'content' => 'Content',
    ]);

    $validator = Validator::make(
        ['commentPayload' => $payload],
        CommentPayloadValidation::createRules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('commentPayload.commentableType'))->toBeTrue();
});

test('create comment payload fails when commentable id is missing', function (): void {
    $payload = array_replace_recursive(CommentPayloadValidation::createDefaults(), [
        'commentableType' => Task::class,
        'content' => 'Content',
    ]);
    unset($payload['commentableId']);

    $validator = Validator::make(
        ['commentPayload' => $payload],
        CommentPayloadValidation::createRules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('commentPayload.commentableId'))->toBeTrue();
});

test('create comment payload fails when content is empty', function (): void {
    $payload = array_replace_recursive(CommentPayloadValidation::createDefaults(), [
        'commentableType' => Task::class,
        'commentableId' => 1,
        'content' => '',
    ]);

    $validator = Validator::make(
        ['commentPayload' => $payload],
        CommentPayloadValidation::createRules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('commentPayload.content'))->toBeTrue();
});

test('create comment payload fails when content is whitespace only', function (): void {
    $payload = array_replace_recursive(CommentPayloadValidation::createDefaults(), [
        'commentableType' => Task::class,
        'commentableId' => 1,
        'content' => '   ',
    ]);

    $validator = Validator::make(
        ['commentPayload' => $payload],
        CommentPayloadValidation::createRules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('commentPayload.content'))->toBeTrue();
});

test('create comment payload fails when content exceeds max length', function (): void {
    $payload = array_replace_recursive(CommentPayloadValidation::createDefaults(), [
        'commentableType' => Task::class,
        'commentableId' => 1,
        'content' => str_repeat('a', 65536),
    ]);

    $validator = Validator::make(
        ['commentPayload' => $payload],
        CommentPayloadValidation::createRules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('commentPayload.content'))->toBeTrue();
});

test('valid update comment payload passes validation', function (): void {
    $payload = array_replace_recursive(CommentPayloadValidation::updateDefaults(), [
        'content' => 'Updated content',
        'isPinned' => true,
    ]);

    $validator = Validator::make(
        ['commentPayload' => $payload],
        CommentPayloadValidation::updateRules()
    );

    expect($validator->passes())->toBeTrue();
});

test('update comment payload fails when content is empty', function (): void {
    $payload = array_replace_recursive(CommentPayloadValidation::updateDefaults(), [
        'content' => '',
    ]);

    $validator = Validator::make(
        ['commentPayload' => $payload],
        CommentPayloadValidation::updateRules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('commentPayload.content'))->toBeTrue();
});

test('update comment payload fails when content is whitespace only', function (): void {
    $payload = array_replace_recursive(CommentPayloadValidation::updateDefaults(), [
        'content' => "\t  \n",
    ]);

    $validator = Validator::make(
        ['commentPayload' => $payload],
        CommentPayloadValidation::updateRules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('commentPayload.content'))->toBeTrue();
});

test('update comment payload accepts boolean isPinned', function (): void {
    $payload = array_replace_recursive(CommentPayloadValidation::updateDefaults(), [
        'content' => 'Content',
        'isPinned' => false,
    ]);

    $validator = Validator::make(
        ['commentPayload' => $payload],
        CommentPayloadValidation::updateRules()
    );

    expect($validator->passes())->toBeTrue();
});

test('create comment dto from validated maps and trims content', function (): void {
    $validated = [
        'commentableType' => Task::class,
        'commentableId' => 42,
        'content' => '  Trimmed content  ',
    ];

    $dto = CreateCommentDto::fromValidated($validated);

    expect($dto->commentableType)->toBe(Task::class)
        ->and($dto->commentableId)->toBe(42)
        ->and($dto->content)->toBe('Trimmed content');
});

test('create comment dto toServiceAttributes returns content only', function (): void {
    $dto = new CreateCommentDto(
        commentableType: Task::class,
        commentableId: 1,
        content: 'Service content',
    );

    $attrs = $dto->toServiceAttributes();

    expect($attrs)->toBe(['content' => 'Service content']);
});

test('update comment dto from validated maps content and isPinned', function (): void {
    $validated = [
        'content' => '  Updated text  ',
        'isPinned' => true,
    ];

    $dto = UpdateCommentDto::fromValidated($validated);

    expect($dto->content)->toBe('Updated text')
        ->and($dto->isPinned)->toBeTrue();
});

test('update comment dto from validated defaults isPinned to false when missing', function (): void {
    $validated = ['content' => 'Content'];

    $dto = UpdateCommentDto::fromValidated($validated);

    expect($dto->content)->toBe('Content')
        ->and($dto->isPinned)->toBeFalse();
});

test('update comment dto toServiceAttributes returns content and is_pinned', function (): void {
    $dto = new UpdateCommentDto(content: 'New content', isPinned: true);

    $attrs = $dto->toServiceAttributes();

    expect($attrs)->toBe([
        'content' => 'New content',
        'is_pinned' => true,
    ]);
});

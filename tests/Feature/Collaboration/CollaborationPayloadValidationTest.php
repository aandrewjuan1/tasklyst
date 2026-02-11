<?php

use App\DataTransferObjects\Collaboration\CreateCollaborationInvitationDto;
use App\Enums\CollaborationPermission;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Validation\CollaborationPayloadValidation;
use Illuminate\Support\Facades\Validator;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('valid create collaboration payload passes validation', function (): void {
    $payload = array_replace_recursive(CollaborationPayloadValidation::createDefaults(), [
        'collaboratableType' => 'task',
        'collaboratableId' => 1,
        'email' => 'user@example.com',
        'permission' => CollaborationPermission::Edit->value,
    ]);

    $validator = Validator::make(
        ['collaborationPayload' => $payload],
        CollaborationPayloadValidation::createRules()
    );

    expect($validator->passes())->toBeTrue();
});

test('create collaboration payload fails when collaboratable type is missing', function (): void {
    $payload = array_replace_recursive(CollaborationPayloadValidation::createDefaults(), [
        'collaboratableId' => 1,
        'email' => 'user@example.com',
        'permission' => CollaborationPermission::Edit->value,
    ]);
    unset($payload['collaboratableType']);

    $validator = Validator::make(
        ['collaborationPayload' => $payload],
        CollaborationPayloadValidation::createRules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('collaborationPayload.collaboratableType'))->toBeTrue();
});

test('create collaboration payload fails when collaboratable type is invalid', function (): void {
    $payload = array_replace_recursive(CollaborationPayloadValidation::createDefaults(), [
        'collaboratableType' => 'invalid',
        'collaboratableId' => 1,
        'email' => 'user@example.com',
        'permission' => CollaborationPermission::Edit->value,
    ]);

    $validator = Validator::make(
        ['collaborationPayload' => $payload],
        CollaborationPayloadValidation::createRules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('collaborationPayload.collaboratableType'))->toBeTrue();
});

test('create collaboration payload fails when collaboratable id is missing', function (): void {
    $payload = array_replace_recursive(CollaborationPayloadValidation::createDefaults(), [
        'collaboratableType' => 'task',
        'email' => 'user@example.com',
        'permission' => CollaborationPermission::Edit->value,
    ]);
    unset($payload['collaboratableId']);

    $validator = Validator::make(
        ['collaborationPayload' => $payload],
        CollaborationPayloadValidation::createRules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('collaborationPayload.collaboratableId'))->toBeTrue();
});

test('create collaboration payload fails when email is invalid', function (): void {
    $payload = array_replace_recursive(CollaborationPayloadValidation::createDefaults(), [
        'collaboratableType' => 'task',
        'collaboratableId' => 1,
        'email' => 'not-an-email',
        'permission' => CollaborationPermission::Edit->value,
    ]);

    $validator = Validator::make(
        ['collaborationPayload' => $payload],
        CollaborationPayloadValidation::createRules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('collaborationPayload.email'))->toBeTrue();
});

test('create collaboration payload fails when email is missing', function (): void {
    $payload = array_replace_recursive(CollaborationPayloadValidation::createDefaults(), [
        'collaboratableType' => 'task',
        'collaboratableId' => 1,
        'permission' => CollaborationPermission::Edit->value,
    ]);
    unset($payload['email']);

    $validator = Validator::make(
        ['collaborationPayload' => $payload],
        CollaborationPayloadValidation::createRules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('collaborationPayload.email'))->toBeTrue();
});

test('create collaboration payload fails when permission is invalid', function (): void {
    $payload = array_replace_recursive(CollaborationPayloadValidation::createDefaults(), [
        'collaboratableType' => 'task',
        'collaboratableId' => 1,
        'email' => 'user@example.com',
        'permission' => 'invalid',
    ]);

    $validator = Validator::make(
        ['collaborationPayload' => $payload],
        CollaborationPayloadValidation::createRules()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('collaborationPayload.permission'))->toBeTrue();
});

test('create collaboration invitation dto from validated maps and casts permission', function (): void {
    $validated = [
        'collaboratableType' => 'task',
        'collaboratableId' => 42,
        'email' => 'invitee@example.com',
        'permission' => CollaborationPermission::View->value,
    ];
    $inviterId = $this->user->id;

    $dto = CreateCollaborationInvitationDto::fromValidated($validated, $inviterId);

    expect($dto->collaboratableType)->toBe('task')
        ->and($dto->collaboratableId)->toBe(42)
        ->and($dto->inviterId)->toBe($inviterId)
        ->and($dto->inviteeEmail)->toBe('invitee@example.com')
        ->and($dto->permission)->toBe(CollaborationPermission::View);
});

test('create collaboration invitation dto from validated defaults permission to edit when missing', function (): void {
    $validated = [
        'collaboratableType' => 'event',
        'collaboratableId' => 1,
        'email' => 'a@b.com',
    ];

    $dto = CreateCollaborationInvitationDto::fromValidated($validated, $this->user->id);

    expect($dto->permission)->toBe(CollaborationPermission::Edit);
});

test('create collaboration invitation dto toServiceAttributes returns expected array', function (): void {
    $dto = new CreateCollaborationInvitationDto(
        collaboratableType: 'task',
        collaboratableId: 1,
        inviterId: $this->user->id,
        inviteeEmail: 'user@example.com',
        permission: CollaborationPermission::Edit,
    );

    $attrs = $dto->toServiceAttributes();

    expect($attrs)->toBe([
        'collaboratable_type' => Task::class,
        'collaboratable_id' => 1,
        'inviter_id' => $this->user->id,
        'invitee_email' => 'user@example.com',
        'permission' => CollaborationPermission::Edit,
    ]);
});

test('create collaboration invitation dto collaboratableMorphClass returns task class for task', function (): void {
    $dto = new CreateCollaborationInvitationDto(
        collaboratableType: 'task',
        collaboratableId: 1,
        inviterId: $this->user->id,
        inviteeEmail: 'a@b.com',
        permission: CollaborationPermission::Edit,
    );

    expect($dto->collaboratableMorphClass())->toBe(Task::class);
});

test('create collaboration invitation dto collaboratableMorphClass returns event class for event', function (): void {
    $dto = new CreateCollaborationInvitationDto(
        collaboratableType: 'event',
        collaboratableId: 1,
        inviterId: $this->user->id,
        inviteeEmail: 'a@b.com',
        permission: CollaborationPermission::Edit,
    );

    expect($dto->collaboratableMorphClass())->toBe(Event::class);
});

test('create collaboration invitation dto collaboratableMorphClass returns project class for project', function (): void {
    $dto = new CreateCollaborationInvitationDto(
        collaboratableType: 'project',
        collaboratableId: 1,
        inviterId: $this->user->id,
        inviteeEmail: 'a@b.com',
        permission: CollaborationPermission::Edit,
    );

    expect($dto->collaboratableMorphClass())->toBe(Project::class);
});

test('create collaboration invitation dto collaboratableMorphClass throws for unknown type', function (): void {
    $dto = new CreateCollaborationInvitationDto(
        collaboratableType: 'unknown',
        collaboratableId: 1,
        inviterId: $this->user->id,
        inviteeEmail: 'a@b.com',
        permission: CollaborationPermission::Edit,
    );

    $dto->collaboratableMorphClass();
})->throws(\InvalidArgumentException::class);

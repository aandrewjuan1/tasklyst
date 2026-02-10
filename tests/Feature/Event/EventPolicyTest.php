<?php

use App\Enums\CollaborationPermission;
use App\Models\Collaboration;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->collaboratorWithEdit = User::factory()->create();
    $this->collaboratorWithView = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('view any and create allow any authenticated user', function (): void {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('viewAny', Event::class))->toBeTrue()
        ->and(Gate::forUser($user)->allows('create', Event::class))->toBeTrue();
});

test('owner can view event', function (): void {
    $event = Event::factory()->for($this->owner)->create();

    expect($this->owner->can('view', $event))->toBeTrue();
});

test('collaborator with edit permission can view event', function (): void {
    $event = Event::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Event::class,
        'collaboratable_id' => $event->id,
        'user_id' => $this->collaboratorWithEdit->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    expect($this->collaboratorWithEdit->can('view', $event))->toBeTrue();
});

test('collaborator with view permission can view event', function (): void {
    $event = Event::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Event::class,
        'collaboratable_id' => $event->id,
        'user_id' => $this->collaboratorWithView->id,
        'permission' => CollaborationPermission::View,
    ]);

    expect($this->collaboratorWithView->can('view', $event))->toBeTrue();
});

test('other user cannot view event', function (): void {
    $event = Event::factory()->for($this->owner)->create();

    expect($this->otherUser->can('view', $event))->toBeFalse();
});

test('owner can update event', function (): void {
    $event = Event::factory()->for($this->owner)->create();

    expect($this->owner->can('update', $event))->toBeTrue();
});

test('collaborator with edit permission can update event', function (): void {
    $event = Event::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Event::class,
        'collaboratable_id' => $event->id,
        'user_id' => $this->collaboratorWithEdit->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    expect($this->collaboratorWithEdit->can('update', $event))->toBeTrue();
});

test('collaborator with view permission cannot update event', function (): void {
    $event = Event::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Event::class,
        'collaboratable_id' => $event->id,
        'user_id' => $this->collaboratorWithView->id,
        'permission' => CollaborationPermission::View,
    ]);

    expect($this->collaboratorWithView->can('update', $event))->toBeFalse();
});

test('other user cannot update event', function (): void {
    $event = Event::factory()->for($this->owner)->create();

    expect($this->otherUser->can('update', $event))->toBeFalse();
});

test('owner can delete event', function (): void {
    $event = Event::factory()->for($this->owner)->create();

    expect($this->owner->can('delete', $event))->toBeTrue();
});

test('collaborator cannot delete event', function (): void {
    $event = Event::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Event::class,
        'collaboratable_id' => $event->id,
        'user_id' => $this->collaboratorWithEdit->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    expect($this->collaboratorWithEdit->can('delete', $event))->toBeFalse();
});

test('other user cannot delete event', function (): void {
    $event = Event::factory()->for($this->owner)->create();

    expect($this->otherUser->can('delete', $event))->toBeFalse();
});

test('owner can restore and force delete event', function (): void {
    $event = Event::factory()->for($this->owner)->create();

    expect($this->owner->can('restore', $event))->toBeTrue()
        ->and($this->owner->can('forceDelete', $event))->toBeTrue();
});

test('collaborator cannot restore or force delete event', function (): void {
    $event = Event::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Event::class,
        'collaboratable_id' => $event->id,
        'user_id' => $this->collaboratorWithEdit->id,
        'permission' => CollaborationPermission::Edit,
    ]);

    expect($this->collaboratorWithEdit->can('restore', $event))->toBeFalse()
        ->and($this->collaboratorWithEdit->can('forceDelete', $event))->toBeFalse();
});

<?php

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('view any and create allow any authenticated user', function (): void {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('viewAny', Teacher::class))->toBeTrue()
        ->and(Gate::forUser($user)->allows('create', Teacher::class))->toBeTrue();
});

test('owner can view teacher', function (): void {
    $teacher = Teacher::factory()->for($this->owner)->create();

    expect($this->owner->can('view', $teacher))->toBeTrue();
});

test('other user cannot view teacher', function (): void {
    $teacher = Teacher::factory()->for($this->owner)->create();

    expect($this->otherUser->can('view', $teacher))->toBeFalse();
});

test('owner can update teacher', function (): void {
    $teacher = Teacher::factory()->for($this->owner)->create();

    expect($this->owner->can('update', $teacher))->toBeTrue();
});

test('other user cannot update teacher', function (): void {
    $teacher = Teacher::factory()->for($this->owner)->create();

    expect($this->otherUser->can('update', $teacher))->toBeFalse();
});

test('owner can delete teacher', function (): void {
    $teacher = Teacher::factory()->for($this->owner)->create();

    expect($this->owner->can('delete', $teacher))->toBeTrue();
});

test('other user cannot delete teacher', function (): void {
    $teacher = Teacher::factory()->for($this->owner)->create();

    expect($this->otherUser->can('delete', $teacher))->toBeFalse();
});

test('owner can restore and force delete teacher', function (): void {
    $teacher = Teacher::factory()->for($this->owner)->create();

    expect($this->owner->can('restore', $teacher))->toBeTrue()
        ->and($this->owner->can('forceDelete', $teacher))->toBeTrue();
});

test('other user cannot restore or force delete teacher', function (): void {
    $teacher = Teacher::factory()->for($this->owner)->create();

    expect($this->otherUser->can('restore', $teacher))->toBeFalse()
        ->and($this->otherUser->can('forceDelete', $teacher))->toBeFalse();
});

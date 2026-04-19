<?php

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('view any and create allow any authenticated user', function (): void {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('viewAny', SchoolClass::class))->toBeTrue()
        ->and(Gate::forUser($user)->allows('create', SchoolClass::class))->toBeTrue();
});

test('owner can view school class', function (): void {
    $schoolClass = SchoolClass::factory()->for($this->owner)->create();

    expect($this->owner->can('view', $schoolClass))->toBeTrue();
});

test('other user cannot view school class', function (): void {
    $schoolClass = SchoolClass::factory()->for($this->owner)->create();

    expect($this->otherUser->can('view', $schoolClass))->toBeFalse();
});

test('owner can update school class', function (): void {
    $schoolClass = SchoolClass::factory()->for($this->owner)->create();

    expect($this->owner->can('update', $schoolClass))->toBeTrue();
});

test('other user cannot update school class', function (): void {
    $schoolClass = SchoolClass::factory()->for($this->owner)->create();

    expect($this->otherUser->can('update', $schoolClass))->toBeFalse();
});

test('owner can delete school class', function (): void {
    $schoolClass = SchoolClass::factory()->for($this->owner)->create();

    expect($this->owner->can('delete', $schoolClass))->toBeTrue();
});

test('other user cannot delete school class', function (): void {
    $schoolClass = SchoolClass::factory()->for($this->owner)->create();

    expect($this->otherUser->can('delete', $schoolClass))->toBeFalse();
});

test('owner can restore and force delete school class', function (): void {
    $schoolClass = SchoolClass::factory()->for($this->owner)->create();

    expect($this->owner->can('restore', $schoolClass))->toBeTrue()
        ->and($this->owner->can('forceDelete', $schoolClass))->toBeTrue();
});

test('other user cannot restore or force delete school class', function (): void {
    $schoolClass = SchoolClass::factory()->for($this->owner)->create();

    expect($this->otherUser->can('restore', $schoolClass))->toBeFalse()
        ->and($this->otherUser->can('forceDelete', $schoolClass))->toBeFalse();
});

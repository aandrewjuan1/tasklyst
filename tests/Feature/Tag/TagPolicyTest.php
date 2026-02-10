<?php

use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('view any and create allow any authenticated user', function (): void {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('viewAny', Tag::class))->toBeTrue()
        ->and(Gate::forUser($user)->allows('create', Tag::class))->toBeTrue();
});

test('owner can view tag', function (): void {
    $tag = Tag::factory()->for($this->owner)->create();

    expect($this->owner->can('view', $tag))->toBeTrue();
});

test('other user cannot view tag', function (): void {
    $tag = Tag::factory()->for($this->owner)->create();

    expect($this->otherUser->can('view', $tag))->toBeFalse();
});

test('owner can update tag', function (): void {
    $tag = Tag::factory()->for($this->owner)->create();

    expect($this->owner->can('update', $tag))->toBeTrue();
});

test('other user cannot update tag', function (): void {
    $tag = Tag::factory()->for($this->owner)->create();

    expect($this->otherUser->can('update', $tag))->toBeFalse();
});

test('owner can delete tag', function (): void {
    $tag = Tag::factory()->for($this->owner)->create();

    expect($this->owner->can('delete', $tag))->toBeTrue();
});

test('other user cannot delete tag', function (): void {
    $tag = Tag::factory()->for($this->owner)->create();

    expect($this->otherUser->can('delete', $tag))->toBeFalse();
});

test('owner can restore and force delete tag', function (): void {
    $tag = Tag::factory()->for($this->owner)->create();

    expect($this->owner->can('restore', $tag))->toBeTrue()
        ->and($this->owner->can('forceDelete', $tag))->toBeTrue();
});

test('other user cannot restore or force delete tag', function (): void {
    $tag = Tag::factory()->for($this->owner)->create();

    expect($this->otherUser->can('restore', $tag))->toBeFalse()
        ->and($this->otherUser->can('forceDelete', $tag))->toBeFalse();
});

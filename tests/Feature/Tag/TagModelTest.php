<?php

use App\Models\Event;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('scope for user returns tags owned by the user', function (): void {
    $owned = Tag::factory()->for($this->owner)->create(['name' => 'Owned tag']);
    Tag::factory()->for($this->otherUser)->create(['name' => 'Other tag']);

    $tags = Tag::query()->forUser($this->owner->id)->get();

    expect($tags)->toHaveCount(1)
        ->and($tags->first()->id)->toBe($owned->id);
});

test('scope for user does not return other users tags', function (): void {
    Tag::factory()->for($this->owner)->create(['name' => 'Owner only tag']);

    $tags = Tag::query()->forUser($this->otherUser->id)->get();

    expect($tags)->toHaveCount(0);
});

test('scope by name matches case insensitively', function (): void {
    $tag = Tag::factory()->for($this->owner)->create(['name' => 'Work']);

    $found = Tag::query()->forUser($this->owner->id)->byName('WORK')->first();

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($tag->id);
});

test('validIdsForUser returns only existing tag ids belonging to user', function (): void {
    $tag1 = Tag::factory()->for($this->owner)->create();
    $tag2 = Tag::factory()->for($this->owner)->create();

    $ids = Tag::validIdsForUser($this->owner->id, [$tag1->id, $tag2->id]);

    expect($ids)->toEqualCanonicalizing([$tag1->id, $tag2->id]);
});

test('validIdsForUser filters out other users tags and non-existent ids', function (): void {
    $ownTag = Tag::factory()->for($this->owner)->create();
    $otherTag = Tag::factory()->for($this->otherUser)->create();

    $ids = Tag::validIdsForUser($this->owner->id, [$ownTag->id, $otherTag->id, 99999]);

    expect($ids)->toEqual([$ownTag->id]);
});

test('validIdsForUser returns empty array when given empty or invalid ids', function (): void {
    expect(Tag::validIdsForUser($this->owner->id, []))->toEqual([])
        ->and(Tag::validIdsForUser($this->owner->id, [0, -1, 99999]))->toEqual([]);
});

test('validIdsForUser deduplicates ids', function (): void {
    $tag = Tag::factory()->for($this->owner)->create();

    $ids = Tag::validIdsForUser($this->owner->id, [$tag->id, $tag->id]);

    expect($ids)->toEqual([$tag->id]);
});

test('tag belongs to user', function (): void {
    $tag = Tag::factory()->for($this->owner)->create();

    expect($tag->user)->not->toBeNull()
        ->and($tag->user->id)->toBe($this->owner->id);
});

test('tag tasks relationship returns attached tasks', function (): void {
    $tag = Tag::factory()->for($this->owner)->create();
    $task = Task::factory()->for($this->owner)->create();
    $task->tags()->attach($tag->id);

    $tag->load('tasks');
    expect($tag->tasks)->toHaveCount(1)
        ->and($tag->tasks->first()->id)->toBe($task->id);
});

test('tag events relationship returns attached events', function (): void {
    $tag = Tag::factory()->for($this->owner)->create();
    $event = Event::factory()->for($this->owner)->create();
    $event->tags()->attach($tag->id);

    $tag->load('events');
    expect($tag->events)->toHaveCount(1)
        ->and($tag->events->first()->id)->toBe($event->id);
});

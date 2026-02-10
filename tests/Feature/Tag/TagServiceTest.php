<?php

use App\Models\Tag;
use App\Models\User;
use App\Services\TagService;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->service = app(TagService::class);
});

test('create tag sets user_id and name', function (): void {
    $tag = $this->service->createTag($this->user, ['name' => 'Work']);

    expect($tag)->toBeInstanceOf(Tag::class)
        ->and($tag->user_id)->toBe($this->user->id)
        ->and($tag->name)->toBe('Work')
        ->and($tag->exists)->toBeTrue();
});

test('update tag updates name and does not allow user_id override', function (): void {
    $tag = Tag::factory()->for($this->user)->create(['name' => 'Original']);
    $otherUser = User::factory()->create();

    $updated = $this->service->updateTag($tag, [
        'name' => 'Updated name',
        'user_id' => $otherUser->id,
    ]);

    expect($updated->name)->toBe('Updated name')
        ->and($tag->fresh()->name)->toBe('Updated name')
        ->and($tag->fresh()->user_id)->toBe($this->user->id);
});

test('delete tag removes tag from database', function (): void {
    $tag = Tag::factory()->for($this->user)->create();

    $result = $this->service->deleteTag($tag);

    expect($result)->toBeTrue()
        ->and(Tag::find($tag->id))->toBeNull();
});

test('resolveTagIdsFromPayload with only tagIds returns deduplicated valid ids for user', function (): void {
    $tag1 = Tag::factory()->for($this->user)->create();
    $tag2 = Tag::factory()->for($this->user)->create();

    $ids = $this->service->resolveTagIdsFromPayload($this->user, [
        'tagIds' => [$tag1->id, $tag2->id, $tag1->id],
    ]);

    expect($ids)->toEqualCanonicalizing([$tag1->id, $tag2->id]);
});

test('resolveTagIdsFromPayload with pendingTagNames finds existing tag by name and returns its id', function (): void {
    $existing = Tag::factory()->for($this->user)->create(['name' => 'Existing']);

    $ids = $this->service->resolveTagIdsFromPayload($this->user, [
        'tagIds' => [],
        'pendingTagNames' => ['Existing'],
    ]);

    expect($ids)->toEqual([$existing->id]);
});

test('resolveTagIdsFromPayload with pendingTagNames creates new tag when name does not exist', function (): void {
    $ids = $this->service->resolveTagIdsFromPayload($this->user, [
        'tagIds' => [],
        'pendingTagNames' => ['NewTag'],
    ]);

    expect($ids)->toHaveCount(1);
    $tag = Tag::query()->forUser($this->user->id)->byName('NewTag')->first();
    expect($tag)->not->toBeNull()
        ->and($ids[0])->toBe($tag->id);
});

test('resolveTagIdsFromPayload merges tagIds and created or found ids and deduplicates', function (): void {
    $tag1 = Tag::factory()->for($this->user)->create();
    $existingByName = Tag::factory()->for($this->user)->create(['name' => 'ByName']);

    $ids = $this->service->resolveTagIdsFromPayload($this->user, [
        'tagIds' => [$tag1->id],
        'pendingTagNames' => ['ByName', 'BrandNew'],
    ]);

    expect($ids)->toHaveCount(3)
        ->and($ids)->toContain($tag1->id)
        ->and($ids)->toContain($existingByName->id);
    $newTag = Tag::query()->forUser($this->user->id)->byName('BrandNew')->first();
    expect($newTag)->not->toBeNull()
        ->and($ids)->toContain($newTag->id);
});

test('resolveTagIdsFromPayload ignores empty pending names', function (): void {
    $ids = $this->service->resolveTagIdsFromPayload($this->user, [
        'tagIds' => [],
        'pendingTagNames' => ['', '  ', "\t"],
    ]);

    expect($ids)->toEqual([]);
    expect(Tag::query()->forUser($this->user->id)->count())->toBe(0);
});

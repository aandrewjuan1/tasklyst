<?php

use App\Models\Tag;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('create tag with valid name creates tag in database', function (): void {
    $this->actingAs($this->owner);

    Livewire::test('pages::workspace.index')
        ->call('createTag', 'My Tag');

    $tag = Tag::query()->where('user_id', $this->owner->id)->where('name', 'My Tag')->first();
    expect($tag)->not->toBeNull()
        ->and($tag->user_id)->toBe($this->owner->id);
});

test('create tag with empty name does not create tag', function (): void {
    $this->actingAs($this->owner);
    $countBefore = Tag::query()->where('user_id', $this->owner->id)->count();

    Livewire::test('pages::workspace.index')
        ->call('createTag', '');

    expect(Tag::query()->where('user_id', $this->owner->id)->count())->toBe($countBefore);
});

test('create tag with whitespace only name does not create tag', function (): void {
    $this->actingAs($this->owner);
    $countBefore = Tag::query()->where('user_id', $this->owner->id)->count();

    Livewire::test('pages::workspace.index')
        ->call('createTag', '   ');

    expect(Tag::query()->where('user_id', $this->owner->id)->count())->toBe($countBefore);
});

test('owner can delete tag and tag is removed', function (): void {
    $this->actingAs($this->owner);
    $tag = Tag::factory()->for($this->owner)->create(['name' => 'To delete']);
    $tagId = $tag->id;

    Livewire::test('pages::workspace.index')
        ->call('deleteTag', $tagId);

    expect(Tag::find($tagId))->toBeNull();
});

test('delete tag with non existent id does not throw', function (): void {
    $this->actingAs($this->owner);
    $countBefore = Tag::query()->count();

    Livewire::test('pages::workspace.index')
        ->call('deleteTag', 99999);

    expect(Tag::query()->count())->toBe($countBefore);
});

test('other user cannot delete tag not owned by them', function (): void {
    $tag = Tag::factory()->for($this->owner)->create(['name' => 'Owner tag']);
    $this->actingAs($this->otherUser);

    Livewire::test('pages::workspace.index')
        ->call('deleteTag', $tag->id);

    expect(Tag::find($tag->id))->not->toBeNull();
});

test('tags computed returns only authenticated user tags', function (): void {
    $tag1 = Tag::factory()->for($this->owner)->create(['name' => 'A']);
    Tag::factory()->for($this->otherUser)->create(['name' => 'B']);
    $this->actingAs($this->owner);

    $component = Livewire::test('pages::workspace.index');
    $tags = $component->get('tags');

    expect($tags)->toHaveCount(1)
        ->and($tags->first()->id)->toBe($tag1->id)
        ->and($tags->first()->name)->toBe('A');
});

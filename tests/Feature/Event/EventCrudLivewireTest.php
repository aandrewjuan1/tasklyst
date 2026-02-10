<?php

use App\Enums\CollaborationPermission;
use App\Enums\EventStatus;
use App\Models\Collaboration;
use App\Models\Event;
use App\Models\Tag;
use App\Models\User;
use App\Support\Validation\EventPayloadValidation;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->collaboratorWithEdit = User::factory()->create();
    $this->collaboratorWithView = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

test('create event with valid payload creates event in database', function (): void {
    $this->actingAs($this->owner);

    Livewire::test('pages::workspace.index')
        ->call('createEvent', [
            'title' => 'Livewire created event',
        ]);

    $event = Event::query()->where('user_id', $this->owner->id)->where('title', 'Livewire created event')->first();
    expect($event)->not->toBeNull()
        ->and($event->user_id)->toBe($this->owner->id);
});

test('create event with empty title does not create event', function (): void {
    $this->actingAs($this->owner);
    $payload = array_replace_recursive(EventPayloadValidation::defaults(), ['title' => '']);

    Livewire::test('pages::workspace.index')
        ->call('createEvent', $payload);

    $count = Event::query()->where('user_id', $this->owner->id)->count();
    expect($count)->toBe(0);
});

test('create event with tag ids attaches tags', function (): void {
    $this->actingAs($this->owner);
    $tag = Tag::factory()->for($this->owner)->create();

    Livewire::test('pages::workspace.index')
        ->call('createEvent', [
            'title' => 'Event with tag',
            'tagIds' => [$tag->id],
        ]);

    $event = Event::query()->where('user_id', $this->owner->id)->where('title', 'Event with tag')->first();
    expect($event)->not->toBeNull();
    $event->load('tags');
    expect($event->tags->pluck('id')->toArray())->toContain($tag->id);
});

test('owner can delete event and event is soft deleted', function (): void {
    $this->actingAs($this->owner);
    $event = Event::factory()->for($this->owner)->create(['title' => 'To delete']);

    Livewire::test('pages::workspace.index')
        ->call('deleteEvent', $event->id);

    $event->refresh();
    expect($event->trashed())->toBeTrue();
});

test('delete event with non existent id does not delete any event', function (): void {
    $this->actingAs($this->owner);
    $countBefore = Event::query()->count();

    Livewire::test('pages::workspace.index')
        ->call('deleteEvent', 99999);

    expect(Event::query()->count())->toBe($countBefore);
});

test('collaborator with edit permission cannot delete event', function (): void {
    $event = Event::factory()->for($this->owner)->create();
    Collaboration::create([
        'collaboratable_type' => Event::class,
        'collaboratable_id' => $event->id,
        'user_id' => $this->collaboratorWithEdit->id,
        'permission' => CollaborationPermission::Edit,
    ]);
    $this->actingAs($this->collaboratorWithEdit);

    Livewire::test('pages::workspace.index')
        ->call('deleteEvent', $event->id)
        ->assertForbidden();

    expect($event->fresh()->trashed())->toBeFalse();
});

test('other user cannot delete event not shared with them', function (): void {
    $event = Event::factory()->for($this->owner)->create();
    $this->actingAs($this->otherUser);

    Livewire::test('pages::workspace.index')
        ->call('deleteEvent', $event->id);

    expect($event->fresh()->trashed())->toBeFalse();
});

test('owner can update event property title', function (): void {
    $this->actingAs($this->owner);
    $event = Event::factory()->for($this->owner)->create(['title' => 'Original title']);

    Livewire::test('pages::workspace.index')
        ->call('updateEventProperty', $event->id, 'title', 'Updated title');

    expect($event->fresh()->title)->toBe('Updated title');
});

test('owner can update event property status', function (): void {
    $this->actingAs($this->owner);
    $event = Event::factory()->for($this->owner)->create(['status' => EventStatus::Scheduled]);

    Livewire::test('pages::workspace.index')
        ->call('updateEventProperty', $event->id, 'status', EventStatus::Ongoing->value);

    expect($event->fresh()->status)->toBe(EventStatus::Ongoing);
});

test('update event property with invalid property name does not update event', function (): void {
    $this->actingAs($this->owner);
    $event = Event::factory()->for($this->owner)->create(['title' => 'Unchanged']);

    Livewire::test('pages::workspace.index')
        ->call('updateEventProperty', $event->id, 'invalidProperty', 'value');

    expect($event->fresh()->title)->toBe('Unchanged');
});

test('update event property with empty title does not update event', function (): void {
    $this->actingAs($this->owner);
    $event = Event::factory()->for($this->owner)->create(['title' => 'Original']);

    Livewire::test('pages::workspace.index')
        ->call('updateEventProperty', $event->id, 'title', '   ');

    expect($event->fresh()->title)->toBe('Original');
});

test('collaborator with edit permission can update event property', function (): void {
    $event = Event::factory()->for($this->owner)->create(['title' => 'Shared event']);
    Collaboration::create([
        'collaboratable_type' => Event::class,
        'collaboratable_id' => $event->id,
        'user_id' => $this->collaboratorWithEdit->id,
        'permission' => CollaborationPermission::Edit,
    ]);
    $this->actingAs($this->collaboratorWithEdit);

    Livewire::test('pages::workspace.index')
        ->call('updateEventProperty', $event->id, 'title', 'Updated by collaborator');

    expect($event->fresh()->title)->toBe('Updated by collaborator');
});

test('collaborator with view only permission cannot update event property', function (): void {
    $event = Event::factory()->for($this->owner)->create(['title' => 'View only event']);
    Collaboration::create([
        'collaboratable_type' => Event::class,
        'collaboratable_id' => $event->id,
        'user_id' => $this->collaboratorWithView->id,
        'permission' => CollaborationPermission::View,
    ]);
    $this->actingAs($this->collaboratorWithView);

    Livewire::test('pages::workspace.index')
        ->call('updateEventProperty', $event->id, 'title', 'Should not update')
        ->assertForbidden();

    expect($event->fresh()->title)->toBe('View only event');
});

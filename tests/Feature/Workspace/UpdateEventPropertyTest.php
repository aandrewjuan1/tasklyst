<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

it('updates event status inline', function (): void {
    $user = User::factory()->create();
    $event = Event::factory()->for($user)->create([
        'status' => EventStatus::Scheduled,
    ]);

    actingAs($user);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateEventProperty', $event->id, 'status', EventStatus::Completed->value);

    $event->refresh();
    expect($event->status)->toBe(EventStatus::Completed);
});

it('rejects empty inline event title', function (): void {
    $user = User::factory()->create();
    $event = Event::factory()->for($user)->create([
        'title' => 'Original',
    ]);

    actingAs($user);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateEventProperty', $event->id, 'title', '   ');

    $event->refresh();
    expect($event->title)->toBe('Original');
});

it('prevents setting end before start when updating event dates inline', function (): void {
    $user = User::factory()->create();
    $start = Carbon::parse('2024-01-01 10:00:00');
    $end = Carbon::parse('2024-01-01 12:00:00');

    $event = Event::factory()->for($user)->create([
        'start_datetime' => $start,
        'end_datetime' => $end,
    ]);

    actingAs($user);

    $invalidEnd = $start->copy()->subHour()->toDateTimeString();

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateEventProperty', $event->id, 'endDatetime', $invalidEnd);

    $event->refresh();
    expect($event->end_datetime->equalTo($end))->toBeTrue();
});

it('syncs tagIds inline for events', function (): void {
    $user = User::factory()->create();
    $tag1 = Tag::factory()->for($user)->create();
    $tag2 = Tag::factory()->for($user)->create();

    $event = Event::factory()->for($user)->create();
    $event->tags()->attach($tag1);

    actingAs($user);

    Livewire::actingAs($user)
        ->test('pages::workspace.index')
        ->call('updateEventProperty', $event->id, 'tagIds', [$tag2->id]);

    $event->refresh();
    expect($event->tags->pluck('id')->all())->toBe([$tag2->id]);
});

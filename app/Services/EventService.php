<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EventService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createEvent(User $user, array $attributes): Event
    {
        return DB::transaction(function () use ($user, $attributes): Event {
            $tagIds = $attributes['tagIds'] ?? [];
            unset($attributes['tagIds']);

            $event = Event::query()->create([
                ...$attributes,
                'user_id' => $user->id,
            ]);

            if (! empty($tagIds)) {
                $event->tags()->attach($tagIds);
            }

            return $event;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateEvent(Event $event, array $attributes): Event
    {
        unset($attributes['user_id']);

        return DB::transaction(function () use ($event, $attributes): Event {
            $event->fill($attributes);
            $event->save();

            return $event;
        });
    }

    public function deleteEvent(Event $event): bool
    {
        return DB::transaction(function () use ($event): bool {
            return (bool) $event->delete();
        });
    }
}

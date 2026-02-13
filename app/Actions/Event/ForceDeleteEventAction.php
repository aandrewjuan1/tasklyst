<?php

namespace App\Actions\Event;

use App\Models\Event;
use App\Models\User;
use App\Services\EventService;

class ForceDeleteEventAction
{
    public function __construct(
        private EventService $eventService
    ) {}

    public function execute(Event $event, ?User $actor = null): bool
    {
        return $this->eventService->forceDeleteEvent($event, $actor);
    }
}

<?php

namespace App\Actions\Event;

use App\Models\Event;
use App\Services\EventService;

class DeleteEventAction
{
    public function __construct(
        private EventService $eventService
    ) {}

    public function execute(Event $event): bool
    {
        return $this->eventService->deleteEvent($event);
    }
}

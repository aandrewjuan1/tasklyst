<?php

namespace App\Actions\Event;

use App\Models\EventException;
use App\Services\EventService;

class DeleteEventExceptionAction
{
    public function __construct(
        private EventService $eventService
    ) {}

    public function execute(EventException $exception): bool
    {
        return $this->eventService->deleteEventException($exception);
    }
}

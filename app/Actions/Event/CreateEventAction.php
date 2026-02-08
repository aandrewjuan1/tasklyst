<?php

namespace App\Actions\Event;

use App\DataTransferObjects\Event\CreateEventDto;
use App\Models\Event;
use App\Models\User;
use App\Services\EventService;

class CreateEventAction
{
    public function __construct(
        private EventService $eventService
    ) {}

    public function execute(User $user, CreateEventDto $dto): Event
    {
        return $this->eventService->createEvent($user, $dto->toServiceAttributes());
    }
}

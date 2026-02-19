<?php

namespace App\Actions\Event;

use App\DataTransferObjects\Event\UpdateEventExceptionDto;
use App\Models\EventException;
use App\Services\EventService;

class UpdateEventExceptionAction
{
    public function __construct(
        private EventService $eventService
    ) {}

    public function execute(EventException $exception, UpdateEventExceptionDto $dto): EventException
    {
        $attributes = $dto->toServiceAttributes();

        return $attributes !== []
            ? $this->eventService->updateEventException($exception, $attributes)
            : $exception->fresh();
    }
}

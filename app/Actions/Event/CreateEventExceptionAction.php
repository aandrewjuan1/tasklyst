<?php

namespace App\Actions\Event;

use App\DataTransferObjects\Event\CreateEventExceptionDto;
use App\Models\EventException;
use App\Models\EventInstance;
use App\Models\RecurringEvent;
use App\Models\User;
use App\Services\EventService;
use Illuminate\Support\Carbon;

class CreateEventExceptionAction
{
    public function __construct(
        private EventService $eventService
    ) {}

    public function execute(User $user, CreateEventExceptionDto $dto): EventException
    {
        $recurringEvent = RecurringEvent::query()->findOrFail($dto->recurringEventId);
        $date = Carbon::parse($dto->exceptionDate);

        $replacement = null;
        if ($dto->replacementInstanceId !== null) {
            $replacement = EventInstance::query()
                ->where('recurring_event_id', $recurringEvent->id)
                ->findOrFail($dto->replacementInstanceId);
        }

        return $this->eventService->createEventException(
            $recurringEvent,
            $date,
            $dto->isDeleted,
            $replacement,
            $user,
            $dto->reason
        );
    }
}

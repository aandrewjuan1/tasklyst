<?php

namespace App\Actions\Llm;

use App\Actions\Event\CreateEventAction;
use App\DataTransferObjects\Event\CreateEventDto;
use App\DataTransferObjects\Llm\ToolResultDto;
use App\Models\User;

class CreateEventFromLlmAction
{
    public function __construct(
        private readonly CreateEventAction $createEventAction,
    ) {}

    /**
     * @param  array<string, mixed>  $args
     */
    public function __invoke(array $args, User $user): ToolResultDto
    {
        $dto = new CreateEventDto(
            title: (string) ($args['title'] ?? ''),
            description: null,
            status: null,
            startDatetime: \App\Support\DateHelper::parseOptional($args['start_datetime'] ?? null),
            endDatetime: \App\Support\DateHelper::parseOptional($args['end_datetime'] ?? null),
            allDay: (bool) ($args['all_day'] ?? false),
            tagIds: [],
            recurrence: null,
        );

        $event = $this->createEventAction->execute($user, $dto);

        return new ToolResultDto(
            tool: 'create_event',
            success: true,
            payload: [
                'id' => $event->id,
                'title' => $event->title,
                'start_datetime' => $event->start_datetime,
                'end_datetime' => $event->end_datetime,
                'all_day' => $event->all_day,
            ],
        );
    }
}

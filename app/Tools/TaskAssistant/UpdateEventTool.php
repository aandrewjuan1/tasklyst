<?php

namespace App\Tools\TaskAssistant;

use App\Actions\Event\UpdateEventPropertyAction;
use App\Models\Event;

class UpdateEventTool extends DelegatingTool
{
    public function __construct(
        \App\Models\User $user,
        private readonly UpdateEventPropertyAction $updateEventPropertyAction
    ) {
        parent::__construct($user);

        $this->as('update_event')
            ->for('Update an existing event property.')
            ->withNumberParameter('eventId', 'ID of the event to update', true)
            ->withStringParameter('property', 'Property to update: title, description, status, startDatetime, endDatetime, allDay, tagIds, recurrence', true)
            ->withStringParameter('value', 'New value (JSON for tagIds/recurrence)', true)
            ->withStringParameter('occurrenceDate', 'Optional date for recurring event (Y-m-d)', false)
            ->withStringParameter('operation_token', 'Optional idempotency token', false)
            ->using($this);

        $this->action = function (array $params): array {
            $event = Event::query()
                ->forUser($this->user->id)
                ->findOrFail((int) $params['eventId']);
            $property = (string) $params['property'];
            $value = $params['value'];
            if (in_array($property, ['tagIds', 'recurrence'], true) && is_string($value)) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : $value;
            }
            $occurrenceDate = isset($params['occurrenceDate']) ? (string) $params['occurrenceDate'] : null;
            $this->updateEventPropertyAction->execute($event, $property, $value, $occurrenceDate, $this->user);

            return [
                'ok' => true,
                'message' => __('Event updated.'),
                'event' => ['id' => $event->id, 'title' => $event->title],
            ];
        };
    }

    public function __invoke(mixed ...$args): string
    {
        $params = $this->normalizeParams(...$args);
        $operationToken = isset($params['operation_token']) ? (string) $params['operation_token'] : null;

        return $this->runDelegatedAction($params, 'update_event', $operationToken);
    }
}

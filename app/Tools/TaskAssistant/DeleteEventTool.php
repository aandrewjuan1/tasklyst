<?php

namespace App\Tools\TaskAssistant;

use App\Actions\Event\DeleteEventAction;
use App\Models\Event;

class DeleteEventTool extends DelegatingTool
{
    public function __construct(
        \App\Models\User $user,
        private readonly DeleteEventAction $deleteEventAction
    ) {
        parent::__construct($user);

        $this->as('delete_event')
            ->for('Move an event to trash.')
            ->withNumberParameter('eventId', 'ID of the event to delete', true)
            ->withBooleanParameter('confirm', 'Set true to confirm deletion', false)
            ->withStringParameter('operation_token', 'Optional idempotency token', false)
            ->using($this);

        $this->action = function (array $params): array {
            $event = Event::query()
                ->forUser($this->user->id)
                ->findOrFail((int) $params['eventId']);
            $this->deleteEventAction->execute($event, $this->user);

            return [
                'ok' => true,
                'message' => __('Event moved to trash.'),
                'event_id' => $event->id,
            ];
        };
    }

    public function __invoke(mixed ...$args): string
    {
        $params = $this->normalizeParams(...$args);
        if (($params['confirm'] ?? false) !== true) {
            return json_encode([
                'ok' => false,
                'message' => __('Please confirm by calling again with confirm: true'),
                'requires_confirm' => true,
            ]);
        }
        $operationToken = isset($params['operation_token']) ? (string) $params['operation_token'] : null;

        return $this->runDelegatedAction($params, 'delete_event', $operationToken);
    }
}

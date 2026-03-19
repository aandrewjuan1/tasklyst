<?php

namespace App\Tools\LLM\TaskAssistant;

use App\Actions\Event\RestoreEventAction;
use App\Models\Event;

class RestoreEventTool extends DelegatingTool
{
    public function __construct(
        \App\Models\User $user,
        private readonly RestoreEventAction $restoreEventAction
    ) {
        parent::__construct($user);

        $this->as('restore_event')
            ->for('Restore an event from trash.')
            ->withNumberParameter('eventId', 'ID of the event to restore', true)
            ->withStringParameter('operation_token', 'Optional idempotency token', false)
            ->using($this);

        $this->action = function (array $params): array {
            $event = Event::query()
                ->forUser($this->user->id)
                ->onlyTrashed()
                ->findOrFail((int) $params['eventId']);
            $this->restoreEventAction->execute($event, $this->user);

            return [
                'ok' => true,
                'message' => __('Event restored.'),
                'event_id' => $event->id,
            ];
        };
    }

    public function __invoke(mixed ...$args): string
    {
        $params = $this->normalizeParams(...$args);
        $operationToken = isset($params['operation_token']) ? (string) $params['operation_token'] : null;

        return $this->runDelegatedAction($params, 'restore_event', $operationToken);
    }
}

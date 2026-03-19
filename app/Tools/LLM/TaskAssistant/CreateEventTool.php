<?php

namespace App\Tools\LLM\TaskAssistant;

use App\Actions\Event\CreateEventAction;
use App\DataTransferObjects\Event\CreateEventDto;
use Illuminate\Support\Arr;

class CreateEventTool extends DelegatingTool
{
    public function __construct(
        \App\Models\User $user,
        private readonly CreateEventAction $createEventAction
    ) {
        parent::__construct($user);

        $this->as('create_event')
            ->for('Create a new event. Use when the user wants to add an event.')
            ->withStringParameter('title', 'Title of the event', true)
            ->withStringParameter('description', 'Optional description', false)
            ->withStringParameter('status', 'Optional status', false)
            ->withStringParameter('startDatetime', 'Optional ISO8601 start datetime', false)
            ->withStringParameter('endDatetime', 'Optional ISO8601 end datetime', false)
            ->withBooleanParameter('allDay', 'Whether the event is all-day', false)
            ->withStringParameter('tagIds', 'Optional JSON array of tag IDs', false)
            ->withStringParameter('recurrence', 'Optional JSON recurrence object', false)
            ->withStringParameter('operation_token', 'Optional idempotency token', false)
            ->using($this);

        $this->action = function (array $params): array {
            $validated = $this->buildValidatedFromParams($params);
            $dto = CreateEventDto::fromValidated($validated);
            $event = $this->createEventAction->execute($this->user, $dto);

            return [
                'ok' => true,
                'message' => __('Event created.'),
                'event' => [
                    'id' => $event->id,
                    'title' => $event->title,
                    'status' => $event->status?->value ?? $event->status,
                    'start_datetime' => $event->start_datetime?->toIso8601String(),
                    'end_datetime' => $event->end_datetime?->toIso8601String(),
                ],
            ];
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function buildValidatedFromParams(array $params): array
    {
        $tagIds = $params['tagIds'] ?? [];
        if (is_string($tagIds)) {
            $decoded = json_decode($tagIds, true);
            $tagIds = is_array($decoded) ? array_map('intval', $decoded) : [];
        }
        $tagIds = Arr::wrap($tagIds);
        $recurrence = $params['recurrence'] ?? null;
        if (is_string($recurrence)) {
            $decoded = json_decode($recurrence, true);
            $recurrence = is_array($decoded) ? $decoded : null;
        }

        return [
            'title' => (string) ($params['title'] ?? ''),
            'description' => isset($params['description']) ? (string) $params['description'] : null,
            'status' => isset($params['status']) ? (string) $params['status'] : null,
            'startDatetime' => $params['startDatetime'] ?? null,
            'endDatetime' => $params['endDatetime'] ?? null,
            'allDay' => (bool) ($params['allDay'] ?? false),
            'tagIds' => $tagIds,
            'recurrence' => $recurrence,
        ];
    }

    public function __invoke(mixed ...$args): string
    {
        $params = $this->normalizeParams(...$args);
        $operationToken = isset($params['operation_token']) ? (string) $params['operation_token'] : null;

        return $this->runDelegatedAction($params, 'create_event', $operationToken);
    }
}

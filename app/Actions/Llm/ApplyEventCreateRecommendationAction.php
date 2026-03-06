<?php

namespace App\Actions\Llm;

use App\Actions\Event\CreateEventAction;
use App\DataTransferObjects\Event\CreateEventDto;
use App\DataTransferObjects\Llm\EventCreateRecommendationDto;
use App\Enums\ActivityLogAction;
use App\Enums\LlmIntent;
use App\Models\Tag;
use App\Models\User;
use App\Services\ActivityLogRecorder;
use App\Services\TagService;
use App\Support\Validation\EventPayloadValidation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ApplyEventCreateRecommendationAction
{
    public function __construct(
        private CreateEventAction $createEvent,
        private TagService $tagService,
        private ActivityLogRecorder $activityLogRecorder,
    ) {}

    public function execute(User $user, EventCreateRecommendationDto $recommendation, LlmIntent $intent, string $userAction): void
    {
        if ($userAction === 'reject') {
            return;
        }

        $payload = array_replace_recursive(
            EventPayloadValidation::defaults(),
            $recommendation->toEventPayload(),
        );

        $validator = Validator::make(['eventPayload' => $payload], EventPayloadValidation::rules());
        if ($validator->fails()) {
            Log::warning('LLM event create recommendation failed validation.', [
                'user_id' => $user->id,
                'errors' => $validator->errors()->all(),
            ]);

            return;
        }

        $validatedEvent = $validator->validated()['eventPayload'];

        if (($validatedEvent['pendingTagNames'] ?? []) !== []) {
            Gate::authorize('create', Tag::class);
        }

        $tagIds = $this->tagService->resolveTagIdsFromPayload($user, $validatedEvent, 'event');
        $validatedEvent['tagIds'] = $tagIds;

        $dto = CreateEventDto::fromValidated($validatedEvent);

        try {
            $event = $this->createEvent->execute($user, $dto);
        } catch (\Throwable $e) {
            Log::error('Failed to create event from LLM recommendation.', [
                'user_id' => $user->id,
                'payload' => $validatedEvent,
                'exception' => $e,
            ]);

            return;
        }

        $this->activityLogRecorder->record(
            $event,
            $user,
            ActivityLogAction::FieldUpdated,
            [
                'field' => 'llm_recommendation',
                'from' => null,
                'to' => [
                    'intent' => $intent->value,
                    'entity_type' => 'event',
                    'user_action' => $userAction,
                    'reasoning' => $recommendation->reasoning,
                    'created' => [
                        'title' => $event->title,
                        'start_datetime' => $event->start_datetime,
                        'end_datetime' => $event->end_datetime,
                    ],
                ],
            ],
        );
    }
}

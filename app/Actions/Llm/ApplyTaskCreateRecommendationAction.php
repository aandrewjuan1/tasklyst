<?php

namespace App\Actions\Llm;

use App\Actions\Task\CreateTaskAction;
use App\DataTransferObjects\Llm\TaskCreateRecommendationDto;
use App\DataTransferObjects\Task\CreateTaskDto;
use App\Enums\ActivityLogAction;
use App\Enums\LlmIntent;
use App\Models\Event;
use App\Models\Project;
use App\Models\Tag;
use App\Models\User;
use App\Services\ActivityLogRecorder;
use App\Services\TagService;
use App\Support\Validation\TaskPayloadValidation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ApplyTaskCreateRecommendationAction
{
    public function __construct(
        private CreateTaskAction $createTask,
        private TagService $tagService,
        private ActivityLogRecorder $activityLogRecorder,
    ) {}

    public function execute(User $user, TaskCreateRecommendationDto $recommendation, LlmIntent $intent, string $userAction): void
    {
        if ($userAction === 'reject') {
            $this->activityLogRecorder->record(
                $user,
                $user,
                ActivityLogAction::FieldUpdated,
                [
                    'field' => 'llm_recommendation',
                    'from' => null,
                    'to' => [
                        'intent' => $intent->value,
                        'entity_type' => 'task',
                        'user_action' => $userAction,
                        'reasoning' => $recommendation->reasoning,
                        'created' => null,
                    ],
                ],
            );

            return;
        }

        $payload = array_replace_recursive(
            TaskPayloadValidation::defaults(),
            $recommendation->toTaskPayload(),
        );

        $validator = Validator::make(['taskPayload' => $payload], TaskPayloadValidation::rules());
        if ($validator->fails()) {
            Log::warning('LLM task create recommendation failed validation.', [
                'user_id' => $user->id,
                'errors' => $validator->errors()->all(),
            ]);

            return;
        }

        $validatedTask = $validator->validated()['taskPayload'];

        $projectId = $validatedTask['projectId'] ?? null;
        if ($projectId !== null) {
            $project = Project::query()->forUser($user->id)->find((int) $projectId);
            if ($project === null) {
                return;
            }
            Gate::authorize('update', $project);
        }

        $eventId = $validatedTask['eventId'] ?? null;
        if ($eventId !== null) {
            $event = Event::query()->forUser($user->id)->find((int) $eventId);
            if ($event === null) {
                return;
            }
            Gate::authorize('update', $event);
        }

        if (($validatedTask['pendingTagNames'] ?? []) !== []) {
            Gate::authorize('create', Tag::class);
        }

        $tagIds = $this->tagService->resolveTagIdsFromPayload($user, $validatedTask, 'task');
        $validatedTask['tagIds'] = $tagIds;

        $dto = CreateTaskDto::fromValidated($validatedTask);

        try {
            $task = $this->createTask->execute($user, $dto);
        } catch (\Throwable $e) {
            Log::error('Failed to create task from LLM recommendation.', [
                'user_id' => $user->id,
                'payload' => $validatedTask,
                'exception' => $e,
            ]);

            return;
        }

        $this->activityLogRecorder->record(
            $task,
            $user,
            ActivityLogAction::FieldUpdated,
            [
                'field' => 'llm_recommendation',
                'from' => null,
                'to' => [
                    'intent' => $intent->value,
                    'entity_type' => 'task',
                    'user_action' => $userAction,
                    'reasoning' => $recommendation->reasoning,
                    'created' => [
                        'title' => $task->title,
                        'start_datetime' => $task->start_datetime,
                        'end_datetime' => $task->end_datetime,
                        'priority' => $task->priority?->value,
                    ],
                ],
            ],
        );
    }
}

<?php

namespace App\Actions\Llm;

use App\Actions\Project\CreateProjectAction;
use App\DataTransferObjects\Llm\ProjectCreateRecommendationDto;
use App\DataTransferObjects\Project\CreateProjectDto;
use App\Enums\ActivityLogAction;
use App\Enums\LlmIntent;
use App\Models\User;
use App\Services\ActivityLogRecorder;
use App\Support\Validation\ProjectPayloadValidation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ApplyProjectCreateRecommendationAction
{
    public function __construct(
        private CreateProjectAction $createProject,
        private ActivityLogRecorder $activityLogRecorder,
    ) {}

    public function execute(User $user, ProjectCreateRecommendationDto $recommendation, LlmIntent $intent, string $userAction): void
    {
        if ($userAction === 'reject') {
            return;
        }

        $payload = array_replace_recursive(
            ProjectPayloadValidation::defaults(),
            $recommendation->toProjectPayload(),
        );

        $validator = Validator::make(['projectPayload' => $payload], ProjectPayloadValidation::rules());
        if ($validator->fails()) {
            Log::warning('LLM project create recommendation failed validation.', [
                'user_id' => $user->id,
                'errors' => $validator->errors()->all(),
            ]);

            return;
        }

        $validatedProject = $validator->validated()['projectPayload'];

        $dto = CreateProjectDto::fromValidated($validatedProject);

        try {
            $project = $this->createProject->execute($user, $dto);
        } catch (\Throwable $e) {
            Log::error('Failed to create project from LLM recommendation.', [
                'user_id' => $user->id,
                'payload' => $validatedProject,
                'exception' => $e,
            ]);

            return;
        }

        $this->activityLogRecorder->record(
            $project,
            $user,
            ActivityLogAction::FieldUpdated,
            [
                'field' => 'llm_recommendation',
                'from' => null,
                'to' => [
                    'intent' => $intent->value,
                    'entity_type' => 'project',
                    'user_action' => $userAction,
                    'reasoning' => $recommendation->reasoning,
                    'created' => [
                        'name' => $project->name,
                        'start_datetime' => $project->start_datetime,
                        'end_datetime' => $project->end_datetime,
                    ],
                ],
            ],
        );
    }
}

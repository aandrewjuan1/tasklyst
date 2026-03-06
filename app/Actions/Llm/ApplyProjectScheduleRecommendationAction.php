<?php

namespace App\Actions\Llm;

use App\Actions\Project\UpdateProjectPropertyAction;
use App\DataTransferObjects\Llm\ProjectScheduleRecommendationDto;
use App\Enums\ActivityLogAction;
use App\Enums\LlmIntent;
use App\Models\Project;
use App\Models\User;
use App\Services\ActivityLogRecorder;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ApplyProjectScheduleRecommendationAction
{
    public function __construct(
        private UpdateProjectPropertyAction $updateProjectProperty,
        private ActivityLogRecorder $activityLogRecorder
    ) {}

    /**
     * Apply a project scheduling or timeline-adjustment recommendation to the given project.
     *
     * @param  array<string, mixed>  $overrides  Optional user-modified values for startDatetime, endDatetime.
     */
    public function execute(
        User $user,
        Project $project,
        ProjectScheduleRecommendationDto $recommendation,
        LlmIntent $intent,
        string $userAction,
        array $overrides = []
    ): void {
        if ($userAction === 'reject') {
            $this->recordAudit($project, $user, $intent, $userAction, $recommendation, []);

            return;
        }

        $changes = [];

        $attributes = $recommendation->toProjectAttributes();
        $attributes = array_merge($attributes, $overrides);

        DB::transaction(function () use (&$changes, $project, $user, $attributes): void {
            foreach (['startDatetime', 'endDatetime'] as $property) {
                if (! array_key_exists($property, $attributes)) {
                    continue;
                }

                $oldValue = $project->getPropertyValueForUpdate($property);
                $newValue = $attributes[$property];

                if ($this->valuesAreEquivalent($oldValue, $newValue)) {
                    continue;
                }

                $result = $this->updateProjectProperty->execute($project, $property, $newValue, $user);

                if ($result->success) {
                    $changes[$property] = [
                        'from' => $result->oldValue,
                        'to' => $result->newValue,
                    ];
                }
            }
        });

        $this->recordAudit($project, $user, $intent, $userAction, $recommendation, $changes);
    }

    /**
     * @param  array<string, array{from:mixed,to:mixed}>  $changes
     */
    private function recordAudit(
        Project $project,
        User $user,
        LlmIntent $intent,
        string $userAction,
        ProjectScheduleRecommendationDto $recommendation,
        array $changes
    ): void {
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
                    'changes' => $changes,
                    'modified_fields' => array_keys($changes),
                ],
            ]
        );
    }

    private function valuesAreEquivalent(mixed $oldValue, mixed $newValue): bool
    {
        if ($oldValue instanceof CarbonInterface && $newValue instanceof CarbonInterface) {
            return $oldValue->eq($newValue);
        }

        return $oldValue === $newValue;
    }
}

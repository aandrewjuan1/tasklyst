<?php

namespace App\Actions\Llm;

use App\Actions\Project\UpdateProjectPropertyAction;
use App\DataTransferObjects\Llm\ProjectUpdatePropertiesRecommendationDto;
use App\Enums\ActivityLogAction;
use App\Enums\LlmIntent;
use App\Models\Project;
use App\Models\User;
use App\Services\ActivityLogRecorder;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ApplyProjectPropertiesRecommendationAction
{
    /**
     * @var list<string>
     */
    private const ALLOWED_PROPERTIES = [
        'name',
        'description',
        'startDatetime',
        'endDatetime',
    ];

    public function __construct(
        private UpdateProjectPropertyAction $updateProjectProperty,
        private ActivityLogRecorder $activityLogRecorder
    ) {}

    /**
     * Apply a generic project property update recommendation to the given project.
     *
     * @param  array<string, mixed>  $overrides  Optional user-modified values for properties.
     */
    public function execute(
        User $user,
        Project $project,
        ProjectUpdatePropertiesRecommendationDto $recommendation,
        LlmIntent $intent,
        string $userAction,
        array $overrides = []
    ): void {
        if ($userAction === 'reject') {
            $this->recordAudit($project, $user, $intent, $userAction, $recommendation, []);

            return;
        }

        $changes = [];

        $properties = array_merge($recommendation->proposedProperties(), $overrides);
        $properties = array_intersect_key($properties, array_flip(self::ALLOWED_PROPERTIES));

        if ($properties === []) {
            $this->recordAudit($project, $user, $intent, $userAction, $recommendation, []);

            return;
        }

        DB::transaction(function () use (&$changes, $project, $user, $properties): void {
            foreach ($properties as $property => $newValue) {
                $oldValue = $project->getPropertyValueForUpdate($property);

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
        ProjectUpdatePropertiesRecommendationDto $recommendation,
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

<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\RecommendationDisplayDto;
use App\Enums\ActivityLogAction;
use App\Models\User;
use App\Services\ActivityLogRecorder;
use Illuminate\Database\Eloquent\Model;

class RecordLlmRecommendationAppliedAction
{
    public function __construct(
        private ActivityLogRecorder $activityLogRecorder
    ) {}

    /**
     * Record a high-level audit log that a recommendation was accepted, modified, or rejected.
     *
     * @param  array<string, array{from:mixed,to:mixed}>  $changes
     */
    public function execute(
        Model $entity,
        User $user,
        RecommendationDisplayDto $displayDto,
        string $userAction,
        array $changes = []
    ): void {
        $this->activityLogRecorder->record(
            $entity,
            $user,
            ActivityLogAction::FieldUpdated,
            [
                'field' => 'llm_recommendation',
                'from' => null,
                'to' => [
                    'intent' => $displayDto->intent->value,
                    'entity_type' => $displayDto->entityType->value,
                    'user_action' => $userAction,
                    'reasoning' => $displayDto->reasoning,
                    'validation_confidence' => $displayDto->validationConfidence,
                    'used_fallback' => $displayDto->usedFallback,
                    'changes' => $changes,
                ],
            ]
        );
    }
}

<?php

namespace App\Actions\Project;

use App\DataTransferObjects\Project\UpdateProjectPropertyResult;
use App\Models\Project;
use App\Services\ProjectService;
use App\Support\DateHelper;
use Illuminate\Support\Facades\Log;

class UpdateProjectPropertyAction
{
    public function __construct(
        private ProjectService $projectService
    ) {}

    public function execute(Project $project, string $property, mixed $validatedValue): UpdateProjectPropertyResult
    {
        $column = Project::propertyToColumn($property);
        $oldValue = $project->getPropertyValueForUpdate($property);

        if ($property === 'endDatetime' && $validatedValue !== null && $project->start_datetime !== null) {
            $endDatetime = DateHelper::parseOptional($validatedValue);
            if ($endDatetime !== null && $endDatetime->lt($project->start_datetime)) {
                return UpdateProjectPropertyResult::failure(
                    $oldValue,
                    $validatedValue,
                    __('End date must be the same as or after the start date.')
                );
            }
        }

        $attributes = [$column => $validatedValue];
        if ($column === 'start_datetime' || $column === 'end_datetime') {
            $attributes[$column] = DateHelper::parseOptional($validatedValue);
        }

        try {
            $this->projectService->updateProject($project, $attributes);
        } catch (\Throwable $e) {
            Log::error('Failed to update project property from workspace.', [
                'project_id' => $project->id,
                'property' => $property,
                'exception' => $e,
            ]);

            return UpdateProjectPropertyResult::failure($oldValue, $validatedValue);
        }

        $newValue = in_array($property, ['startDatetime', 'endDatetime'], true) ? ($attributes[$column] ?? null) : $validatedValue;

        return UpdateProjectPropertyResult::success($oldValue, $newValue);
    }
}

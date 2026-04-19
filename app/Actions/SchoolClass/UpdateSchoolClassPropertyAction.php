<?php

namespace App\Actions\SchoolClass;

use App\DataTransferObjects\SchoolClass\UpdateSchoolClassPropertyResult;
use App\Enums\ActivityLogAction;
use App\Models\RecurringSchoolClass;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\ActivityLogRecorder;
use App\Services\SchoolClassService;
use App\Support\DateHelper;
use App\Support\Validation\SchoolClassPayloadValidation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class UpdateSchoolClassPropertyAction
{
    public function __construct(
        private ActivityLogRecorder $activityLogRecorder,
        private SchoolClassService $schoolClassService,
    ) {}

    public function execute(SchoolClass $schoolClass, string $property, mixed $validatedValue, ?User $actor = null): UpdateSchoolClassPropertyResult
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('update', $schoolClass);
        }

        if ($property === 'recurrence') {
            return $this->updateRecurrence($schoolClass, $validatedValue, $actor);
        }

        return $this->updateSimpleProperty($schoolClass, $property, $validatedValue, $actor);
    }

    /**
     * @param  array<string, mixed>  $validatedValue
     */
    private function updateRecurrence(SchoolClass $schoolClass, array $validatedValue, ?User $actor): UpdateSchoolClassPropertyResult
    {
        $schoolClass->loadMissing('recurringSchoolClass');
        $oldRecurrence = RecurringSchoolClass::toPayloadArray($schoolClass->recurringSchoolClass);

        try {
            $this->schoolClassService->updateOrCreateRecurringSchoolClass($schoolClass, $validatedValue);

            if ($actor !== null) {
                $this->activityLogRecorder->record(
                    $schoolClass,
                    $actor,
                    ActivityLogAction::FieldUpdated,
                    ['field' => 'recurrence', 'from' => $oldRecurrence, 'to' => $validatedValue]
                );
            }

            return UpdateSchoolClassPropertyResult::success($oldRecurrence, $validatedValue);
        } catch (\Throwable $e) {
            Log::error('Failed to update school class recurrence.', [
                'school_class_id' => $schoolClass->id,
                'exception' => $e,
            ]);

            return UpdateSchoolClassPropertyResult::failure($oldRecurrence, $validatedValue);
        }
    }

    private function updateSimpleProperty(SchoolClass $schoolClass, string $property, mixed $validatedValue, ?User $actor): UpdateSchoolClassPropertyResult
    {
        $column = SchoolClass::propertyToColumn($property);
        $oldValue = $schoolClass->getPropertyValueForUpdate($property);

        $attributes = [$column => $validatedValue];
        if ($column === 'start_datetime' || $column === 'end_datetime') {
            $parsedDatetime = DateHelper::parseRequired($validatedValue);
            $attributes[$column] = $parsedDatetime;

            $start = $column === 'start_datetime' ? $parsedDatetime : $schoolClass->start_datetime;
            $end = $column === 'end_datetime' ? $parsedDatetime : $schoolClass->end_datetime;

            $dateRangeError = SchoolClassPayloadValidation::validateSchoolClassDateRangeForUpdate($start, $end);
            if ($dateRangeError !== null) {
                return UpdateSchoolClassPropertyResult::failure($oldValue, $validatedValue, $dateRangeError);
            }
        }

        try {
            $this->schoolClassService->updateSchoolClass($schoolClass, $attributes);
        } catch (\Throwable $e) {
            Log::error('Failed to update school class property.', [
                'school_class_id' => $schoolClass->id,
                'property' => $property,
                'exception' => $e,
            ]);

            return UpdateSchoolClassPropertyResult::failure($oldValue, $validatedValue);
        }

        $newValue = in_array($property, ['startDatetime', 'endDatetime'], true) ? ($attributes[$column] ?? null) : $validatedValue;

        if ($actor !== null) {
            $this->activityLogRecorder->record(
                $schoolClass,
                $actor,
                ActivityLogAction::FieldUpdated,
                ['field' => $property, 'from' => $oldValue, 'to' => $newValue]
            );
        }

        return UpdateSchoolClassPropertyResult::success($oldValue, $newValue);
    }
}

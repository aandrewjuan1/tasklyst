<?php

namespace App\Actions\Workspace;

use App\Models\AssistantSchedulePlanItem;

final class AlignWorkspaceForScheduledPlanItemAction
{
    /**
     * Compute the workspace calendar date for a plan item and whether it differs from the current selection.
     *
     * @return array{date_changed: bool, new_date: string|null}
     */
    public function execute(AssistantSchedulePlanItem $planItem, ?string $currentSelectedDate): array
    {
        $start = $planItem->planned_start_at;
        if ($start === null) {
            return ['date_changed' => false, 'new_date' => null];
        }

        $timezone = (string) config('app.timezone', 'UTC');
        $newDate = $start->setTimezone($timezone)->toDateString();

        $normalizedCurrent = null;
        if ($currentSelectedDate !== null && $currentSelectedDate !== '' && strtotime($currentSelectedDate) !== false) {
            $normalizedCurrent = \Carbon\Carbon::parse($currentSelectedDate)->toDateString();
        }

        $dateChanged = $normalizedCurrent !== null && $normalizedCurrent !== $newDate;

        return [
            'date_changed' => $dateChanged,
            'new_date' => $newDate,
        ];
    }
}

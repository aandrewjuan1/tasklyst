<?php

namespace App\Support;

use Carbon\CarbonInterface;

final class DeadlineLabel
{
    /**
     * @return array{label:string,tone:'overdue'|'soon'|'normal'}|null
     */
    public static function from(?CarbonInterface $deadline, ?CarbonInterface $reference = null): ?array
    {
        if ($deadline === null) {
            return null;
        }

        $now = $reference ?? now();

        if ($deadline->isPast()) {
            $minutesLate = max(1, $deadline->diffInMinutes($now));
            if ($minutesLate < 60) {
                return [
                    'label' => __('Overdue by :count min', ['count' => $minutesLate]),
                    'tone' => 'overdue',
                ];
            }

            $hoursLate = (int) floor($minutesLate / 60);
            if ($hoursLate < 24) {
                return [
                    'label' => __('Overdue by :count h', ['count' => $hoursLate]),
                    'tone' => 'overdue',
                ];
            }

            $daysLate = (int) floor($hoursLate / 24);

            return [
                'label' => __('Overdue by :count d', ['count' => $daysLate]),
                'tone' => 'overdue',
            ];
        }

        if ($deadline->isToday()) {
            return [
                'label' => __('Due today'),
                'tone' => 'soon',
            ];
        }

        if ($deadline->isTomorrow()) {
            return [
                'label' => __('Due tomorrow'),
                'tone' => 'soon',
            ];
        }

        $daysUntil = $now->copy()->startOfDay()->diffInDays($deadline->copy()->startOfDay(), false);
        if ($daysUntil >= 0 && $daysUntil <= 7) {
            return [
                'label' => __('Due in :count d', ['count' => $daysUntil]),
                'tone' => 'soon',
            ];
        }

        return [
            'label' => __('Due :date', ['date' => $deadline->translatedFormat('M j')]),
            'tone' => 'normal',
        ];
    }
}

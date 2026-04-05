<?php

namespace App\Data\Analytics;

use Carbon\CarbonImmutable;

readonly class UserAnalyticsOverview
{
    /**
     * @param  array<string, int>  $tasksCompletedByDay  Keys `Y-m-d`
     * @param  array<string, int>  $focusWorkSecondsByDay  Keys `Y-m-d`
     * @param  array<string, int>  $tasksCompletedByProjectId  Keys `none` or numeric project id string
     */
    public function __construct(
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public int $tasksCompletedCount,
        public int $tasksCreatedCount,
        public int $focusWorkSecondsTotal,
        public int $focusWorkSessionsCount,
        public array $tasksCompletedByDay,
        public array $focusWorkSecondsByDay,
        public array $tasksCompletedByProjectId,
    ) {}
}
